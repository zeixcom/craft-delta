# Craft Delta

Inline revision diffing and a submit-for-review workflow for Craft CMS 5. Compare any two revisions, drafts, or the current version of an entry and see exactly what changed — word-level for text, block-level for Matrix, value-level for everything else — then accept changes selectively or route drafts through review before they publish.

## Requirements

- Craft CMS 5.8+
- PHP 8.2+
- *(Optional)* [CKEditor](https://plugins.craftcms.com/ckeditor) — enables rich-text diffing for CKEditor fields. Without it, Plain Text fields are still word-diffed.

## Installation

```bash
composer require zeixcom/craft-delta
php craft plugin/install craft-delta
```

## Features

**Diffing**

- Word-level text diffing for Plain Text and CKEditor fields
- Matrix diffing — added, removed, modified, and reordered blocks
- Relational diffing — Entries, Assets, Categories, Tags, Users (asset diffs show thumbnails, filenames, and metadata)
- Table, Option (Dropdown / Radio / Checkboxes / Multi-select / Button Group), and scalar diffing (Number, Date, Lightswitch, Color, Money, Country, Time, Link, Icon, Range, JSON, …)
- Site-aware diffs, a **Changed only** filter, and summary stats (fields changed, additions/deletions)

**Review Mode**

- Accept or reject each change individually — per field and per Matrix block, with reordering as its own decision — then publish only the accepted changes as a new revision
- Keyboard-driven stepper (`J`/`K` to move, `A`/`R` to decide); decisions persist in `localStorage` and resume across browser restarts; stale-edit detection if canonical changes mid-review

**Submit-for-review workflow** (v2.0+)

- Authors submit a draft to one or more reviewers; reviewers approve, request changes with a note, or decline. Authors revise and re-request (a new review round) or withdraw.
- Approved reviews publish wholesale — immediately or scheduled via a queued job — or granularly through Review Mode
- A **Reviews** dashboard in the CP nav (assigned to me / my submissions / all for admins) and a **Workflow** status column on entry index pages
- Email notifications on every transition, sent in the recipient's preferred language
- Section-agnostic permissions that compose with Craft's native section access
- `EVENT_AFTER_SUBMIT`, `EVENT_AFTER_APPROVE`, `EVENT_AFTER_CHANGES_REQUESTED`, `EVENT_AFTER_DECLINE`, `EVENT_AFTER_REREQUEST`, `EVENT_AFTER_WITHDRAW`, and `EVENT_AFTER_PUBLISH` events for third-party integration

**Platform**

- Translations: English, German, French, Spanish, Italian, Dutch, Portuguese, Polish
- Pluggable differ architecture for custom field types

## Usage

### Comparing revisions

Open any entry that has at least one revision (or a published draft). A **Compare Revisions** button appears in the editor sidebar — click it to open the diff slideout.

- Pick the two versions with the dropdowns — Current, any draft, or any revision. The diff loads automatically when the selection changes.
- Reverse direction with the **swap** button; hide unchanged fields with **Changed only**.
- **Open full page** shows the same diff as a standalone page. Like the slideout, it only needs view access to the entry's section.

### Review Mode

Available when comparing a draft or revision against the **Current** entry, with the *Enable Review Mode* setting on. Requires the **Apply review-mode changes** permission.

Click **Start Review**. Each changed field gains **✓ Accept** / **✗ Reject** buttons; Matrix blocks get them per block. Use **J / K** to step between changes and **A / R** to decide, then click **Apply N accepted** to publish the accepted changes to the entry as a new revision — rejected changes are dropped.

When the source is a draft, an **Also delete source draft** checkbox appears next to Apply:

- **Unchecked (default):** the source draft is kept. Because canonical now matches it for everything you accepted, re-opening the diff shows only what you *didn't* accept — the draft becomes a queue of leftover changes.
- **Checked:** the source draft is deleted after a successful publish.

Decisions live in browser `localStorage` until you Apply or Cancel. If the canonical entry changes mid-review, you're prompted to start over.

### Submit-for-review workflow

Toggle the whole workflow with **Settings → Plugins → Craft Delta → Enable Workflow** (on by default). With it off, the plugin behaves like v1.1 — diff and Review Mode only.

**Submitting (author).** An author with **Submit drafts for review** sees a **Submit for review** button on a published draft. Clicking it asks them to choose one or more reviewers (only eligible reviewers are listed — see [Permissions & access](#permissions--access)). Each requested reviewer gets an email linking to the review, and the draft shows an **In review** status.

**Reviewing.** Reviews happen on a dedicated full-page workspace at `/admin/delta-review?reviewId=N` — reachable from the **Reviews** dashboard, the notification emails, or the **Open review** link in the diff slideout. (The slideout and the `delta-compare` full page are diff-only; the review apparatus lives on this page.) The page shows the reviewers' verdicts and current round, the diff with inline comments, the general discussion, plus:

- **Approve** — records an approval verdict. One approval (with no outstanding change requests) moves the review to **Approved**; a single **Request changes** from any reviewer blocks it.
- **Request changes** — sends the author a note and moves the review to **Changes requested**.
- **Accept / reject per change** — for reviewers with **Apply review-mode changes**, accept/reject controls and a live "decided" counter are shown on each change; **Apply N accepted** publishes only the accepted ones.
- **Decline** — terminal; the author keeps the draft and receives your optional note by email.

Comments are anchored inline under the change they're about (or posted to the general discussion), with one level of replies and resolve/unresolve.

**Iterating (author).** When changes are requested, the author revises the draft and clicks **Re-request review** — the same reviewers are asked again in a new **round**. The author can also **Withdraw** the request at any time while it's active.

**Publishing.** Once approved, **Publish** (now) and **Schedule for…** (later, via a queued job) appear for the reviewer and the author. Publishing additionally requires Craft's native save permission on the entry, so a review-only role can't push content live. Scheduling is rescinded automatically if a reviewer subsequently requests changes or the review is declined/withdrawn.

A submitted draft is **not** locked. If the author keeps editing, a scheduled apply publishes whatever the draft contains at apply time.

A **withdrawn** request can simply be resubmitted — the review re-opens with a new round. **Declined** is terminal for that draft; to start over after a decline, duplicate the draft and submit the copy. Deleting a draft cancels its active review.

The **Reviews** item in the CP nav opens a dashboard listing reviews awaiting your verdict, your own submissions, and (for admins) all reviews.

#### What a granular (partial) apply does to the workflow

Applying accepted changes through Review Mode **is** the review decision, so the review is **closed as Published** — recorded with the reviewer and a timestamp. This holds whether you accepted every change or only some: a partial apply **finalizes** the review rather than leaving it open for a second pass.

The rejected changes aren't lost. The source draft is left untouched and becomes a record of what was declined — re-opening the diff afterward shows only the changes you didn't accept (canonical now matches the draft for everything that was). Tick **Also delete source draft** before applying to discard those leftovers instead; the closed review is kept as an audit record either way.

> **Design note — why a partial apply closes the review.** A reviewer who applies has made their call, so the review concludes the same way a wholesale publish does. The plugin does **not** support iterative apply (apply some now, keep the review open, apply more later) — for iteration, use **Request changes** and let the author re-request a new round. Implemented in `WorkflowService::resolveByReview()`, called from `DiffController::actionApply()`.

### Permissions & access

Craft Delta keeps the **workflow role** (three plugin permissions) separate from **entry access** (Craft's native section permissions). The plugin permissions are section-agnostic — grant section access through each user's normal groups.

Plugin permissions live under **Settings → Users → User Groups → Permissions → Craft Delta**:

| Permission | Key | Grants |
|---|---|---|
| Submit drafts for review | `craftdelta-submitDraft` | The **Submit for review** button on the holder's own drafts. |
| Review submitted drafts | `craftdelta-reviewDraft` | Being assignable as a reviewer, plus the Approve / Request changes / Decline verdicts. |
| Apply review-mode changes | `craftdelta-applyReview` | Entering Review Mode and publishing accepted changes — both standalone Review Mode and the per-change accept/reject on the review page. |

Section access is **not** handled by the plugin. Give each role the native Craft section permissions for the sections they work in, for example:

- **Authors** — View / Create / Save entries.
- **Reviewers** — View entries, **View other authors' drafts** (`viewPeerEntryDrafts`) so they can open an assigned draft, and Save entries (including other authors') to publish them.

The reviewer dropdown only lists users who hold **Review submitted drafts** *and* can view peer drafts in the draft's section, so an assignee can always open what they're given.

Users with none of the plugin permissions still see the read-only diff. Admins have everything implicitly.

### Settings

**Settings → Plugins → Craft Delta:**

| Setting | Default | Description |
|---|---|---|
| Diff Context Lines | 3 | Unchanged lines shown around a change (0–20). |
| Max Field Length | 50,000 | Character count above which a field falls back to a simplified diff (min 1,000). |
| Show Unchanged Fields | Off | Whether unchanged fields are shown by default. |
| Enable Review Mode | On | Show **Start Review** and the accept/reject/apply controls. Off = read-only diff. |
| Enable Workflow | On | Show the submit-for-review workflow (and its endpoints). Off = v1.1 behavior. |

## Extending

### Custom field differs

Register a differ for a third-party field type:

```php
use yii\base\Event;
use zeixcom\craftdelta\services\FieldDiffService;
use zeixcom\craftdelta\events\RegisterDiffersEvent;

Event::on(
    FieldDiffService::class,
    FieldDiffService::EVENT_REGISTER_DIFFERS,
    function (RegisterDiffersEvent $event) {
        $event->differs[\myvendor\fields\MyField::class] = MyFieldDiffer::class;
    }
);
```

A differ implements `zeixcom\craftdelta\differ\DifferInterface`:

- `diff(mixed $old, mixed $new): ?string` — the HTML diff, or `null` when unchanged
- `getStats(mixed $old, mixed $new): array{additions: int, deletions: int}`

Field types without a registered differ fall back to the scalar differ.

### Workflow events

`WorkflowService` fires events carrying the affected `Review` model as `$event->review` — hook them for custom notifications, audit logging, or syncing to external systems:

| Constant | Fires after |
|---|---|
| `WorkflowService::EVENT_AFTER_SUBMIT` | a draft is submitted for review |
| `WorkflowService::EVENT_AFTER_APPROVE` | a reviewer records an approval verdict |
| `WorkflowService::EVENT_AFTER_CHANGES_REQUESTED` | a reviewer requests changes |
| `WorkflowService::EVENT_AFTER_DECLINE` | a reviewer declines (terminal) |
| `WorkflowService::EVENT_AFTER_REREQUEST` | the author re-requests review (new round) |
| `WorkflowService::EVENT_AFTER_WITHDRAW` | the author withdraws (terminal) |
| `WorkflowService::EVENT_AFTER_PUBLISH` | the draft is published — immediately, scheduled, *or* via a granular Review Mode apply |

```php
use yii\base\Event;
use zeixcom\craftdelta\services\WorkflowService;
use zeixcom\craftdelta\events\WorkflowEvent;

Event::on(
    WorkflowService::class,
    WorkflowService::EVENT_AFTER_PUBLISH,
    function (WorkflowEvent $event) {
        $review = $event->review; // zeixcom\craftdelta\models\Review
        // $review->state, $review->round, $review->canonicalEntryId, …
    }
);
```

## Development

- Unit tests: `composer test` (PHPUnit). Static analysis: `composer phpstan`. Code style: `composer check-cs` / `composer fix-cs`.
- End-to-end smoke commands (against a configured dev environment):
  - `php craft craft-delta/smoke/matrix-apply` — Matrix add/apply round-trip
  - `php craft craft-delta/smoke/matrix-add-remove` — Matrix add + remove round-trip
  - `php craft craft-delta/smoke/setup-workflow-users` — provision `delta.author` / `delta.reviewer` fixtures for a manual workflow walkthrough

## Roadmap

Extend coverage to popular third-party field types (Neo, Hyper, …).

## License

Proprietary — see [LICENSE.md](LICENSE.md).
