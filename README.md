# Craft Delta

Inline revision diffing for Craft CMS 5. Compare any two revisions, drafts, or the current version of an entry and see exactly what changed — word-level for text, block-level for Matrix, and value-level for everything else.

## Requirements

- Craft CMS 5.8+
- PHP 8.2+

## Installation

```bash
composer require zeixcom/craft-delta
php craft plugin/install craft-delta
```

## Features

- **Word-level text diffing** for Plain Text and CKEditor fields
- **Matrix diffing** — added, removed, modified, and reordered blocks
- **Relational field diffing** — Entries, Assets, Categories, Tags, Users
- **Table field diffing** — row and cell-level changes
- **Option field diffing** — Dropdowns, Radios, Checkboxes, Multi-select
- **Scalar diffing** — Numbers, Dates, Lightswitches, Colors, Money, etc.
- **Draft comparison** — compare drafts against revisions or the current version
- **Multisite support** — diffs are site-aware
- **"Changed only" filter** — toggle unchanged fields on/off
- **Diff summary stats** — fields changed, additions/deletions
- **Translations** — English, German, French, Spanish, Italian, Dutch, Portuguese, Polish
- **Review Mode** — accept or reject individual changes from inside the diff slideout, then apply all accepted changes as a new draft on the canonical entry. Per-field and per-Matrix-block granularity. Resumable across browser restarts.

## Usage

Open any entry with at least one revision. A **"Compare Revisions"** button appears in the sidebar. Click it to open the diff slideout.

Use the two dropdowns to select which versions to compare — Current, any draft, or any revision. The diff loads automatically when you change the selection.

- Click the **swap** button to reverse the comparison direction
- Toggle **"Changed only"** to hide unchanged fields

### Review Mode

When comparing a draft or revision against the **current** entry, click **Start Review** in the slideout toolbar. Each changed field gains an **✓ Accept** / **✗ Reject** button pair; Matrix blocks get the same buttons per block. Use **J / K** to step between changes, **A / R** to decide. Click **Apply N accepted** to publish your accepted changes directly to the entry as a new revision — rejected changes are dropped.

When the comparison source is a draft, an **"Also delete source draft"** checkbox appears next to Apply. Tick it before clicking Apply to remove the source draft after publication. Leave it unchecked (default) to keep the source draft around — it then doubles as a queue of rejected changes you can revisit later (re-opening the diff will show only the changes you didn't accept, since the canonical now matches the source for everything that was accepted).

Decisions persist in browser localStorage until you Apply or Cancel. If the canonical entry is edited mid-review, you'll be prompted to start over.

### Permissions

Three per-section permissions registered under **Settings → Users → User Groups → Permissions** in the **Craft Delta** group:

- **Submit drafts for review** — authors holding this permission see a "Submit for review" button on their drafts.
- **Review submitted drafts** — reviewers holding this permission can be picked as an assignee and can Approve (wholesale, with optional scheduling) or Reject.
- **Apply review-mode changes** *(unchanged from v1.x)* — required additionally for the "Granular review" path inside the workflow, and for the legacy ad-hoc review mode.

Users with none of these still see the read-only diff. Admins have everything implicitly.

> **Note on draft locking:** submitted drafts are **not** locked. If the author keeps editing while a scheduled apply is pending, the queue job will publish whatever the draft contains at apply time.

### Workflow (v2.0+)

Authors with the **Submit drafts for review** permission see a **Submit for review** button on their drafts. Clicking it asks them to pick a reviewer.

The chosen reviewer receives an email with a link to the entry. From the diff slideout, the reviewer sees three buttons:

- **Approve all** — applies the draft to canonical. Has a dropdown for "Apply now" or "Schedule for…" (queues a job).
- **Granular review** — opens the v1.1 per-field accept/decline flow (requires the **Apply review-mode changes** permission additionally).
- **Reject** — terminal. Author keeps the draft and receives the reviewer's note by email.

Rejected drafts cannot be re-submitted. To revise, duplicate the draft and submit the copy.

Disable the entire workflow path via **Settings → Plugins → Craft Delta → Enable Workflow** (defaults to On).

## Settings

Configure under **Settings > Plugins > Craft Delta**:

| Setting | Default | Description |
|---------|---------|-------------|
| Diff Context Lines | 3 | Unchanged lines shown around changes |
| Max Field Length | 50,000 | Characters before showing a simplified diff |
| Show Unchanged Fields | Off | Show unchanged fields by default |
| Enable Review Mode | On | Show the "Start Review" button. When off, the plugin behaves as a pure read-only diff tool. |
| Enable Workflow | On | Show the workflow Submit/Approve/Reject UI. When off, v1.1 behavior. |

## Extending

Register custom differs for third-party field types:

```php
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

Custom differs must implement `zeixcom\craftdelta\differ\DifferInterface`.

## Roadmap

Extend the plugin with the most popular third-party field types like NEO, Hyper, etc.

## License

Proprietary — see [LICENSE.md](LICENSE.md)
