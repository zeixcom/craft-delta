# Craft Delta — Review Requests ("real review mode")

- **Date:** 2026-06-08
- **Status:** Phase 1 implemented (2026-06-08); Phases 2–3 pending
- **Author:** Design session (Fabian + Claude)
- **Scope:** Extend the v2.0.0 submit-for-review workflow into a GitHub-PR-style
  review-request flow: an iterate loop, anchored review comments, multiple
  reviewers, and a reviewer dashboard.

## Context

### What exists today (v2.0.0)

- One table, `craftdelta_draft_workflows`: one row per draft, a single
  `assigneeId`, a single `rejectNote`, states `pending | approved | rejected`.
- An author submits a draft to **one** reviewer, who **approves** (publish now
  or scheduled) or **rejects** with a note.
- `approve` and `reject` are both **terminal** — a rejected draft cannot be
  re-submitted; the author must duplicate the draft.
- Status surfaces as a sidebar pill and an entry-index column.
- A separate "review mode" renders the diff with per-atom accept/reject controls
  (stable `data-atom-id`s) and publishes accepted atoms via
  `MergeService::merge()` + `WorkflowService::resolveByReview()`.

### What "a real review" adds

The gap is everything that happens **after** the first look:

1. **Iterate loop** — a non-terminal "changes requested" the author can act on,
   distinct from a permanent "no".
2. **Review feedback** — comments tied to specific changes in the diff, not one
   free-text note.
3. **Multiple reviewers** — request review from several people.
4. **Reviewer dashboard** — "reviews requested of me" / "my submissions".

## Decisions

| Decision | Choice |
| --- | --- |
| State machine | **Approach A** — non-terminal `changes_requested` for the iterate loop, separate terminal `declined`; per-round reviewer verdicts; overall state **derived** |
| Approval threshold | **Any one approves**; an unresolved `changes_requested` from any reviewer **blocks** publish until that reviewer re-approves |
| Reviewer set | A **specific requested set** (matches today's assignee picking). An open "reviewer pool" is a later add — out of scope for v1. |
| Comment granularity | **Anchored per-field/atom** (field handle + block UID, marked **outdated** when the anchor leaves the live diff) **plus** a general request-level thread |

## Data model

The v2.0.0 workflow is unreleased, so there is **no production review data to
migrate** — we reshape the schema cleanly. `craftdelta_draft_workflows` is
replaced by three tables.

### `craftdelta_reviews` — the request

| Column | Notes |
| --- | --- |
| `id` | PK |
| `draftId` | unique, FK → drafts, ON DELETE CASCADE |
| `canonicalEntryId` | |
| `sectionUid` | |
| `state` | cached derivation: `open \| changes_requested \| approved \| declined \| cancelled \| published` |
| `round` | smallint, default 1; bumped on each re-request |
| `submittedBy` | userId |
| `scheduledFor` | publish path (unchanged) |
| `appliedAt` | publish path (unchanged) |
| `dateCreated`, `dateUpdated`, `uid` | |

### `craftdelta_review_reviewers` — requested reviewers + per-round verdict

| Column | Notes |
| --- | --- |
| `id` | PK |
| `reviewId` | FK → reviews, cascade |
| `userId` | |
| `round` | which round this verdict belongs to |
| `verdict` | `pending \| approved \| changes_requested`, default `pending` |
| `decidedAt` | nullable |
| `dateCreated`, `dateUpdated`, `uid` | |

Unique key `(reviewId, userId, round)`. Storing the verdict **per round** keeps
full history; the **current round's** rows drive the derived state.

### `craftdelta_review_comments` — feedback

| Column | Notes |
| --- | --- |
| `id` | PK |
| `reviewId` | FK → reviews, cascade |
| `round` | round the comment was made in |
| `authorId` | |
| `body` | text |
| `anchorType` | `general \| field \| atom` |
| `fieldHandle` | nullable (stable anchor) |
| `blockUid` | nullable (stable anchor) |
| `atomId` | nullable (snapshot of the atom-id at comment time) |
| `resolved` | bool, default false |
| `parentId` | self-FK, one level of replies |
| `dateCreated`, `dateUpdated`, `uid` | |

**"Outdated" is derived, not stored.** When the review renders against the
*current* draft, the server computes the live diff and its atom set
(`MergeService::collectAvailableAtoms`, reused). A comment is **current** if its
anchor still resolves to a live atom; otherwise it is **outdated** and collapses
into an "Outdated (N)" section.

> v1 simplification: a block modified *again* keeps the same
> `matrix-block:<handle>:<uid>:modified` atom-id, so its comment reads as
> "current" even though content shifted. Detecting that needs a content hash —
> deferred. The anchor check still catches removed/gone/relocated changes.

## State machine & derivation

`reviews.state` is a **cache of a derivation** over the **current round's**
reviewer verdicts, never set blindly. Precedence:

1. any current-round verdict is `changes_requested` → **`changes_requested`** (blocks publish)
2. else any current-round verdict is `approved` → **`approved`**
3. else → **`open`**

`declined`, `cancelled`, `published` are **explicit** action results, not derived.

```
 (no row)
    │ submit(draftId, userIds[])
    ▼
  open ◀──── re-request (round++) ──── changes_requested
   │ │                                       ▲
   │ └──── request changes ─────────────────-┘
   │ approve (threshold met)
   ▼
 approved ── publish (now/scheduled) ──▶ published
                                                    
 open / changes_requested ── withdraw ──▶ cancelled (terminal)
 open / changes_requested ── decline  ──▶ declined  (terminal)
```

| Actor | Action | From → To |
| --- | --- | --- |
| Author | `submit(draftId, userIds[])` | (none) → `open`, round 1, reviewer rows `pending` |
| Reviewer | `approve` / `requestChanges(comment)` | writes *their* current-round verdict → recompute |
| Reviewer/Admin | `decline` | `open`/`changes_requested` → `declined` |
| Author | `reRequest` | `changes_requested` → `open`, **round++**, reviewer set copied in as `pending` |
| Author/Admin | `withdraw` | `open`/`changes_requested` → `cancelled` |
| canSave user | `publish` (now/scheduled) | `approved` → `published` |

**Concurrency** reuses the pattern introduced in the v2.0.0 review fixes: a
verdict write touches a single `(reviewId, userId, round)` row (reviewers never
collide); the action then recomputes aggregate state and writes `reviews.state`
**inside one transaction**. Round transitions (`reRequest`, `withdraw`,
`decline`) use the atomic conditional update —
`updateAll([...], ['id' => $id, 'state' => $expectedFrom])` — so a stale snapshot
cannot drive an illegal transition. This is the `transition()` helper from the
fixes, generalized to the new states.

`reRequest` does not *force* that the draft changed, but warns (or optionally
blocks) when `draft.dateUpdated` has not moved since the round opened.

## Comments & the "outdated" logic

- The diff slideout already renders `data-atom-id` on every change. Commenting
  on one posts `{reviewId, atomId, body}`; the server **decomposes** the atom-id
  into a stable anchor and stores both the snapshot and the stable parts:
  - `field:title` → `anchorType=field`, `fieldHandle=title`, `blockUid=null`
  - `matrix-block:body:uid123:modified` → `anchorType=atom`, `fieldHandle=body`, `blockUid=uid123`
  - a request-level remark → `anchorType=general`, no anchor
- **Threads:** `parentId` self-FK, one level of replies (no deep nesting).
- **Resolution:** any comment can be marked `resolved` by author or reviewer;
  resolved threads collapse. Resolution is **UI hygiene only** — it does not
  change a verdict. A reviewer clears `changes_requested` solely by explicitly
  re-approving in a later round.

## Services, controllers & endpoints

### Services

- **`WorkflowService`** grows into the review authority:
  `submit(draft, userIds[])`, `approve`, `requestChanges`, `decline`,
  `reRequest`, `withdraw`, plus the existing publish path
  (`applyDraftNow` / scheduled). Every verdict action ends by calling a private
  `recomputeState($reviewId)` **inside the same transaction**.
- **`ReviewCommentService`** (new, small): `addComment`, `resolveComment`,
  `commentsForReview` (with the `outdated` flag computed against the live atom set).
- **`EmailService`** gains `changesRequested`, `reRequested`, `declined`
  (existing approve/reject retained). Already hardened by the dispatch try/catch fix.

### Records / models

`Review`, `ReviewReviewer`, `ReviewComment` (records + models) replace the single
`DraftWorkflow`. `Review` keeps `statusLabel()` / `isPending()` / `isScheduled()`,
extended for the new states. `statusLabel()` stays the single source of truth for
status display.

### Endpoints

```
POST review/submit          {draftId, reviewerIds[]}
POST review/approve         {reviewId}
POST review/request-changes {reviewId, body, atomId?}
POST review/decline         {reviewId, note?}
POST review/re-request      {reviewId}
POST review/withdraw        {reviewId}
POST review/publish         {reviewId, scheduledFor?}   # existing approve-publish
POST review/comment         {reviewId, body, atomId?, parentId?}
POST review/comment/resolve {commentId}
GET  review/thread          {reviewId}                  # comments for the slideout
GET  review/dashboard       {filter: mine|assigned}
```

**Permissions** reuse `Delta::PERMISSION_*`: `SUBMIT` gates
submit/reRequest/withdraw; `REVIEW` gates approve/requestChanges/decline/comment;
publish stays gated by native `canSave`.

**Integration with granular review mode:** the existing atom accept/reject +
`resolveByReview` apply path stays — it is "approve with curated edits", and on
apply it transitions the review to `published`. Request-review and granular-apply
become one coherent flow.

## UI

1. **Diff slideout (review surface):**
   - Reviewer toolbar: `Approve` / `Request changes` / `Decline` (the latter two
     open a comment box).
   - Per-atom comment affordance: comment icon + count badge on each
     `data-atom-id` row; inline thread (reply, resolve).
   - Outdated comments collapse into a greyed "Outdated (N)" disclosure.
   - Reviewer roster strip: each reviewer + verdict pill, plus round number.
   - Author view: `Re-request review` (enabled only from `changes_requested`),
     `Withdraw`, and a general-comment box.
2. **Reviews dashboard** — a CP nav item / route with two lists:
   - **Assigned to me** — requests awaiting my verdict.
   - **My submissions** — my drafts in review and their state.
   Rows link to the entry with the slideout auto-opened. Counts drive a nav badge.
3. **Entry-index column + sidebar pill** — extended to render the new states via
   `statusLabel()`.

**JS:** `workflow.js` grows the toolbar actions + comment posting/rendering; a
small `review-dashboard.js` for the CP screen. The slideout fetches
`review/thread` on open and hydrates comment threads onto atoms by
`data-atom-id` lookup. New UI strings are added to the `registerTranslations`
block in `Delta.php` (and the 8 locale files).

## Notifications

`EmailService` gains `changesRequested` → author, `reRequested` → reviewers,
`declined` → author; `submitted` now fans out to each requested reviewer.
**Comment-only notifications are deferred** (verdict emails carry the signal;
per-comment email risks spam) — optional Phase 3 with batching.

## Migration

- If v2.0.0 is still unreleased: **reshape in place** — one migration creates the
  three tables and drops `craftdelta_draft_workflows`; `Install.php` updated for
  fresh installs. No data backfill.
- If 2.0.0 has shipped: ship **2.1.0** with an upgrade migration that backfills
  each existing single-assignee row into a one-reviewer review.
- Bump `schemaVersion` either way.

## Testing

- **Unit:** the state-derivation precedence rule (pure function — test
  exhaustively), transition guards, atom-id → anchor decomposition, outdated
  computation.
- **Integration (Craft kernel):** multi-reviewer submit, request-changes →
  re-request round increment, approve threshold, decline terminal, publish,
  comment add/resolve, FK cascade on draft delete.
- **Smoke (`craft-smoke-test`):** the full loop — submit → comment + request
  changes → revise + re-request → approve → publish — plus dashboard counts.

## Phased rollout

Each phase is independently shippable.

1. **Phase 1 — core review loop:** state machine + iterate loop
   (`changes_requested` / `re-request` / `decline` / `withdraw`) + multiple
   reviewers + derived state. Reuse the single note for now. Delivers the core
   "real review".
2. **Phase 2 — feedback:** anchored comments + threads + outdated logic.
3. **Phase 3 — discoverability:** dashboard + nav badge + notification polish.

## Deferred / out of scope (v1)

- Open reviewer **pool** (anyone in a group can pick up a request).
- **Content-hash** outdated detection for re-modified blocks.
- **Per-comment** email notifications.
- Configurable **N-of-M** approval thresholds (v1 is "any one approves").

## Rough size

~3 new tables, 3 records + 3 models, ~2 service files (1 extended), ~10
endpoints, 2 JS modules, 1 new CP screen, plus migration and tests. Phase 1 is
the bulk of the state-machine work.
