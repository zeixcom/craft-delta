<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\queue\jobs;

use craft\helpers\DateTimeHelper;
use craft\queue\BaseJob;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\models\Review;
use zeixcom\craftdelta\records\ReviewRecord;

/**
 * Applies a scheduled, approved review's draft to its canonical entry.
 *
 * The early-out checks below skip cancelled/already-applied reviews; the
 * authoritative guard against double-applying is the atomic claim inside
 * WorkflowService::applyDraftNow() (a conditional UPDATE on appliedAt), which
 * serialises this job against a concurrent manual apply or a retried message.
 * Note: this publishes whatever the draft contains at run time — by design,
 * the draft is not locked after approval.
 */
class ApplyScheduledDraft extends BaseJob
{
    public int $reviewId;

    public function execute($queue): void
    {
        $record = ReviewRecord::findOne(['id' => $this->reviewId]);
        if ($record === null) {
            return;
        }
        if ($record->state !== Review::STATE_APPROVED) {
            return;
        }
        if ($record->appliedAt !== null) {
            return;
        }

        // Any blocking transition (changes requested, decline, withdraw,
        // re-request) clears scheduledFor, and rescheduling moves it. A null or
        // not-yet-due schedule means THIS message is stale — the approval it was
        // queued for was rescinded or superseded, so it must not publish even
        // though the review may be "approved" again in a later round.
        if ($record->scheduledFor === null) {
            return;
        }
        $due = DateTimeHelper::toDateTime($record->scheduledFor);
        if (!$due instanceof \DateTime || $due->getTimestamp() > time() + 30) {
            return;
        }

        $plugin = Delta::getInstance();
        $review = $plugin->workflow->getById($this->reviewId);
        if ($review === null) {
            return;
        }

        $plugin->workflow->applyDraftNow($review);
    }

    protected function defaultDescription(): ?string
    {
        return 'Applying scheduled draft (Craft Delta)';
    }
}
