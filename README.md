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

## Usage

Open any entry with at least one revision. A **"Compare Revisions"** button appears in the sidebar. Click it to open the diff slideout.

Use the two dropdowns to select which versions to compare — Current, any draft, or any revision. The diff loads automatically when you change the selection.

- Click the **swap** button to reverse the comparison direction
- Toggle **"Changed only"** to hide unchanged fields

## Settings

Configure under **Settings > Plugins > Craft Delta**:

| Setting | Default | Description |
|---------|---------|-------------|
| Diff Context Lines | 3 | Unchanged lines shown around changes |
| Max Field Length | 50,000 | Characters before showing a simplified diff |
| Show Unchanged Fields | Off | Show unchanged fields by default |

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
