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

- Authors submit a draft and assign a reviewer; reviewers approve (now or scheduled), reject with a note, or apply changes granularly
- Email notifications on submit / approve / reject
- A **Workflow** status column on entry index pages
- Section-agnostic permissions that compose with Craft's native section access
- `EVENT_AFTER_SUBMIT` / `EVENT_AFTER_APPROVE` / `EVENT_AFTER_REJECT` events for third-party integration

**Platform**

- Translations: English, German, French, Spanish, Italian, Dutch, Portuguese, Polish
- Pluggable differ architecture for custom field types

## Usage

### Comparing revisions

Open any entry that has at least one revision (or a published draft). A **Compare Revisions** button appears in the editor sidebar — click it to open the diff slideout.

- Pick the two versions with the dropdowns — Current, any draft, or any revision. The diff loads automatically when the selection changes.
- Reverse direction with the **swap** button; hide unchanged fields with **Changed only**.
- **Open full page** shows the same diff as a standalone page. That is a plugin route, so a non-admin needs permission to access the plugin (Craft's *Access Craft Delta* permission) to open it — the in-sidebar slideout needs only section view access.

### Review Mode

Available when comparing a draft or revision against the **Current** entry, with the *Enable Review Mode* setting on. Requires the **Apply review-mode changes** permission.

Click **Start Review**. Each changed field gains **✓ Accept** / **✗ Reject** buttons; Matrix blocks get them per block. Use **J / K** to step between changes and **A / R** to decide, then click **Apply N accepted** to publish the accepted changes to the entry as a new revision — rejected changes are dropped.

When the source is a draft, an **Also delete source draft** checkbox appears next to Apply:

- **Unchecked (default):** the source draft is kept. Because canonical now matches it for everything you accepted, re-opening the diff shows only what you *didn't* accept — the draft becomes a queue of leftover changes.
- **Checked:** the source draft is deleted after a successful publish.

Decisions live in browser `localStorage` until you Apply or Cancel. If the canonical entry changes mid-review, you're prompted to start over.

### Submit-for-review workflow

Toggle the whole workflow with **Settings → Plugins → Craft Delta → Enable Workflow** (on by default). With it off, the plugin behaves like v1.1 — diff and Review Mode only.

**Submitting (author).** An author with **Submit drafts for review** sees a **Submit for review** button on a published draft. Clicking it asks them to choose a reviewer (only eligible reviewers are listed — see [Permissions & access](#permissions--access)). The assigned reviewer gets an email linking to the entry, and the draft shows a **Pending review** status.

**Reviewing.** Opening the submitted draft's diff, the assigned reviewer gets a toolbar:

- **Approve all** — publishes the whole draft to canonical immediately.
- **Schedule for…** — publishes the whole draft later, via a queued job at the date/time you enter.
- **Granular review** — enters Review Mode so you can accept/reject individual changes, then apply only the accepted ones (requires **Apply review-mode changes**).
- **Reject** — terminal; the author keeps the draft and receives your optional note by email.

A submitted draft is **not** locked. If the author keeps editing, a scheduled apply publishes whatever the draft contains at apply time.

Rejected drafts can't be re-submitted; to revise, duplicate the draft and submit the copy.

#### What a granular (partial) apply does to the workflow

Applying accepted changes through Review Mode **is** the review decision, so the workflow is **closed as Approved** — recorded with the reviewer and a timestamp. This holds whether you accepted every change or only some: a partial apply **finalizes** the workflow rather than leaving it open for a second pass.

The rejected changes aren't lost. The source draft is left untouched and becomes a record of what was declined — re-opening the diff afterward shows only the changes you didn't accept (canonical now matches the draft for everything that was). Tick **Also delete source draft** before applying to discard those leftovers instead; deleting the draft also removes the workflow record, which is keyed to the draft.

> **Design note — why a partial apply closes the workflow.** A reviewer who applies has made their call, so the workflow mirrors **Approve all** and closes. The plugin does **not** support iterative review (apply some now, keep the workflow open, apply more later) — for that, reject the draft and have the author resubmit a revised copy. This keeps the state machine to a single, terminal decision per submission. Implemented in `WorkflowService::resolveByReview()`, called from `DiffController::actionApply()`.

### Permissions & access

Craft Delta keeps the **workflow role** (three plugin permissions) separate from **entry access** (Craft's native section permissions). The plugin permissions are section-agnostic — grant section access through each user's normal groups.

Plugin permissions live under **Settings → Users → User Groups → Permissions → Craft Delta**:

| Permission | Key | Grants |
|---|---|---|
| Submit drafts for review | `craftdelta-submitDraft` | The **Submit for review** button on the holder's own drafts. |
| Review submitted drafts | `craftdelta-reviewDraft` | Being assignable as a reviewer, plus the Approve / Reject actions. |
| Apply review-mode changes | `craftdelta-applyReview` | Entering Review Mode and publishing accepted changes — both standalone Review Mode and the workflow's **Granular review**. |

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
| Enable Workflow | On | Show the Submit / Approve / Reject UI. Off = v1.1 behavior. |

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

`WorkflowService` fires events carrying the affected `DraftWorkflow` as `$event->workflow` — hook them for custom notifications, audit logging, or syncing to external systems:

| Constant | Fires after |
|---|---|
| `WorkflowService::EVENT_AFTER_SUBMIT` | a draft is submitted for review |
| `WorkflowService::EVENT_AFTER_APPROVE` | a draft is approved — wholesale *or* via a granular Review Mode apply |
| `WorkflowService::EVENT_AFTER_REJECT` | a draft is rejected |

```php
use yii\base\Event;
use zeixcom\craftdelta\services\WorkflowService;
use zeixcom\craftdelta\events\WorkflowEvent;

Event::on(
    WorkflowService::class,
    WorkflowService::EVENT_AFTER_APPROVE,
    function (WorkflowEvent $event) {
        $workflow = $event->workflow; // DraftWorkflow
        // …
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
