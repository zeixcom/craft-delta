<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $reviewId
 * @property int $round
 * @property int $authorId
 * @property string $body
 * @property string $anchorType
 * @property string|null $fieldHandle
 * @property string|null $blockUid
 * @property string|null $atomId
 * @property bool $resolved
 * @property int|null $parentId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ReviewCommentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%craftdelta_review_comments}}';
    }
}
