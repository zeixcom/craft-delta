<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\services\MergeService;

class MergeServiceTest extends TestCase
{
    public function testParseFieldAtom(): void
    {
        $parsed = MergeService::parseAtomKey('field:title');

        $this->assertSame('field', $parsed['kind']);
        $this->assertSame('title', $parsed['handle']);
    }

    public function testParseMatrixBlockAtom(): void
    {
        $parsed = MergeService::parseAtomKey('matrix-block:blocks:8a3f-1234:added');

        $this->assertSame('matrix-block', $parsed['kind']);
        $this->assertSame('blocks', $parsed['fieldHandle']);
        $this->assertSame('8a3f-1234', $parsed['blockUid']);
        $this->assertSame('added', $parsed['changeType']);
    }

    public function testParseMatrixReorderAtom(): void
    {
        $parsed = MergeService::parseAtomKey('matrix-reorder:blocks');

        $this->assertSame('matrix-reorder', $parsed['kind']);
        $this->assertSame('blocks', $parsed['fieldHandle']);
    }

    public function testRejectsMalformedAtom(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MergeService::parseAtomKey('bogus:thing');
    }

    public function testRejectsUnknownChangeType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MergeService::parseAtomKey('matrix-block:blocks:abc:exploded');
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MergeService::parseAtomKey('');
    }

    public function testValidateAtomsAcceptsKnownAtoms(): void
    {
        // Set of stable keys representing the fresh diff's available atoms
        $availableAtoms = [
            'field:title',
            'matrix-block:blocks:8a3f:added',
            'matrix-reorder:blocks',
        ];

        $accepted = ['field:title', 'matrix-reorder:blocks'];

        // No exception means valid
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
}
