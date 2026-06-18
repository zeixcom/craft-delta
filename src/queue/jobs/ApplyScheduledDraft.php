<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\queue\jobs;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\queue\BaseJob;
use DateTime;
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
        if ($record === null
            || $record->state !== Review::STATE_APPROVED
            || $record->appliedAt !== null
            || $record->scheduledFor === null
        ) {
            return;
        }

        // Any blocking transition clears scheduledFor; null or not-yet-due means
        // this message is stale and must not publish.
        $due = DateTimeHelper::toDateTime($record->scheduledFor);
        if (!$due instanceof DateTime || $due->getTimestamp() > time() + 30) {
            return;
        }

        $plugin = Craft::$app->getPlugins()->getPlugin('craft-delta');
        if (!$plugin instanceof Delta) {
            return;
        }

        $review = $plugin->workflow->getById($this->reviewId);
        if ($review !== null) {
            $plugin->workflow->applyDraftNow($review);
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Applying scheduled draft (Craft Delta)';
    }
}
