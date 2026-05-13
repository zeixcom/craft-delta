<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\models\DraftWorkflow;
use zeixcom\craftdelta\services\WorkflowService;

class WorkflowServiceTest extends TestCase
{
    public function testPendingAllowsApproveAndReject(): void
    {
        $this->assertTrue(WorkflowService::isTransitionAllowed(
            DraftWorkflow::STATE_PENDING,
            DraftWorkflow::STATE_APPROVED
        ));
        $this->assertTrue(WorkflowService::isTransitionAllowed(
            DraftWorkflow::STATE_PENDING,
            DraftWorkflow::STATE_REJECTED
        ));
    }

    public function testApprovedIsTerminal(): void
    {
        $this->assertFalse(WorkflowService::isTransitionAllowed(
            DraftWorkflow::STATE_APPROVED,
            DraftWorkflow::STATE_PENDING
        ));
        $this->assertFalse(WorkflowService::isTransitionAllowed(
            DraftWorkflow::STATE_APPROVED,
            DraftWorkflow::STATE_REJECTED
        ));
    }

    public function testRejectedIsTerminal(): void
    {
        $this->assertFalse(WorkflowService::isTransitionAllowed(
            DraftWorkflow::STATE_REJECTED,
            DraftWorkflow::STATE_PENDING
        ));
        $this->assertFalse(WorkflowService::isTransitionAllowed(
            DraftWorkflow::STATE_REJECTED,
            DraftWorkflow::STATE_APPROVED
        ));
    }

    public function testUnknownStateRejected(): void
    {
        $this->assertFalse(WorkflowService::isTransitionAllowed(
            'bogus',
            DraftWorkflow::STATE_APPROVED
        ));
    }
}
