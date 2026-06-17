<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\enums\ReviewState;
use zeixcom\craftdelta\enums\ReviewVerdict;
use zeixcom\craftdelta\models\Review;

class ReviewTest extends TestCase
{
    public function testChangesRequestedStateAndVerdictRemoved(): void
    {
        // The changes_requested state + verdict were removed from the enums.
        // tryFrom() returning null guards against accidental re-introduction.
        $this->assertNull(ReviewState::tryFrom('changes_requested'));
        $this->assertNull(ReviewVerdict::tryFrom('changes_requested'));
    }

    public function testLegacyUnknownStateIsNeitherActiveNorTerminal(): void
    {
        // A pre-migration 'changes_requested' row hits no known arm: it can't be
        // acted on (not active) but isn't terminal either, and its dot colour
        // falls back to the neutral default. The migration reopens such rows;
        // this documents the graceful behaviour for any that slip through.
        $review = new Review(['state' => 'changes_requested', 'appliedAt' => null]);
        $this->assertFalse($review->isActive());
        $this->assertFalse($review->isTerminal());
        $this->assertSame('gray', $review->statusColor());
    }

    public function testStateConstants(): void
    {
        $this->assertSame('open', Review::STATE_OPEN);
        $this->assertSame('approved', Review::STATE_APPROVED);
        $this->assertSame('declined', Review::STATE_DECLINED);
        $this->assertSame('cancelled', Review::STATE_CANCELLED);
        $this->assertSame('published', Review::STATE_PUBLISHED);
    }

    public function testIsActiveForInFlightStates(): void
    {
        foreach ([Review::STATE_OPEN, Review::STATE_APPROVED] as $state) {
            $review = new Review(['state' => $state, 'appliedAt' => null]);
            $this->assertTrue($review->isActive(), "expected {$state} to be active");
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

    public function testStatusColorMapsToCraftPalette(): void
    {
        $this->assertSame('pending', (new Review(['state' => Review::STATE_OPEN]))->statusColor());
        $this->assertSame('live', (new Review(['state' => Review::STATE_APPROVED]))->statusColor());
        $this->assertSame('amber', (new Review([
            'state' => Review::STATE_APPROVED,
            'scheduledFor' => new \DateTime('+1 hour'),
        ]))->statusColor());
        $this->assertSame('expired', (new Review(['state' => Review::STATE_DECLINED]))->statusColor());
        $this->assertSame('disabled', (new Review(['state' => Review::STATE_CANCELLED]))->statusColor());
        $this->assertSame('active', (new Review(['state' => Review::STATE_PUBLISHED]))->statusColor());
    }
}
