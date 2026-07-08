<?php

declare(strict_types=1);

/**
 * Integration smoke test for the review-feedback diff fixes, run through the
 * real FieldDiffService inside a booted Craft kernel (differ resolution +
 * render), not just the differ classes in isolation:
 *
 *   #4  Link add/remove/retarget inside CKEditor content must surface in the
 *       diff even when the visible anchor text is unchanged.
 *   #9  A lightswitch that is absent (null) in one revision and explicitly off
 *       (false) in the other is the same state and must NOT read as a change.
 *
 * Read-only: constructs field models and diffs values; mutates no content.
 *
 * Invoke via: `ddev craft craft-delta/smoke/diff-fixes`
 */

use craft\ckeditor\Field as CkeditorField;
use craft\fields\Lightswitch;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\helpers\DiffHtml;
use zeixcom\craftdelta\models\FieldDiff;

require __DIR__ . '/_guard.php';

$fieldDiff = Delta::getInstance()?->fieldDiff;
if ($fieldDiff === null) {
    fwrite(STDERR, "FAIL: FieldDiffService unavailable\n");
    exit(1);
}

$body = new CkeditorField(['handle' => 'body', 'name' => 'Body']);
$toggle = new Lightswitch(['handle' => 'showCaption', 'name' => 'Show caption']);

$pass = 0;
$fail = 0;

// require()d inside a controller method, so top-level vars are method-scoped:
// capture by reference (a `global` wouldn't reach them, and exit(1) would no-op).
$check = function(string $label, callable $run, callable $assert) use (&$pass, &$fail): void {
    $result = $run();
    [$ok, $detail] = $assert($result);
    if ($ok) {
        $pass++;
        echo "  PASS  $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label — $detail\n";
    }
};

$has = static fn(?FieldDiff $d): bool => $d !== null && $d->hasChanges;
$contains = static fn(?FieldDiff $d, string $needle): bool => $d !== null && str_contains((string)$d->diffHtml, $needle);

echo "#4 Link changes in CKEditor content\n";

$check(
    'removed link (text unchanged) surfaces, and shows the lost href',
    fn() => $fieldDiff->diff($body, '<p>Visit our <a href="/foo">site</a> today.</p>', '<p>Visit our site today.</p>'),
    fn(?FieldDiff $d) => [$has($d) && $contains($d, '/foo'), 'expected a change mentioning /foo'],
);

$check(
    'retargeted link (text unchanged) surfaces both targets',
    fn() => $fieldDiff->diff($body, '<p>Read the <a href="/alpha">report</a>.</p>', '<p>Read the <a href="/omega">report</a>.</p>'),
    fn(?FieldDiff $d) => [$has($d) && $contains($d, 'alpha') && $contains($d, 'omega'), 'expected both /alpha and /omega'],
);

$check(
    'identical content (incl. link) is not a change',
    fn() => $fieldDiff->diff($body, '<p>Visit our <a href="/foo">site</a> today.</p>', '<p>Visit our <a href="/foo">site</a> today.</p>'),
    fn(?FieldDiff $d) => [$d === null, 'expected null (no change)'],
);

echo "#9 Lightswitch absent vs. off\n";

$check(
    'absent (null) -> off (false) is NOT a change',
    fn() => $fieldDiff->diff($toggle, null, false),
    fn(?FieldDiff $d) => [$d === null, 'expected null (no change)'],
);

$check(
    'off (false) -> on (true) IS a change',
    fn() => $fieldDiff->diff($toggle, false, true),
    fn(?FieldDiff $d) => [$has($d), 'expected a change'],
);

echo "#5 Whole added line keeps its line-level highlight through the purifier\n";

// a wholly-appended line has no inner <ins>; its green needs the tbody change class to survive purify
$appendDiff = $fieldDiff->diff($body, '<p>First stays.</p>', '<p>First stays.</p><p>Brand new line.</p>');

$check(
    'raw diff carries a change-ins tbody for the added line',
    fn() => $appendDiff,
    fn(?FieldDiff $d) => [$has($d) && $contains($d, 'change-ins'), 'expected change-ins in raw diff'],
);

$check(
    'purifier preserves the tbody change class (green survives)',
    fn() => $appendDiff,
    fn(?FieldDiff $d) => [
        $d !== null && str_contains(DiffHtml::purifyDiffHtml((string)$d->diffHtml), 'change-ins'),
        'purifier stripped change-ins from <tbody>',
    ],
);

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    exit(1);
}
