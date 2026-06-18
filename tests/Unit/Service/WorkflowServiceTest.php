<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\models\Review;
use zeixcom\craftdelta\models\ReviewReviewer;
use zeixcom\craftdelta\models\Settings;
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

    public function testLegacyChangesRequestedVerdictNoLongerBlocks(): void
    {
        // "Request changes" was removed: reviewers Approve or Decline only.
        // A stale 'changes_requested' verdict (e.g. a pre-migration row) must
        // not resurrect a blocking state — only an explicit approval moves the
        // review, otherwise it stays open.
        $this->assertSame(Review::STATE_OPEN, WorkflowService::deriveState(['changes_requested', 'pending']));
        $this->assertSame(Review::STATE_APPROVED, WorkflowService::deriveState(['changes_requested', ReviewReviewer::VERDICT_APPROVED]));
    }

    public function testAllPolicyNeedsEveryAssignedReviewer(): void
    {
        // One of two approved is not enough under "all".
        $this->assertSame(Review::STATE_OPEN, WorkflowService::deriveState(
            [ReviewReviewer::VERDICT_APPROVED, ReviewReviewer::VERDICT_PENDING],
            Settings::APPROVAL_ALL,
        ));
        $this->assertSame(Review::STATE_APPROVED, WorkflowService::deriveState(
            [ReviewReviewer::VERDICT_APPROVED, ReviewReviewer::VERDICT_APPROVED],
            Settings::APPROVAL_ALL,
        ));
    }

    public function testCountPolicyNeedsAtLeastN(): void
    {
        // Require 2: one approval stays open, two flips to approved.
        $this->assertSame(Review::STATE_OPEN, WorkflowService::deriveState(
            [ReviewReviewer::VERDICT_APPROVED, ReviewReviewer::VERDICT_PENDING, ReviewReviewer::VERDICT_PENDING],
            Settings::APPROVAL_COUNT,
            2,
        ));
        $this->assertSame(Review::STATE_APPROVED, WorkflowService::deriveState(
            [ReviewReviewer::VERDICT_APPROVED, ReviewReviewer::VERDICT_APPROVED, ReviewReviewer::VERDICT_PENDING],
            Settings::APPROVAL_COUNT,
            2,
        ));
        // Requiring more than the assigned reviewer count clamps to that count.
        $this->assertSame(Review::STATE_APPROVED, WorkflowService::deriveState(
            [ReviewReviewer::VERDICT_APPROVED, ReviewReviewer::VERDICT_APPROVED],
            Settings::APPROVAL_COUNT,
            3,
        ));
    }
}
