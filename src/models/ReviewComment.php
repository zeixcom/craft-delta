<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\models;

use craft\base\Model;
use DateTime;
use zeixcom\craftdelta\enums\CommentAnchorType;

/**
 * A review comment: general (request-level thread) or anchored to a field/atom
 * in the diff. Anchored comments store both the snapshot atomId and the stable
 * parts (fieldHandle + blockUid) so they survive a revision that reshuffles
 * atom ordering; "outdated" is derived at render time, not stored.
 */
class ReviewComment extends Model
{
    public const ANCHOR_GENERAL = CommentAnchorType::General->value;
    public const ANCHOR_FIELD = CommentAnchorType::Field->value;
    public const ANCHOR_ATOM = CommentAnchorType::Atom->value;

    public ?int $id = null;
    public int $reviewId = 0;
    public int $round = 1;
    public int $authorId = 0;
    public string $body = '';
    public string $anchorType = self::ANCHOR_GENERAL;
    public ?string $fieldHandle = null;
    public ?string $blockUid = null;
    public ?string $atomId = null;
    public bool $resolved = false;
    public ?int $parentId = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** Display name, hydrated by the service for the UI. */
    public ?string $authorName = null;

    /** Derived at render time: the anchor is no longer in the live diff. */
    public bool $outdated = false;

    /** @var ReviewComment[] replies, hydrated by the service */
    public array $replies = [];
}
