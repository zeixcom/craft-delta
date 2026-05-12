<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\models\DraftWorkflow;

class DraftWorkflowTest extends TestCase
{
    public function testStateConstantsExist(): void
    {
        $this->assertSame('pending', DraftWorkflow::STATE_PENDING);
        $this->assertSame('approved', DraftWorkflow::STATE_APPROVED);
        $this->assertSame('rejected', DraftWorkflow::STATE_REJECTED);
    }

    public function testIsScheduledTrueWhenScheduledForInFuture(): void
    {
        $wf = new DraftWorkflow([
            'state' => DraftWorkflow::STATE_APPROVED,
            'scheduledFor' => new \DateTime('+1 hour'),
            'appliedAt' => null,
        ]);
        $this->assertTrue($wf->isScheduled());
    }

    public function testIsScheduledFalseWhenAlreadyApplied(): void
    {
        $wf = new DraftWorkflow([
            'state' => DraftWorkflow::STATE_APPROVED,
            'scheduledFor' => new \DateTime('+1 hour'),
            'appliedAt' => new \DateTime(),
        ]);
        $this->assertFalse($wf->isScheduled());
    }

    public function testIsTerminalForApprovedAndRejected(): void
    {
        $approved = new DraftWorkflow(['state' => DraftWorkflow::STATE_APPROVED, 'appliedAt' => new \DateTime()]);
        $rejected = new DraftWorkflow(['state' => DraftWorkflow::STATE_REJECTED]);
        $pending = new DraftWorkflow(['state' => DraftWorkflow::STATE_PENDING]);

        $this->assertTrue($approved->isTerminal());
        $this->assertTrue($rejected->isTerminal());
        $this->assertFalse($pending->isTerminal());
    }
}
