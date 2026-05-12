# Craft Delta v2.0 — Workflow Permissions Design

**Date:** 2026-05-12
**Status:** Design approved, pending implementation plan
**Target version:** craft-delta 2.0.0

## Goal

Introduce a submit-for-review workflow to Craft Delta. Authors submit drafts to a chosen reviewer; the reviewer approves (wholesale, with optional scheduling) or rejects (terminal). Users without workflow permissions retain the v1.1 read-only diff experience.

## Non-goals

- Reviewer dashboard / queue widget in the CP (deferred to v2.1+; reviewers reach drafts via email deep links).
- Locking drafts during review (intentional — latest content at apply time wins).
- Multi-reviewer assignment / quorum.
- "Changes requested" state or resubmit-after-rejection flow (Rejected is terminal).
- Notifications beyond email.

## Permissions

Three per-section permissions registered under the "Craft Delta" group on the user-group permissions screen.

| Handle | Label | Held by |
|---|---|---|
| `craftdelta-submitDraft:{sectionUid}` | Submit drafts for review | Authors |
| `craftdelta-reviewDraft:{sectionUid}` | Review submitted drafts | Reviewers |
| `craftdelta-applyReview:{sectionUid}` | Apply review-mode changes *(existing, unchanged)* | Reviewers |

Users with none of these see only the read-only diff. Admins implicitly hold all permissions.

## State machine

Four logical states. `Draft` is implicit — no workflow row exists. `Approved` carries an optional `scheduledFor` timestamp to represent scheduled-but-not-yet-applied.

```
       (Author saves draft)
              │
              ▼
        ┌──────────┐
        │  Draft   │  ← implicit; no DB row
        └────┬─────┘
             │ Submit (author, requires assignee)
             ▼
        ┌──────────┐
        │ Pending  │  ← reviewer notified by email
        └────┬─────┘
       ┌─────┴─────┐
       │           │
   Approve     Reject (optional note)
       │           │
       ▼           ▼
   ┌──────────┐ ┌──────────┐
   │ Approved │ │ Rejected │  ← terminal
   └──────────┘ └──────────┘
```

**Transitions:**

| From | To | Trigger | Side effects |
|---|---|---|---|
| Draft | Pending | Author submits with assignee | Email assignee |
| Pending | Approved | Reviewer approves (wholesale or granular) | If `scheduledFor` set, push queue job. If not, apply immediately. Email author. |
| Pending | Rejected | Reviewer rejects | Email author with note. |

Rejected and Approved are both terminal. The draft itself is preserved in both cases (Craft's drafts table is untouched).

> **TODO (user input, ~5 lines):** finalize the state transition table as a PHP array literal in `WorkflowService` — this is the single source of truth for what's allowed:
>
> ```php
> private const TRANSITIONS = [
>     'pending'  => ['approved', 'rejected'],
>     'approved' => [],
>     'rejected' => [],
> ];
> ```

## Data model

Single new plugin-owned table. No changes to Craft core tables.

### Table: `{{%craftdelta_draft_workflows}}`

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `draftId` | int, FK → `drafts.id`, ON DELETE CASCADE | Craft's drafts row |
| `canonicalEntryId` | int, FK → `entries.id` | Denormalized for queue filtering |
| `sectionUid` | char(36), indexed | Denormalized so permission checks don't join |
| `state` | enum(`pending`, `approved`, `rejected`) | `draft` is implicit (no row) |
| `submittedBy` | int, FK → `users.id` | Author who submitted |
| `assigneeId` | int, FK → `users.id`, ON DELETE SET NULL | Reviewer chosen by author |
| `decidedBy` | int, FK → `users.id`, nullable | Reviewer who approved or rejected |
| `rejectNote` | text, nullable | Optional, only set on Reject |
| `scheduledFor` | datetime, nullable | Set when reviewer schedules wholesale apply |
| `appliedAt` | datetime, nullable | Set when actually applied to canonical |
| `dateCreated`, `dateUpdated`, `uid` | Craft standard | |

**Indexes:**
- `(assigneeId, state)` — reviewer's queue lookup
- `(state, scheduledFor)` — queue job sweep

**Schema version:** bump `Delta::$schemaVersion` from `1.0.0` → `2.0.0`. Add install + upgrade migrations.

## UI surfaces

### Author viewing their own draft, holds `submitDraft`

New "Submit for review" button in the sidebar, below "Compare Revisions". Click opens a modal:
- Reviewer dropdown (users in this section who hold `reviewDraft:{section}`)
- Submit button

After submit, the button is replaced with a status pill: `⏳ Pending review — assigned to {Reviewer Name}`.

### Reviewer viewing a Pending draft assigned to them

The "Compare Revisions" slideout opens with a new top toolbar:

```
[Approve all ▾] [Granular review] [Reject]
```

- **Approve all ▾** — dropdown reveals "Apply now" or "Schedule for…" (datepicker).
- **Granular review** — opens v1.1's existing per-field accept/decline flow. Gated additionally on `applyReview`. Final Apply sets workflow state to `Approved` and applies the selected changes.
- **Reject** — opens a textarea (optional note) and confirms. Sets state to `Rejected`.

### Everyone else (read-only)

Diff slideout opens with the existing "Changed only" toggle and version selectors. No Start Review button, no workflow toolbar.

### Entry index column

A small "Workflow" column showing a colored pill for entries with active workflow rows: `Pending`, `Approved (scheduled)`, `Rejected`. Filterable via Craft's element index source mechanism.

> **TODO (user input, ~5–10 lines):** reviewer dropdown query in `WorkflowService::getEligibleAssignees()` — decide sorting (alphabetical? recent collaborators first?) and whether to exclude the current author.

## Controllers & services

### `WorkflowService` (new)

`src/services/WorkflowService.php`. Owns the state machine. Public API:

```php
submit(Entry $draft, int $assigneeId, User $submittedBy): DraftWorkflow
approveWholesale(DraftWorkflow $wf, ?DateTime $scheduledFor, User $reviewer): void
approveGranular(DraftWorkflow $wf, array $acceptedFieldHandles, User $reviewer): void
reject(DraftWorkflow $wf, ?string $note, User $reviewer): void
canSubmit(User $user, Entry $draft): bool
canReview(User $user, DraftWorkflow $wf): bool
getEligibleAssignees(Section $section): array
```

Every transition validates current state + permission + ownership in one place. Controllers stay thin.

### `WorkflowController` (new)

| Action | Route | Permission |
|---|---|---|
| `submit` | POST `craft-delta/workflow/submit` | `submitDraft:{section}` + owns draft |
| `approve` | POST `craft-delta/workflow/approve` | `reviewDraft:{section}` + is assignee |
| `reject` | POST `craft-delta/workflow/reject` | `reviewDraft:{section}` + is assignee |
| `assignees` | GET `craft-delta/workflow/assignees` | `submitDraft:{section}` |

### Queue job: `ApplyScheduledDraft`

`src/queue/jobs/ApplyScheduledDraft.php`. Pushed with delay when `approveWholesale` is called with a future `scheduledFor`.

**On execution:**
1. Re-fetch the workflow row.
2. If row missing or state ≠ `approved` or `scheduledFor` changed → no-op.
3. Apply the draft via `Craft::$app->getDrafts()->applyDraft()`.
4. Set `appliedAt`, clear `scheduledFor`.

Re-validation is critical: a scheduled apply could otherwise race a manual change. We accept that whatever the draft contains at apply time is what publishes (per design decision: drafts are not locked during review).

### Email notifications

`EmailService` with three templates registered in `templates/_emails/`:

- `submitted.twig` — to assignee on submit
- `approved.twig` — to author on approve (mentions scheduled time if any)
- `rejected.twig` — to author on reject (includes note)

All sent via `Craft::$app->getMailer()->compose()` with the entry's CP edit URL deep-linked into the body.

> **TODO (user input, ~10 lines per template):** email body copy. Tone, what to include, whether to embed the diff summary stats. Keep markdown-friendly so Craft's mailer renders cleanly.

## Migration from v1.1

**No breaking changes for read-only users.** The diff slideout, "Compare Revisions" button, and version selectors behave identically to v1.1 for users without any Craft Delta permissions.

**Existing `applyReview` permission keeps its meaning.** Still gates the granular Apply action. Sites that already granted it continue to work unchanged; those users become eligible reviewers once they also receive `reviewDraft`.

**Settings:** add `enableWorkflow` (bool, default `true`). When `false`, plugin behaves exactly like v1.1 — no Submit button, no workflow toolbar, queue job and email templates dormant. Kill switch for gradual rollout.

**New events** for third-party integration:
- `WorkflowService::EVENT_BEFORE_SUBMIT`
- `WorkflowService::EVENT_AFTER_SUBMIT`
- `WorkflowService::EVENT_AFTER_APPROVE`
- `WorkflowService::EVENT_AFTER_REJECT`

**Migration files:**
- `m260512_000000_workflow_table.php` — creates the table + indexes
- `Install.php` migration — same table for fresh installs

**README + CHANGELOG** — document the three permissions, state machine, settings toggle, and explicitly note: *"Submitted drafts are not locked. If the author edits while a scheduled apply is pending, the queue job will publish whatever the draft contains at apply time."*

## Open items requiring user input during implementation

These are deliberately left as TODOs in the code for the user to fill in — each is a meaningful 5–10 line decision:

1. **State transition table** (`WorkflowService::TRANSITIONS`) — final shape of the allowed-transitions array.
2. **Reviewer dropdown query** (`WorkflowService::getEligibleAssignees()`) — sorting, author exclusion, max results.
3. **Email body copy** (3 templates) — tone, content, whether to embed diff stats.
4. **Reject note storage shape** — plain text vs lightly structured (markdown? CKEditor blob?).

## Accepted trade-offs

- **No draft lock.** A reviewer's approval may publish content that differs from what they reviewed if the author keeps editing during a scheduled window. Documented in README.
- **Rejected is terminal.** Authors can't resubmit the same draft; they duplicate and submit afresh. Trades a bit of friction for a cleaner audit trail.
- **No reviewer dashboard in v2.0.** Reviewers depend on email deep links. Schema has the columns to add a widget in v2.1 without migration.
- **No "in review" / "claimed by" state.** Two reviewers stepping on each other is possible if both are assignees (which they can't be in our single-assignee model anyway), so the risk is admin-only.
