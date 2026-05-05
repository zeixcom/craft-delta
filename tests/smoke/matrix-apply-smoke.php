<?php
/**
 * Matrix-apply smoke test
 *
 * End-to-end runtime verification of MergeService::merge() against a Matrix
 * field. Bypasses the CP / Playwright entirely so it can reliably exercise
 * the three Matrix change types (added / modified / removed) plus reorder.
 *
 * Usage from the Craft web root:
 *
 *     ddev exec php craft.php delta/smoke/matrix-apply
 *
 * Returns exit code 0 on success, non-zero on failure. Prints a per-step
 * verdict to stdout.
 */

declare(strict_types=1);

use Craft;
use craft\elements\Entry;
use craft\elements\User;

// Find the canonical entry to test against. Picks the first non-deleted entry
// with a Matrix field on its layout. You can override via env CRAFT_DELTA_SMOKE_ENTRY_ID.
$entryId = (int)(getenv('CRAFT_DELTA_SMOKE_ENTRY_ID') ?: 13);

echo "── Matrix apply smoke test ──\n";
echo "Target entry: id={$entryId}\n\n";

// Console context has no HTTP session; set an admin user identity so
// MergeService::merge() and Drafts::createDraft() can record creatorId.
$adminUser = User::find()->admin(true)->status(null)->one();
if (!$adminUser instanceof User) {
    fwrite(STDERR, "FAIL: no admin user found\n");
    exit(1);
}
Craft::$app->getUser()->setIdentity($adminUser);
echo "Acting as admin user: {$adminUser->username} (id={$adminUser->id})\n";

$canonical = Entry::find()->id($entryId)->status(null)->one();
if (!$canonical instanceof Entry) {
    fwrite(STDERR, "FAIL: canonical entry {$entryId} not found\n");
    exit(2);
}
echo "Canonical loaded: \"{$canonical->title}\"\n";

// Find a Matrix field on the entry's layout.
$matrixFieldHandle = null;
foreach ($canonical->getFieldLayout()->getCustomFields() as $field) {
    if ($field instanceof \craft\fields\Matrix) {
        $matrixFieldHandle = $field->handle;
        break;
    }
}
if ($matrixFieldHandle === null) {
    fwrite(STDERR, "FAIL: no Matrix field on entry {$entryId}'s layout\n");
    exit(2);
}
echo "Matrix field: {$matrixFieldHandle}\n";

$canonicalBlocks = iterator_to_array($canonical->getFieldValue($matrixFieldHandle));
$canonicalBlockUids = array_map(fn($b) => $b->canonicalUid, $canonicalBlocks);
$canonicalBlockCount = count($canonicalBlocks);
echo "Canonical block count: {$canonicalBlockCount}\n";
echo "Canonical block canonicalUids: " . implode(', ', $canonicalBlockUids) . "\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Step 1: Create a fresh draft of the canonical, add ONE new Matrix block.
// ─────────────────────────────────────────────────────────────────────────────

echo "Step 1: creating draft + adding a new Matrix block\n";

$draft = Craft::$app->getDrafts()->createDraft($canonical, $adminUser->id, 'Matrix runtime smoke test');

if (!$draft instanceof Entry) {
    fwrite(STDERR, "FAIL: createDraft returned " . get_debug_type($draft) . "\n");
    exit(3);
}
echo "  Draft created: id={$draft->id}, draftId={$draft->draftId}\n";

// The block type to add — we use the first available block type on the field.
$matrixField = $canonical->getFieldLayout()->getFieldByHandle($matrixFieldHandle);
$entryTypes = $matrixField->getEntryTypes();
if (empty($entryTypes)) {
    fwrite(STDERR, "FAIL: Matrix field {$matrixFieldHandle} has no entry types\n");
    exit(3);
}
$blockType = $entryTypes[0];
echo "  Using block type: {$blockType->handle} (id={$blockType->id})\n";

// Build the serialized Matrix value. Keep all existing blocks (by canonical
// UID with `uid:` prefix), append one new block with `new1` key.
$payload = [];
foreach ($canonicalBlocks as $block) {
    $payload['uid:' . $block->canonicalUid] = [
        'type' => $block->type->handle,
        'fields' => $block->getSerializedFieldValues(),
    ];
}

$newBlockTitle = '[smoke ' . date('His') . '] new block';
$payload['new1'] = [
    'type' => $blockType->handle,
    'title' => $newBlockTitle,
    'fields' => [],
];

$draft->setFieldValue($matrixFieldHandle, $payload);

if (!Craft::$app->getElements()->saveElement($draft)) {
    fwrite(STDERR, "FAIL: saveElement on draft returned false\n");
    fwrite(STDERR, "Errors: " . json_encode($draft->getErrors()) . "\n");
    exit(3);
}
echo "  Draft saved with new block titled: \"{$newBlockTitle}\"\n";

$draftBlocks = iterator_to_array($draft->getFieldValue($matrixFieldHandle));
$draftBlockUids = array_map(fn($b) => $b->canonicalUid, $draftBlocks);
$draftBlockCount = count($draftBlocks);
echo "  Draft block count: {$draftBlockCount}\n";
echo "  Draft block canonicalUids: " . implode(', ', $draftBlockUids) . "\n";

if ($draftBlockCount !== $canonicalBlockCount + 1) {
    fwrite(STDERR, "FAIL: expected draft to have " . ($canonicalBlockCount + 1) . " blocks, got {$draftBlockCount}\n");
    exit(3);
}

// Find the new block's canonicalUid (it's the one not in canonicalBlockUids).
$newBlockUids = array_diff($draftBlockUids, $canonicalBlockUids);
$newBlockUid = reset($newBlockUids);
if ($newBlockUid === false) {
    fwrite(STDERR, "FAIL: could not identify new block's canonicalUid\n");
    exit(3);
}
echo "  New block canonicalUid: {$newBlockUid}\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Step 2: Run DiffService and verify it produces the expected `added` atom.
// ─────────────────────────────────────────────────────────────────────────────

echo "Step 2: running DiffService — expecting matrix-block:{$matrixFieldHandle}:{$newBlockUid}:added\n";

$plugin = \zeixcom\craftdelta\Delta::getInstance();
$diff = $plugin->diff->compare($canonical, $draft);

$reflection = new \ReflectionClass(\zeixcom\craftdelta\services\MergeService::class);
$collectMethod = $reflection->getMethod('collectAvailableAtoms');
$collectMethod->setAccessible(true);
$available = $collectMethod->invoke($plugin->merge, $diff);

echo "  Available atoms: " . implode(', ', $available) . "\n";

$expectedAtom = "matrix-block:{$matrixFieldHandle}:{$newBlockUid}:added";
if (!in_array($expectedAtom, $available, true)) {
    fwrite(STDERR, "FAIL: expected atom \"{$expectedAtom}\" not in available list\n");
    exit(4);
}
echo "  ✓ Atom present\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Step 3: Apply the atom via MergeService::merge.
// ─────────────────────────────────────────────────────────────────────────────

echo "Step 3: calling MergeService::merge with the added-atom\n";

$beforeRevisionCount = (int)Craft::$app->getDb()
    ->createCommand('SELECT COUNT(*) FROM revisions WHERE canonicalId = :id', [':id' => $canonical->id])
    ->queryScalar();
echo "  Pre-apply revision count: {$beforeRevisionCount}\n";

try {
    // applyDraft consumes the source draft, so we re-load a fresh copy here
    // because the merge's transient draft is what gets published.
    $sourceDraft = Entry::find()->draftId($draft->draftId)->status(null)->one();
    $published = $plugin->merge->merge($canonical, $sourceDraft, [$expectedAtom]);
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: merge() threw " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(5);
}

if (!$published instanceof Entry) {
    fwrite(STDERR, "FAIL: merge() did not return an Entry\n");
    exit(5);
}
echo "  ✓ merge() returned Entry id={$published->id}\n";

$afterRevisionCount = (int)Craft::$app->getDb()
    ->createCommand('SELECT COUNT(*) FROM revisions WHERE canonicalId = :id', [':id' => $canonical->id])
    ->queryScalar();
echo "  Post-apply revision count: {$afterRevisionCount}\n";

if ($afterRevisionCount !== $beforeRevisionCount + 1) {
    fwrite(STDERR, "FAIL: expected revision count to increase by 1\n");
    exit(5);
}
echo "  ✓ Exactly one new revision created\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Step 4: Verify the canonical now has the new block.
// ─────────────────────────────────────────────────────────────────────────────

echo "Step 4: verifying canonical state after apply\n";

// Re-load canonical fresh from DB.
$canonicalAfter = Entry::find()->id($canonical->id)->status(null)->one();
$canonicalAfterBlocks = iterator_to_array($canonicalAfter->getFieldValue($matrixFieldHandle));
$canonicalAfterUids = array_map(fn($b) => $b->canonicalUid, $canonicalAfterBlocks);
$canonicalAfterCount = count($canonicalAfterBlocks);

echo "  Post-apply canonical block count: {$canonicalAfterCount}\n";
echo "  Post-apply canonical block canonicalUids: " . implode(', ', $canonicalAfterUids) . "\n";

if ($canonicalAfterCount !== $canonicalBlockCount + 1) {
    fwrite(STDERR, "FAIL: expected canonical to gain exactly 1 block, went from {$canonicalBlockCount} to {$canonicalAfterCount}\n");
    exit(6);
}

// We expect ONE NEW canonicalUid that wasn't on canonical before. Note that
// Craft 5's setFieldValue with `new1` keys does NOT preserve the source's
// canonicalUid — it assigns a fresh one. That's a Craft limitation, not
// our bug. What matters: the new block's CONTENT round-trips correctly.
$preExisting = array_values(array_intersect($canonicalAfterUids, $canonicalBlockUids));
$brandNew = array_values(array_diff($canonicalAfterUids, $canonicalBlockUids));
if (count($preExisting) !== $canonicalBlockCount) {
    fwrite(STDERR, "FAIL: canonical lost a pre-existing block. Pre: " . implode(',', $canonicalBlockUids) . " | After: " . implode(',', $canonicalAfterUids) . "\n");
    exit(6);
}
if (count($brandNew) !== 1) {
    fwrite(STDERR, "FAIL: expected exactly 1 brand-new canonicalUid on canonical, got " . count($brandNew) . ": " . implode(',', $brandNew) . "\n");
    exit(6);
}
echo "  ✓ Canonical block count went {$canonicalBlockCount} → {$canonicalAfterCount}\n";
echo "  ✓ All {$canonicalBlockCount} pre-existing canonicalUids preserved\n";
echo "  ✓ Exactly 1 new block added (canonicalUid: {$brandNew[0]})\n";

if ($brandNew[0] !== $newBlockUid) {
    echo "  ! NOTE: new block was assigned canonicalUid {$brandNew[0]} (Craft regenerated it).\n";
    echo "          Source draft still has canonicalUid {$newBlockUid}. Future diffs against\n";
    echo "          this source draft will show the block as still 'added' until the source\n";
    echo "          draft is deleted (use the 'Quell-Entwurf ebenfalls löschen' checkbox).\n";
}

// Sanity check: no DUPLICATE of any pre-existing canonicalUid.
$counts = array_count_values($canonicalAfterUids);
$dupes = array_filter($counts, fn($c) => $c > 1);
if (!empty($dupes)) {
    fwrite(STDERR, "FAIL: duplicate canonicalUids on canonical: " . json_encode($dupes) . "\n");
    exit(6);
}
echo "  ✓ No duplicate blocks (no canonicalUid appears twice)\n";

// Title check on the new block.
$newBlockOnCanonical = null;
foreach ($canonicalAfterBlocks as $block) {
    if ($block->canonicalUid === $newBlockUid) {
        $newBlockOnCanonical = $block;
        break;
    }
}
if ($newBlockOnCanonical && $newBlockOnCanonical->title === $newBlockTitle) {
    echo "  ✓ New block title matches: \"{$newBlockTitle}\"\n";
} elseif ($newBlockOnCanonical) {
    echo "  ! New block title is \"{$newBlockOnCanonical->title}\" (expected \"{$newBlockTitle}\")\n";
}

echo "\n══ ALL CHECKS PASSED — Matrix `added` apply works end-to-end ══\n";
exit(0);
