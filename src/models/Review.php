<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\models;

use Craft;
use craft\base\Model;
use DateTime;
use zeixcom\craftdelta\enums\ReviewState;
use zeixcom\craftdelta\i18n\TranslationKeys;

/**
 * A review request for a draft. State machine:
 *
 *   (no row) --submit-----------> open
 *   open     --approve (any one)-> approved
 *   approved --publish----------> published    (terminal)
 *   open     --decline----------> declined     (terminal)
 *   open     --withdraw---------> cancelled     (terminal)
 *
 * Reviewers Approve or Decline; granular feedback is left as comments. `state`
 * is a CACHE of a derivation over the current round's reviewer verdicts (open /
 * approved). declined, cancelled and published are explicit, set by an action —
 * never derived.
 */
class Review extends Model
{
    public const STATE_OPEN = ReviewState::Open->value;
    public const STATE_APPROVED = ReviewState::Approved->value;
    public const STATE_DECLINED = ReviewState::Declined->value;
    public const STATE_CANCELLED = ReviewState::Cancelled->value;
    public const STATE_PUBLISHED = ReviewState::Published->value;

    private const ACTIVE_STATES = [self::STATE_OPEN, self::STATE_APPROVED];
    private const TERMINAL_STATES = [self::STATE_DECLINED, self::STATE_CANCELLED, self::STATE_PUBLISHED];

    public ?int $id = null;
    // Null once published: applyDraft() deletes the draft and the FK SET NULLs
    // this, leaving the review as a completed audit record.
    public ?int $draftId = null;
    public int $canonicalEntryId = 0;
    public string $sectionUid = '';
    public string $state = self::STATE_OPEN;
    public int $round = 1;
    public int $submittedBy = 0;
    public ?DateTime $scheduledFor = null;
    public ?DateTime $appliedAt = null;
    /** Reviewer's note on a decline (and the withdraw actor's, internally). */
    public ?string $decisionNote = null;

    /** @var ReviewReviewer[] current-round reviewers, hydrated by the service */
    public array $reviewers = [];

    public function isDeclined(): bool
    {
        return $this->state === self::STATE_DECLINED;
    }

    public function isApproved(): bool
    {
        return $this->state === self::STATE_APPROVED;
    }

    /** Still in flight — not declined, cancelled, or published. */
    public function isActive(): bool
    {
        return in_array($this->state, self::ACTIVE_STATES, true) && $this->appliedAt === null;
    }

    public function isScheduled(): bool
    {
        return $this->state === self::STATE_APPROVED && $this->scheduledFor !== null && $this->appliedAt === null;
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, self::TERMINAL_STATES, true);
    }

    /**
     * Human-readable label for the current state. Single source of truth for the
     * entry-index column pill and the sidebar status pill.
     */
    public function statusLabel(): string
    {
        return match ($this->state) {
            self::STATE_OPEN => Craft::t('craft-delta', TranslationKeys::REVIEW_IN_REVIEW),
            self::STATE_APPROVED => $this->isScheduled()
                ? Craft::t('craft-delta', TranslationKeys::APPROVED_SCHEDULED)
                : Craft::t('craft-delta', TranslationKeys::APPROVED),
            self::STATE_DECLINED => Craft::t('craft-delta', TranslationKeys::REVIEW_DECLINED),
            self::STATE_CANCELLED => Craft::t('craft-delta', TranslationKeys::REVIEW_WITHDRAWN),
            self::STATE_PUBLISHED => Craft::t('craft-delta', TranslationKeys::REVIEW_PUBLISHED),
            default => $this->state,
        };
    }

    /**
     * Craft CP `.status` dot color. Workflow state slugs (e.g. `open`) are not
     * built-in Craft status classes — map them to the native palette instead.
     */
    public function statusColor(): string
    {
        return match ($this->state) {
            self::STATE_OPEN => 'pending',
            self::STATE_APPROVED => $this->isScheduled() ? 'amber' : 'live',
            self::STATE_DECLINED => 'expired',
            self::STATE_CANCELLED => 'disabled',
            self::STATE_PUBLISHED => 'active',
            default => 'gray',
        };
    }
}
