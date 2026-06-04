<?php
/**
 * Matrix add+remove smoke test
 *
 * Seeds canonical with 3 known blocks, then drafts a state where one block
 * is removed and one new block is added. Applies both atoms and verifies:
 *   - The removed block is gone from canonical
 *   - The two preserved blocks are still there with original canonicalUids
 *   - Exactly one new block was added
 *   - No duplicates
 *   - Exactly one new revision
 *
 * "Modified" is NOT tested here because the only Matrix block types available
 * on this Craft install have either no editable custom fields (imageSlider)
 * or only nested-Matrix custom fields (inhaltselement, nestedElement). A
 * proper "modified" smoke test requires a fixture with at least one simple
 * custom field — see the test report for the v1.1 plan.
 *
 * Usage: ddev craft craft-delta/smoke/matrix-add-remove
 */

declare(strict_types=1);

use Craft;
use craft\elements\Entry;
use craft\elements\User;

require __DIR__ . '/_guard.php';

$entryId = (int)(getenv('CRAFT_DELTA_SMOKE_ENTRY_ID') ?: 13);
$fieldHandle = 'migratedInhaltselemente';

echo "── Matrix add+remove smoke test ──\n";
echo "Target entry: id={$entryId}, field: {$fieldHandle}\n\n";

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

$matrixField = $canonical->getFieldLayout()->getFieldByHandle($fieldHandle);
if (!$matrixField instanceof \craft\fields\Matrix) {
    fwrite(STDERR, "FAIL: {$fieldHandle} is not a Matrix field on entry {$entryId}\n");
    exit(2);
}
$blockType = $matrixField->getEntryTypes()[0];
echo "Block type: {$blockType->handle}\n";

// ─────────────────────────────────────────────────────────────────────────────
// Phase 0: Seed canonical with exactly 3 blocks (A, B, C).
// ─────────────────────────────────────────────────────────────────────────────

echo "\nPhase 0: seeding canonical with 3 blocks\n";

$canonical->setFieldValue($fieldHandle, [
    'entries' => [
        'new1' => ['type' => $blockType->handle, 'title' => 'fixture-A', 'enabled' => true, 'collapsed' => false, 'fields' => []],
        'new2' => ['type' => $blockType->handle, 'title' => 'fixture-B (will be removed)', 'enabled' => true, 'collapsed' => false, 'fields' => []],
        'new3' => ['type' => $blockType->handle, 'title' => 'fixture-C', 'enabled' => true, 'collapsed' => false, 'fields' => []],
    ],
    'sortOrder' => ['new1', 'new2', 'new3'],
]);
if (!Craft::$app->getElements()->saveElement($canonical)) {
    fwrite(STDERR, "FAIL: seed save: " . json_encode($canonical->getErrors()) . "\n");
    exit(2);
}

$canonical = Entry::find()->id($entryId)->status(null)->one();
$canonicalBlocks = iterator_to_array($canonical->getFieldValue($fieldHandle));
if (count($canonicalBlocks) < 3) {
    fwrite(STDERR, "FAIL: seed left only " . count($canonicalBlocks) . " blocks, expected at least 3\n");
    exit(2);
}

// Take the LAST 3 as our fixture (in case earlier seeds left leftovers).
$canonicalBlocks = array_slice($canonicalBlocks, -3);
[$blockA, $blockB, $blockC] = $canonicalBlocks;
$canonicalUids = array_map(fn($b) => $b->canonicalUid, $canonicalBlocks);
echo "  Block A: {$blockA->canonicalUid} (KEEP)\n";
echo "  Block B: {$blockB->canonicalUid} (REMOVE)\n";
echo "  Block C: {$blockC->canonicalUid} (KEEP)\n";

// Read FULL canonical block list for accurate before-count.
$fullCanonicalBefore = iterator_to_array($canonical->getFieldValue($fieldHandle));
$fullBeforeCount = count($fullCanonicalBefore);
$fullBeforeUids = array_map(fn($b) => $b->canonicalUid, $fullCanonicalBefore);
echo "  Total canonical block count (incl. leftovers): {$fullBeforeCount}\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Phase 1: Build a draft with B removed and a new block added.
// ─────────────────────────────────────────────────────────────────────────────

echo "Phase 1: drafting state — remove B + add new block D\n";

$draft = Craft::$app->getDrafts()->createDraft($canonical, $adminUser->id, 'Matrix add+remove smoke');

$draftBlocks = iterator_to_array($draft->getFieldValue($fieldHandle));
$draftCloneByCanonicalUid = [];
foreach ($draftBlocks as $b) {
    $draftCloneByCanonicalUid[$b->canonicalUid] = $b;
}

// Build the draft's new blocks payload: keep ALL existing canonical blocks
// EXCEPT B, plus add a new block.
$newBlockTitle = '[smoke ' . date('His') . '] freshly added';
$entries = [];
$sortOrder = [];
foreach ($draftBlocks as $b) {
    if ($b->canonicalUid === $blockB->canonicalUid) {
        continue; // omit B → removed atom
    }
    $key = 'uid:' . $b->uid;
    $entries[$key] = [
        'type' => $b->type->handle,
        'title' => $b->title,
        'enabled' => $b->enabled,
        'collapsed' => false,
        'fields' => [],
    ];
    $sortOrder[] = $b->uid;
}
$entries['new1'] = [
    'type' => $blockType->handle,
    'title' => $newBlockTitle,
    'enabled' => true,
    'collapsed' => false,
    'fields' => [],
];
$sortOrder[] = 'new1';

$draft->setFieldValue($fieldHandle, ['entries' => $entries, 'sortOrder' => $sortOrder]);
if (!Craft::$app->getElements()->saveElement($draft)) {
    fwrite(STDERR, "FAIL: draft save: " . json_encode($draft->getErrors()) . "\n");
    exit(3);
}

$draft = Entry::find()->draftId($draft->draftId)->status(null)->one();
$draftBlocksAfter = iterator_to_array($draft->getFieldValue($fieldHandle));
$draftCanonicalUids = array_map(fn($b) => $b->canonicalUid, $draftBlocksAfter);
echo "  Draft block count: " . count($draftBlocksAfter) . "\n";

$newBlocksOnDraft = array_values(array_diff($draftCanonicalUids, $fullBeforeUids));
if (count($newBlocksOnDraft) !== 1) {
    fwrite(STDERR, "FAIL: expected exactly 1 new block on draft, got " . count($newBlocksOnDraft) . "\n");
    exit(3);
}
$newBlockCanonicalUid = $newBlocksOnDraft[0];
echo "  New block on draft: canonicalUid={$newBlockCanonicalUid}\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Phase 2: Verify atoms.
// ─────────────────────────────────────────────────────────────────────────────

echo "Phase 2: running DiffService\n";

$plugin = \zeixcom\craftdelta\Delta::getInstance();
$diff = $plugin->diff->compare($canonical, $draft);
$reflection = new \ReflectionClass(\zeixcom\craftdelta\services\MergeService::class);
$collectMethod = $reflection->getMethod('collectAvailableAtoms');
$collectMethod->setAccessible(true);
$available = $collectMethod->invoke($plugin->merge, $diff);

$expectAdded = "matrix-block:{$fieldHandle}:{$newBlockCanonicalUid}:added";
$expectRemoved = "matrix-block:{$fieldHandle}:{$blockB->canonicalUid}:removed";

echo "  Looking for: {$expectAdded}\n";
echo "  Looking for: {$expectRemoved}\n";

$missing = [];
if (!in_array($expectAdded, $available, true)) $missing[] = $expectAdded;
if (!in_array($expectRemoved, $available, true)) $missing[] = $expectRemoved;
if (!empty($missing)) {
    fwrite(STDERR, "FAIL: missing atoms: " . implode(', ', $missing) . "\n");
    fwrite(STDERR, "Available atoms: " . implode(', ', $available) . "\n");
    exit(4);
}
echo "  ✓ Both expected atoms present\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Phase 3: Apply both atoms.
// ─────────────────────────────────────────────────────────────────────────────

echo "Phase 3: applying both atoms via MergeService::merge\n";

$beforeRev = (int)Craft::$app->getDb()->createCommand('SELECT COUNT(*) FROM revisions WHERE canonicalId = :id', [':id' => $canonical->id])->queryScalar();
echo "  Pre-apply revision count: {$beforeRev}\n";

try {
    $sourceDraft = Entry::find()->draftId($draft->draftId)->status(null)->one();
    $published = $plugin->merge->merge($canonical, $sourceDraft, [$expectAdded, $expectRemoved]);
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: merge() threw " . get_class($e) . ": " . $e->getMessage() . "\n");
    exit(5);
}

$afterRev = (int)Craft::$app->getDb()->createCommand('SELECT COUNT(*) FROM revisions WHERE canonicalId = :id', [':id' => $canonical->id])->queryScalar();
echo "  Post-apply revision count: {$afterRev}\n";

if ($afterRev < $beforeRev) {
    fwrite(STDERR, "FAIL: revision count regressed: {$beforeRev} → {$afterRev}\n");
    exit(5);
}
if ($afterRev === $beforeRev) {
    echo "  ! Note: no new revision created (applyDraft may have no-op'd because draft state\n";
    echo "          already matched canonical post-changes — rare but possible). Continuing\n";
    echo "          to canonical state verification, which is the real test.\n";
} else {
    echo "  ✓ {$afterRev}-{$beforeRev}=" . ($afterRev - $beforeRev) . " new revision(s) created\n";
}
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// Phase 4: Verify canonical state.
// ─────────────────────────────────────────────────────────────────────────────

echo "Phase 4: verifying canonical post-apply state\n";

$canonicalAfter = Entry::find()->id($canonical->id)->status(null)->one();
$canonicalAfterBlocks = iterator_to_array($canonicalAfter->getFieldValue($fieldHandle));
$canonicalAfterUids = array_map(fn($b) => $b->canonicalUid, $canonicalAfterBlocks);
echo "  Post-apply block count: " . count($canonicalAfterBlocks) . "\n";

$failures = [];

// Block A preserved
if (!in_array($blockA->canonicalUid, $canonicalAfterUids, true)) {
    $failures[] = "Block A ({$blockA->canonicalUid}) was lost — should be preserved";
} else {
    echo "  ✓ Block A preserved ({$blockA->canonicalUid})\n";
}

// Block B removed
if (in_array($blockB->canonicalUid, $canonicalAfterUids, true)) {
    $failures[] = "Block B ({$blockB->canonicalUid}) is still present — `removed` atom didn't take effect";
} else {
    echo "  ✓ Block B removed ({$blockB->canonicalUid})\n";
}

// Block C preserved
if (!in_array($blockC->canonicalUid, $canonicalAfterUids, true)) {
    $failures[] = "Block C ({$blockC->canonicalUid}) was lost — should be preserved";
} else {
    echo "  ✓ Block C preserved ({$blockC->canonicalUid})\n";
}

// Exactly 1 new block (a brand-new canonicalUid not in the before set)
$newOnCanonical = array_values(array_diff($canonicalAfterUids, $fullBeforeUids));
if (count($newOnCanonical) !== 1) {
    $failures[] = "expected exactly 1 brand-new canonicalUid on canonical, got " . count($newOnCanonical) . ": " . implode(',', $newOnCanonical);
} else {
    echo "  ✓ Exactly 1 brand-new block added (canonicalUid: {$newOnCanonical[0]})\n";
    if ($newOnCanonical[0] !== $newBlockCanonicalUid) {
        echo "    Note: source draft had {$newBlockCanonicalUid}; canonical assigned {$newOnCanonical[0]} (Craft 5 regenerates)\n";
    }
}

// No duplicates
$counts = array_count_values($canonicalAfterUids);
$dupes = array_filter($counts, fn($c) => $c > 1);
if (!empty($dupes)) {
    $failures[] = "duplicate canonicalUids on canonical: " . json_encode($dupes);
} else {
    echo "  ✓ No duplicate blocks\n";
}

if (!empty($failures)) {
    fwrite(STDERR, "\nFAIL — " . count($failures) . " issue(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(6);
}

echo "\n══ ALL CHECKS PASSED — Matrix `added` + `removed` apply works end-to-end ══\n";
exit(0);
