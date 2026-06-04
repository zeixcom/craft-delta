<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\models;

use craft\base\Model;
use DateTime;

/**
 * Workflow state for a draft. State machine:
 *
 *   (no row)   --submit-->   pending
 *   pending    --approve-->  approved (optionally scheduled)
 *   pending    --reject -->  rejected (terminal)
 *
 * Both `approved` and `rejected` are terminal; rejected drafts are preserved
 * but cannot be re-submitted (author must duplicate the draft).
 */
class DraftWorkflow extends Model
{
    public const STATE_PENDING = 'pending';
    public const STATE_APPROVED = 'approved';
    public const STATE_REJECTED = 'rejected';

    public ?int $id = null;
    public int $draftId = 0;
    public int $canonicalEntryId = 0;
    public string $sectionUid = '';
    public string $state = self::STATE_PENDING;
    public int $submittedBy = 0;
    public ?int $assigneeId = null;
    public ?int $decidedBy = null;
    public ?string $rejectNote = null;
    public ?DateTime $scheduledFor = null;
    public ?DateTime $appliedAt = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function isScheduled(): bool
    {
        return $this->state === self::STATE_APPROVED
            && $this->scheduledFor !== null
            && $this->appliedAt === null;
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, [self::STATE_APPROVED, self::STATE_REJECTED], true);
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    /**
     * Human-readable label for the current state. Single source of truth for
     * the entry-index column pill and the sidebar status pill.
     */
    public function statusLabel(): string
    {
        return match ($this->state) {
            self::STATE_PENDING => \Craft::t('craft-delta', 'Pending review'),
            self::STATE_APPROVED => $this->isScheduled()
                ? \Craft::t('craft-delta', 'Approved — scheduled')
                : \Craft::t('craft-delta', 'Approved'),
            self::STATE_REJECTED => \Craft::t('craft-delta', 'Rejected'),
            default => $this->state,
        };
    }
}
