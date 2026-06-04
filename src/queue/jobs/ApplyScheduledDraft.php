<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\queue\jobs;

use craft\queue\BaseJob;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\models\DraftWorkflow;
use zeixcom\craftdelta\records\DraftWorkflowRecord;

/**
 * Applies a scheduled approved draft to its canonical entry.
 *
 * The early-out checks below skip cancelled/already-applied workflows; the
 * authoritative guard against double-applying is the atomic claim inside
 * WorkflowService::applyDraftNow() (a conditional UPDATE on appliedAt), which
 * serialises this job against a concurrent manual apply or a retried message.
 * Note: this publishes whatever the draft contains at run time — by design,
 * the draft is not locked after approval.
 */
class ApplyScheduledDraft extends BaseJob
{
    public int $workflowId;

    public function execute($queue): void
    {
        $record = DraftWorkflowRecord::findOne(['id' => $this->workflowId]);
        if ($record === null) {
            return;
        }
        if ($record->state !== DraftWorkflow::STATE_APPROVED) {
            return;
        }
        if ($record->appliedAt !== null) {
            return;
        }

        $plugin = Delta::getInstance();
        $wf = $plugin->workflow->getByDraftId($record->draftId);
        if ($wf === null) {
            return;
        }

        $plugin->workflow->applyDraftNow($wf);
    }

    protected function defaultDescription(): ?string
    {
        return 'Applying scheduled draft (Craft Delta)';
    }
}
