<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $draftId
 * @property int $canonicalEntryId
 * @property string $sectionUid
 * @property string $state
 * @property int $round
 * @property int $submittedBy
 * @property int|null $decidedBy
 * @property string|null $decisionNote
 * @property string|null $scheduledFor
 * @property string|null $appliedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ReviewRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%craftdelta_reviews}}';
    }
}
