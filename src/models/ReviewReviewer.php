<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\models;

use craft\base\Model;
use DateTime;
use zeixcom\craftdelta\enums\ReviewVerdict;

/**
 * One reviewer's verdict on a review, for a given round.
 */
class ReviewReviewer extends Model
{
    public const VERDICT_PENDING = ReviewVerdict::Pending->value;
    public const VERDICT_APPROVED = ReviewVerdict::Approved->value;
    public const VERDICT_CHANGES_REQUESTED = ReviewVerdict::ChangesRequested->value;

    public ?int $id = null;
    public int $reviewId = 0;
    public int $userId = 0;
    public int $round = 1;
    public string $verdict = self::VERDICT_PENDING;
    public ?string $note = null;
    public ?DateTime $decidedAt = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** Display name, hydrated by the service for the UI roster (not persisted). */
    public ?string $userName = null;
}
