<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $draftId
 * @property int $canonicalEntryId
 * @property string $sectionUid
 * @property string $state
 * @property int $submittedBy
 * @property int|null $assigneeId
 * @property int|null $decidedBy
 * @property string|null $rejectNote
 * @property string|null $scheduledFor
 * @property string|null $appliedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class DraftWorkflowRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%craftdelta_draft_workflows}}';
    }
}
