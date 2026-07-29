<?php
/**
 * Nested-entry title smoke test (#15)
 *
 * Reproduces "no diffing of titles in nested entries": a Matrix block whose
 * entry type has "Show the Title field" enabled, where ONLY the block title
 * changed between two versions. Verifies the change is detected, exposed as a
 * `matrix-field:<field>:<blockUid>:title` atom, and applied by MergeService.
 *
 * Usage from the Craft web root:
 *
 *     ddev craft craft-delta/smoke/nested-title
 *
 * Override the owner entry with CRAFT_DELTA_SMOKE_ENTRY_ID.
 */

declare(strict_types=1);

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Matrix;

require __DIR__ . '/_guard.php';

$entryId = (int)(getenv('CRAFT_DELTA_SMOKE_ENTRY_ID') ?: 2);

echo "── Nested-entry title smoke test (#15) ──\n";

$adminUser = User::find()->admin(true)->status(null)->one();
if (!$adminUser instanceof User) {
    fwrite(STDERR, "FAIL: no admin user found\n");
    exit(1);
}
Craft::$app->getUser()->setIdentity($adminUser);

$canonical = Entry::find()->id($entryId)->status(null)->one();
if (!$canonical instanceof Entry) {
    fwrite(STDERR, "FAIL: canonical entry {$entryId} not found\n");
    exit(2);
}
echo "Canonical: \"{$canonical->title}\" (id={$canonical->id})\n";

// Pick a Matrix field that actually holds a title-bearing block.
$matrixFieldHandle = null;
$targetBlock = null;
foreach ($canonical->getFieldLayout()->getCustomFields() as $field) {
    if (!$field instanceof Matrix) {
        continue;
    }
    foreach ($canonical->getFieldValue($field->handle)->all() as $block) {
        if ($block->type->hasTitleField) {
            $matrixFieldHandle = $field->handle;
            $targetBlock = $block;
            break 2;
        }
    }
}
if ($matrixFieldHandle === null || $targetBlock === null) {
    fwrite(STDERR, "FAIL: entry {$entryId} has no Matrix block whose entry type shows the Title field\n");
    exit(2);
}
echo "Matrix field: {$matrixFieldHandle}\n";
echo "Target block: canonicalUid={$targetBlock->canonicalUid}, type={$targetBlock->type->handle}, title=\"{$targetBlock->title}\"\n\n";

echo "Step 1: draft that changes ONLY the block title\n";

$draft = Craft::$app->getDrafts()->createDraft($canonical, $adminUser->id, 'Nested title smoke test');
if (!$draft instanceof Entry) {
    fwrite(STDERR, "FAIL: createDraft returned " . get_debug_type($draft) . "\n");
    exit(3);
}

$newTitle = 'Retitled ' . date('His');
$payload = [];
$sortOrder = [];
foreach ($draft->getFieldValue($matrixFieldHandle)->all() as $block) {
    $sortOrder[] = $block->uid;
    $payload['uid:' . $block->uid] = [
        'type' => $block->type->handle,
        'title' => $block->canonicalUid === $targetBlock->canonicalUid ? $newTitle : $block->title,
        'fields' => $block->getSerializedFieldValues(),
    ];
}
$draft->setFieldValue($matrixFieldHandle, ['entries' => $payload, 'sortOrder' => $sortOrder]);

if (!Craft::$app->getElements()->saveElement($draft)) {
    fwrite(STDERR, "FAIL: saveElement on draft returned false: " . json_encode($draft->getErrors()) . "\n");
    exit(3);
}

$draft = Entry::find()->draftId($draft->draftId)->status(null)->one();
$draftTitles = [];
foreach ($draft->getFieldValue($matrixFieldHandle)->all() as $block) {
    $draftTitles[$block->canonicalUid] = $block->title;
}
if (($draftTitles[$targetBlock->canonicalUid] ?? null) !== $newTitle) {
    fwrite(STDERR, "FAIL: draft block title is \"" . ($draftTitles[$targetBlock->canonicalUid] ?? 'NULL') . "\", expected \"{$newTitle}\"\n");
    exit(3);
}
echo "  ✓ Draft block retitled to \"{$newTitle}\" (all other values untouched)\n\n";

echo "Step 2: diffing canonical vs draft\n";

$plugin = \zeixcom\craftdelta\Delta::getInstance();
$diff = $plugin->diff->compare($canonical, $draft);

$matrixDiff = null;
foreach ($diff->fieldDiffs as $fd) {
    if ($fd->fieldHandle === $matrixFieldHandle) {
        $matrixDiff = $fd;
    }
}
if ($matrixDiff === null || !$matrixDiff->hasChanges) {
    fwrite(STDERR, "FAIL (#15 reproduced): the Matrix field reports no changes although the block title changed\n");
    exit(4);
}

$changes = json_decode((string)$matrixDiff->diffHtml, true);
$titleChange = null;
foreach (is_array($changes) ? $changes : [] as $change) {
    if (($change['blockUid'] ?? null) !== $targetBlock->canonicalUid) {
        continue;
    }
    echo "  Block change type: {$change['type']}\n";
    foreach ($change['fieldChanges'] ?? [] as $fc) {
        echo "    fieldChange: {$fc['handle']} ({$fc['label']})\n";
        if ($fc['handle'] === 'title') {
            $titleChange = $fc;
        }
    }
}
if ($titleChange === null) {
    fwrite(STDERR, "FAIL (#15 reproduced): no 'title' fieldChange emitted for the retitled block\n");
    exit(4);
}
echo "  ✓ Title change detected: " . strip_tags((string)$titleChange['diffHtml']) . "\n\n";

echo "Step 3: atom availability\n";

$available = \zeixcom\craftdelta\services\MergeService::collectAvailableAtoms($diff);
$expectedAtom = "matrix-field:{$matrixFieldHandle}:{$targetBlock->canonicalUid}:title";
if (!in_array($expectedAtom, $available, true)) {
    fwrite(STDERR, "FAIL: expected atom \"{$expectedAtom}\" not in: " . implode(', ', $available) . "\n");
    exit(5);
}
echo "  ✓ {$expectedAtom}\n\n";

echo "Step 4: merging that atom into canonical\n";

try {
    $published = $plugin->merge->merge($canonical, $draft, [(int)$canonical->siteId => [$expectedAtom]]);
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: merge() threw " . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(6);
}

$canonicalAfter = Entry::find()->id($canonical->id)->status(null)->one();
$appliedTitle = null;
foreach ($canonicalAfter->getFieldValue($matrixFieldHandle)->all() as $block) {
    if ($block->canonicalUid === $targetBlock->canonicalUid) {
        $appliedTitle = $block->title;
    }
}
if ($appliedTitle !== $newTitle) {
    fwrite(STDERR, "FAIL: canonical block title is \"" . ($appliedTitle ?? 'NULL') . "\", expected \"{$newTitle}\"\n");
    exit(6);
}
echo "  ✓ Canonical block title now \"{$appliedTitle}\"\n\n";
echo "ALL CHECKS PASSED\n";
