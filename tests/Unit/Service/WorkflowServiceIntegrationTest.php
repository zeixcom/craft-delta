<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for WorkflowService submit/approve/reject. Require a
 * booted Craft kernel — matches the existing MergeServiceIntegrationTest
 * skip pattern.
 */
class WorkflowServiceIntegrationTest extends TestCase
{
    public function testSubmitCreatesPendingRowAndSendsEmail(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot.');
    }

    public function testApproveWholesaleNowAppliesDraftToCanonical(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot.');
    }

    public function testApproveWholesaleScheduledPushesQueueJob(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot.');
    }

    public function testRejectSetsTerminalStateAndPreservesDraft(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot.');
    }

    public function testCanReviewFalseForNonAssignee(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot.');
    }
}
