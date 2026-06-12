<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\models\Review;
use zeixcom\craftdelta\models\ReviewReviewer;
use zeixcom\craftdelta\services\WorkflowService;

/**
 * The derived-state precedence rule is the heart of the multi-reviewer model,
 * so it is exercised exhaustively here as a pure function (no kernel needed).
 */
class WorkflowServiceTest extends TestCase
{
    public function testEmptyVerdictsIsOpen(): void
    {
        $this->assertSame(Review::STATE_OPEN, WorkflowService::deriveState([]));
    }

    public function testAllPendingIsOpen(): void
    {
        $this->assertSame(Review::STATE_OPEN, WorkflowService::deriveState([
            ReviewReviewer::VERDICT_PENDING,
            ReviewReviewer::VERDICT_PENDING,
        ]));
    }

    public function testAnyApprovalWithoutBlockIsApproved(): void
    {
        $this->assertSame(Review::STATE_APPROVED, WorkflowService::deriveState([
            ReviewReviewer::VERDICT_PENDING,
            ReviewReviewer::VERDICT_APPROVED,
        ]));
    }

    public function testSingleApprovalIsApproved(): void
    {
        $this->assertSame(Review::STATE_APPROVED, WorkflowService::deriveState([
            ReviewReviewer::VERDICT_APPROVED,
        ]));
    }

    public function testChangesRequestedBlocksEvenWithApproval(): void
    {
        // "Any one approves" is overridden by an unresolved "changes requested".
        $this->assertSame(Review::STATE_CHANGES_REQUESTED, WorkflowService::deriveState([
            ReviewReviewer::VERDICT_APPROVED,
            ReviewReviewer::VERDICT_CHANGES_REQUESTED,
        ]));
    }

    public function testChangesRequestedAloneBlocks(): void
    {
        $this->assertSame(Review::STATE_CHANGES_REQUESTED, WorkflowService::deriveState([
            ReviewReviewer::VERDICT_CHANGES_REQUESTED,
            ReviewReviewer::VERDICT_PENDING,
        ]));
    }
}
