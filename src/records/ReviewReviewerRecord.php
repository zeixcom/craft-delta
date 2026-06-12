<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\records;

use craft\db\ActiveRecord;

/**
 * One row per (reviewer, round). The current round's rows drive the review's
 * derived state; older rounds are kept as history.
 *
 * @property int $id
 * @property int $reviewId
 * @property int $userId
 * @property int $round
 * @property string $verdict
 * @property string|null $note
 * @property string|null $decidedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ReviewReviewerRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%craftdelta_review_reviewers}}';
    }
}
