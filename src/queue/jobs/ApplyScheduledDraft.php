<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\queue\jobs;

use craft\queue\BaseJob;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\models\DraftWorkflow;
use zeixcom\craftdelta\records\DraftWorkflowRecord;

/**
 * Applies a scheduled approved draft to its canonical entry. Re-validates
 * state before applying so manual changes or cancellations cause a no-op.
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
