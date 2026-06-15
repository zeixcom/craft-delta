<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\models\DiffResult;
use zeixcom\craftdelta\models\FieldDiff;
use zeixcom\craftdelta\services\MergeService;
use zeixcom\craftdelta\services\StaleAtomException;

class MergeServiceTest extends TestCase
{
    public function testValidateAtomsAcceptsKnownAtoms(): void
    {
        $availableAtoms = [
            'field:title',
            'matrix-block:blocks:8a3f:added',
            'matrix-reorder:blocks',
        ];

        $accepted = ['field:title', 'matrix-reorder:blocks'];

        MergeService::validateAtoms($availableAtoms, $accepted);
        $this->addToAssertionCount(1);
    }

    public function testValidateAtomsRejectsUnknownAtom(): void
    {
        $availableAtoms = ['field:title'];
        $accepted = ['field:body'];

        $this->expectException(\zeixcom\craftdelta\services\StaleAtomException::class);
        MergeService::validateAtoms($availableAtoms, $accepted);
    }

    public function testValidateAtomsRejectsMalformedAtom(): void
    {
        $availableAtoms = ['field:title'];
        $accepted = ['malformed-key'];

        $this->expectException(\InvalidArgumentException::class);
        MergeService::validateAtoms($availableAtoms, $accepted);
    }

    public function testValidateAtomsRejectsTooManyAcceptedAtoms(): void
    {
        $accepted = array_map(static fn(int $i) => "field:f{$i}", range(1, 501));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Too many accepted atoms');
        MergeService::validateAtoms([], $accepted);
    }

    public function testBuildBlockListAcceptedAddedIncludesSourceBlock(): void
    {
        $current = [['uid' => 'A', 'content' => 'a']];
        $source = [['uid' => 'A', 'content' => 'a'], ['uid' => 'X', 'content' => 'x']];
        $atoms = [['blockUid' => 'X', 'changeType' => 'added']];

        $result = MergeService::buildMatrixBlockList($current, $source, $atoms);

        $uids = array_column($result, 'uid');
        $this->assertContains('A', $uids);
        $this->assertContains('X', $uids);
    }

    public function testBuildBlockListRejectedAddedDoesNotIncludeSourceBlock(): void
    {
        $current = [['uid' => 'A', 'content' => 'a']];
        $source = [['uid' => 'A', 'content' => 'a'], ['uid' => 'X', 'content' => 'x']];
        $atoms = []; // X-added not accepted

        $result = MergeService::buildMatrixBlockList($current, $source, $atoms);

        $uids = array_column($result, 'uid');
        $this->assertNotContains('X', $uids);
    }

    public function testBuildBlockListAcceptedRemovedDropsBlock(): void
    {
        $current = [['uid' => 'A', 'content' => 'a'], ['uid' => 'B', 'content' => 'b']];
        $source = [['uid' => 'A', 'content' => 'a']];
        $atoms = [['blockUid' => 'B', 'changeType' => 'removed']];

        $result = MergeService::buildMatrixBlockList($current, $source, $atoms);

        $uids = array_column($result, 'uid');
        $this->assertNotContains('B', $uids);
    }

    public function testBuildBlockListRejectedRemovedKeepsBlock(): void
    {
        $current = [['uid' => 'A', 'content' => 'a'], ['uid' => 'B', 'content' => 'b']];
        $source = [['uid' => 'A', 'content' => 'a']];
        $atoms = []; // B-removed rejected

        $result = MergeService::buildMatrixBlockList($current, $source, $atoms);

        $uids = array_column($result, 'uid');
        $this->assertContains('B', $uids);
    }

    public function testBuildBlockListAcceptedModifiedReplacesContent(): void
    {
        $current = [['uid' => 'A', 'content' => 'old']];
        $source = [['uid' => 'A', 'content' => 'new']];
        $atoms = [['blockUid' => 'A', 'changeType' => 'modified']];

        $result = MergeService::buildMatrixBlockList($current, $source, $atoms);

        $this->assertSame('new', $result[0]['content']);
    }

    public function testBuildBlockListRejectedModifiedKeepsCurrentContent(): void
    {
        $current = [['uid' => 'A', 'content' => 'old']];
        $source = [['uid' => 'A', 'content' => 'new']];
        $atoms = [];

        $result = MergeService::buildMatrixBlockList($current, $source, $atoms);

        $this->assertSame('old', $result[0]['content']);
    }

    public function testOrderNoReorderPreservesCurrentOrder(): void
    {
        $survivors = [
            ['uid' => 'A'],
            ['uid' => 'B'],
            ['uid' => 'C'],
        ];
        $current = [['uid' => 'A'], ['uid' => 'B'], ['uid' => 'C']];
        $source = [['uid' => 'C'], ['uid' => 'B'], ['uid' => 'A']]; // reversed

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, false);

        $this->assertSame(['A', 'B', 'C'], array_column($result, 'uid'));
    }

    public function testOrderNoReorderAppendsSourceOnlyAddedAtEnd(): void
    {
        $survivors = [
            ['uid' => 'A'],
            ['uid' => 'B'],
            ['uid' => 'X'],
        ];
        $current = [['uid' => 'A'], ['uid' => 'B']];
        $source = [['uid' => 'X'], ['uid' => 'A'], ['uid' => 'B']];

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, false);

        $this->assertSame(['A', 'B', 'X'], array_column($result, 'uid'));
    }

    public function testOrderNoReorderKeepsCurrentOnlyBlocksInPlace(): void
    {
        $survivors = [
            ['uid' => 'A'],
            ['uid' => 'B'],
            ['uid' => 'C'],
        ];
        $current = [['uid' => 'A'], ['uid' => 'B'], ['uid' => 'C']];
        $source = [['uid' => 'A'], ['uid' => 'C']];

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, false);

        $this->assertSame(['A', 'B', 'C'], array_column($result, 'uid'));
    }

    public function testOrderReorderUsesSourceOrderForBothSidesBlocks(): void
    {
        $survivors = [
            ['uid' => 'A'],
            ['uid' => 'B'],
            ['uid' => 'C'],
        ];
        $current = [['uid' => 'A'], ['uid' => 'B'], ['uid' => 'C']];
        $source = [['uid' => 'C'], ['uid' => 'B'], ['uid' => 'A']];

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, true);

        $this->assertSame(['C', 'B', 'A'], array_column($result, 'uid'));
    }

    public function testOrderReorderInsertsSourceOnlyAddedAtSourcePosition(): void
    {
        $survivors = [
            ['uid' => 'A'],
            ['uid' => 'X'],
            ['uid' => 'B'],
        ];
        $current = [['uid' => 'A'], ['uid' => 'B']];
        $source = [['uid' => 'A'], ['uid' => 'X'], ['uid' => 'B']];

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, true);

        $this->assertSame(['A', 'X', 'B'], array_column($result, 'uid'));
    }

    /**
     * Worked example from spec §6.2:
     * - Current order: A, B, C, D, E (B exists only in current)
     * - Source order: A, X, C, E, D (X is source-only; D and E reordered)
     * - User accepts: X-added, reorder. User rejects: B-removed.
     *
     * B's anchor in current is A (the most recent both-sides block before B).
     * Expected result: A, B, X, C, E, D
     */
    public function testOrderReorderAnchorRuleFromSpec(): void
    {
        $survivors = [
            ['uid' => 'A'],
            ['uid' => 'B'], // current-only, kept
            ['uid' => 'C'],
            ['uid' => 'D'],
            ['uid' => 'E'],
            ['uid' => 'X'], // source-only, added
        ];
        $current = [
            ['uid' => 'A'], ['uid' => 'B'], ['uid' => 'C'], ['uid' => 'D'], ['uid' => 'E'],
        ];
        $source = [
            ['uid' => 'A'], ['uid' => 'X'], ['uid' => 'C'], ['uid' => 'E'], ['uid' => 'D'],
        ];

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, true);

        $this->assertSame(['A', 'B', 'X', 'C', 'E', 'D'], array_column($result, 'uid'));
    }

    public function testOrderReorderMultipleCurrentOnlyBlocksPreserveRelativeOrder(): void
    {
        $survivors = [
            ['uid' => 'A'],
            ['uid' => 'B1'],
            ['uid' => 'B2'],
            ['uid' => 'C'],
        ];
        $current = [['uid' => 'A'], ['uid' => 'B1'], ['uid' => 'B2'], ['uid' => 'C']];
        $source = [['uid' => 'A'], ['uid' => 'C']];

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, true);

        $this->assertSame(['A', 'B1', 'B2', 'C'], array_column($result, 'uid'));
    }

    public function testOrderReorderCurrentOnlyBeforeFirstAnchorGoesFirst(): void
    {
        $survivors = [
            ['uid' => 'B'],
            ['uid' => 'A'],
        ];
        $current = [['uid' => 'B'], ['uid' => 'A']];
        $source = [['uid' => 'A']];

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, true);

        $this->assertSame(['B', 'A'], array_column($result, 'uid'));
    }

    public function testBuildMatrixSetValueExistingFirstKeepsUidMode(): void
    {
        $ordered = [
            ['uid' => 'CANON', 'payload' => ['type' => 'text']],
            ['uid' => 'NEWBLK', 'payload' => ['type' => 'text']],
        ];
        $currentByCanonicalUid = [
            'CANON' => ['draftEntryUid' => 'draft-uid-1'],
        ];

        $value = MergeService::buildMatrixSetValue($ordered, $currentByCanonicalUid);

        // Existing block keyed uid:…, new block keyed newN; sortOrder in display order.
        $this->assertArrayHasKey('uid:draft-uid-1', $value['entries']);
        $this->assertArrayHasKey('new1', $value['entries']);
        $this->assertSame(['draft-uid-1', 'new1'], $value['sortOrder']);
        // First entries key is UID-shaped → Craft stays in UID mode.
        $this->assertStringStartsWith('uid:', array_key_first($value['entries']));
    }

    public function testBuildMatrixSetValueNewBlockFirstStillKeepsExistingFirstInMap(): void
    {
        // Regression for the silent data-loss bug: a NEW block sorts FIRST.
        // Craft only inspects the first entries key to pick UID vs ID mode, so
        // the existing block MUST still be the first key in the entries map even
        // though it is second in display order.
        $ordered = [
            ['uid' => 'NEWBLK', 'payload' => ['type' => 'text']],   // displayed first
            ['uid' => 'CANON', 'payload' => ['type' => 'text']],    // existing, displayed second
        ];
        $currentByCanonicalUid = [
            'CANON' => ['draftEntryUid' => 'draft-uid-1'],
        ];

        $value = MergeService::buildMatrixSetValue($ordered, $currentByCanonicalUid);

        // Display order preserved via sortOrder: new block first.
        $this->assertSame(['new1', 'draft-uid-1'], $value['sortOrder']);
        // But the entries MAP leads with the UID-shaped key so Craft keeps UID
        // mode and does not drop the existing block.
        $this->assertStringStartsWith('uid:', array_key_first($value['entries']));
        $this->assertSame(
            ['uid:draft-uid-1', 'new1'],
            array_keys($value['entries']),
        );
    }

    public function testBuildMatrixSetValueAllNewBlocks(): void
    {
        $ordered = [
            ['uid' => 'N1', 'payload' => ['type' => 'text']],
            ['uid' => 'N2', 'payload' => ['type' => 'text']],
        ];

        $value = MergeService::buildMatrixSetValue($ordered, []);

        $this->assertSame(['new1', 'new2'], array_keys($value['entries']));
        $this->assertSame(['new1', 'new2'], $value['sortOrder']);
    }

    private function fieldDiff(array $config): FieldDiff
    {
        return new FieldDiff(array_merge([
            'fieldHandle' => 'x',
            'fieldLabel' => 'X',
            'fieldType' => 'craft\\fields\\PlainText',
            'hasChanges' => true,
            'diffHtml' => '',
        ], $config));
    }

    public function testCollectAvailableAtomsForFieldAndMatrix(): void
    {
        $diff = new DiffResult(['fieldDiffs' => [
            $this->fieldDiff(['fieldHandle' => 'title', 'fieldType' => 'attribute']),
            $this->fieldDiff([
                'fieldHandle' => 'blocks',
                'fieldType' => 'craft\\fields\\Matrix',
                'diffHtml' => json_encode([
                    ['type' => 'added', 'blockUid' => 'A'],
                    ['type' => 'removed', 'blockUid' => 'B'],
                    ['type' => 'modified', 'blockUid' => 'C'],
                    ['type' => 'reordered'],
                ]),
            ]),
        ]]);

        $atoms = MergeService::collectAvailableAtoms($diff);

        $this->assertContains('field:title', $atoms);
        $this->assertContains('matrix-block:blocks:A:added', $atoms);
        $this->assertContains('matrix-block:blocks:B:removed', $atoms);
        $this->assertContains('matrix-block:blocks:C:modified', $atoms);
        $this->assertContains('matrix-reorder:blocks', $atoms);
    }

    public function testCollectAvailableAtomsSkipsUnchangedAndUnparseable(): void
    {
        $diff = new DiffResult(['fieldDiffs' => [
            $this->fieldDiff(['fieldHandle' => 'untouched', 'hasChanges' => false]),
            $this->fieldDiff(['fieldHandle' => 'blocks', 'fieldType' => 'craft\\fields\\Matrix', 'diffHtml' => 'not-json']),
        ]]);

        $this->assertSame([], MergeService::collectAvailableAtoms($diff));
    }

    public function testValidateAtomsThrowsStaleWhenAcceptedAtomNoLongerOffered(): void
    {
        // Simulates the canonical/source changing after the reviewer loaded the
        // diff: a matrix block the reviewer accepted is no longer in the fresh
        // diff, so the apply must abort as stale rather than apply a phantom.
        $freshDiff = new DiffResult(['fieldDiffs' => [
            $this->fieldDiff([
                'fieldHandle' => 'blocks',
                'fieldType' => 'craft\\fields\\Matrix',
                'diffHtml' => json_encode([['type' => 'added', 'blockUid' => 'A']]),
            ]),
        ]]);
        $available = MergeService::collectAvailableAtoms($freshDiff);

        $this->expectException(StaleAtomException::class);
        MergeService::validateAtoms($available, ['matrix-block:blocks:GONE:added']);
    }
}
