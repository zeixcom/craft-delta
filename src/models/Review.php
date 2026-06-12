<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\models;

use craft\base\Model;
use DateTime;
use zeixcom\craftdelta\enums\ReviewState;
use zeixcom\craftdelta\i18n\TranslationKeys;

/**
 * A review request for a draft. State machine:
 *
 *   (no row) --submit--------> open
 *   open     --request changes-> changes_requested
 *   changes_requested --re-request--> open            (author revised, round++)
 *   open     --approve (any one)--> approved
 *   approved --publish---------> published            (terminal)
 *   open/changes_requested --decline--> declined      (terminal)
 *   open/changes_requested --withdraw--> cancelled    (terminal)
 *
 * `state` is a CACHE of a derivation over the current round's reviewer verdicts
 * (open / changes_requested / approved). declined, cancelled and published are
 * explicit, set by an action — never derived.
 */
class Review extends Model
{
    public const STATE_OPEN = ReviewState::Open->value;
    public const STATE_CHANGES_REQUESTED = ReviewState::ChangesRequested->value;
    public const STATE_APPROVED = ReviewState::Approved->value;
    public const STATE_DECLINED = ReviewState::Declined->value;
    public const STATE_CANCELLED = ReviewState::Cancelled->value;
    public const STATE_PUBLISHED = ReviewState::Published->value;

    public ?int $id = null;
    // Null once published: applyDraft() deletes the draft and the FK SET NULLs
    // this, leaving the review as a completed audit record.
    public ?int $draftId = null;
    public int $canonicalEntryId = 0;
    public string $sectionUid = '';
    public string $state = self::STATE_OPEN;
    public int $round = 1;
    public int $submittedBy = 0;
    public ?int $decidedBy = null;
    public ?string $decisionNote = null;
    public ?DateTime $scheduledFor = null;
    public ?DateTime $appliedAt = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** @var ReviewReviewer[] current-round reviewers, hydrated by the service */
    public array $reviewers = [];

    public function isChangesRequested(): bool
    {
        return $this->state === self::STATE_CHANGES_REQUESTED;
    }

    public function isApproved(): bool
    {
        return $this->state === self::STATE_APPROVED;
    }

    /** Still in flight — not declined, cancelled, or published. */
    public function isActive(): bool
    {
        return in_array($this->state, [self::STATE_OPEN, self::STATE_CHANGES_REQUESTED, self::STATE_APPROVED], true)
            && $this->appliedAt === null;
    }

    public function isScheduled(): bool
    {
        return $this->state === self::STATE_APPROVED
            && $this->scheduledFor !== null
            && $this->appliedAt === null;
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, [self::STATE_DECLINED, self::STATE_CANCELLED, self::STATE_PUBLISHED], true);
    }

    /**
     * Human-readable label for the current state. Single source of truth for the
     * entry-index column pill and the sidebar status pill.
     */
    public function statusLabel(): string
    {
        return match ($this->state) {
            self::STATE_OPEN => \Craft::t('craft-delta', TranslationKeys::REVIEW_IN_REVIEW),
            self::STATE_CHANGES_REQUESTED => \Craft::t('craft-delta', TranslationKeys::REVIEW_CHANGES_REQUESTED),
            self::STATE_APPROVED => $this->isScheduled()
                ? \Craft::t('craft-delta', TranslationKeys::APPROVED_SCHEDULED)
                : \Craft::t('craft-delta', TranslationKeys::APPROVED),
            self::STATE_DECLINED => \Craft::t('craft-delta', TranslationKeys::REVIEW_DECLINED),
            self::STATE_CANCELLED => \Craft::t('craft-delta', TranslationKeys::REVIEW_WITHDRAWN),
            self::STATE_PUBLISHED => \Craft::t('craft-delta', TranslationKeys::REVIEW_PUBLISHED),
            default => $this->state,
        };
    }
}
