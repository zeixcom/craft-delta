<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\models\Review;

class ReviewTest extends TestCase
{
    public function testStateConstants(): void
    {
        $this->assertSame('open', Review::STATE_OPEN);
        $this->assertSame('changes_requested', Review::STATE_CHANGES_REQUESTED);
        $this->assertSame('approved', Review::STATE_APPROVED);
        $this->assertSame('declined', Review::STATE_DECLINED);
        $this->assertSame('cancelled', Review::STATE_CANCELLED);
        $this->assertSame('published', Review::STATE_PUBLISHED);
    }

    public function testIsActiveForInFlightStates(): void
    {
        foreach ([Review::STATE_OPEN, Review::STATE_CHANGES_REQUESTED, Review::STATE_APPROVED] as $state) {
            $review = new Review(['state' => $state, 'appliedAt' => null]);
            $this->assertTrue($review->isActive(), "expected {$state} to be active");
            // isPending() is the back-compat alias the granular-apply gate uses.
            $this->assertTrue($review->isPending(), "expected {$state} isPending()");
        }
    }

    public function testIsNotActiveForTerminalStates(): void
    {
        foreach ([Review::STATE_DECLINED, Review::STATE_CANCELLED, Review::STATE_PUBLISHED] as $state) {
            $review = new Review(['state' => $state]);
            $this->assertFalse($review->isActive(), "expected {$state} to be inactive");
            $this->assertTrue($review->isTerminal(), "expected {$state} to be terminal");
        }
    }

    public function testApprovedButAppliedIsNotActive(): void
    {
        $review = new Review([
            'state' => Review::STATE_APPROVED,
            'appliedAt' => new \DateTime(),
        ]);
        $this->assertFalse($review->isActive());
    }

    public function testIsScheduledTrueWhenFutureAndNotApplied(): void
    {
        $review = new Review([
            'state' => Review::STATE_APPROVED,
            'scheduledFor' => new \DateTime('+1 hour'),
            'appliedAt' => null,
        ]);
        $this->assertTrue($review->isScheduled());
    }

    public function testIsScheduledFalseOnceApplied(): void
    {
        $review = new Review([
            'state' => Review::STATE_APPROVED,
            'scheduledFor' => new \DateTime('+1 hour'),
            'appliedAt' => new \DateTime(),
        ]);
        $this->assertFalse($review->isScheduled());
    }

    public function testOpenIsNotTerminal(): void
    {
        $this->assertFalse((new Review(['state' => Review::STATE_OPEN]))->isTerminal());
    }
}
