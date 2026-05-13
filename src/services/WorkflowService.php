<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use DateTime;
use yii\base\InvalidArgumentException;
use yii\web\ForbiddenHttpException;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\events\WorkflowEvent;
use zeixcom\craftdelta\models\DraftWorkflow;
use zeixcom\craftdelta\queue\jobs\ApplyScheduledDraft;
use zeixcom\craftdelta\records\DraftWorkflowRecord;

/**
 * Owns the submit-for-review state machine and the only writer of the
 * craftdelta_draft_workflows table. Controllers stay thin and delegate here.
 */
class WorkflowService extends Component
{
    public const EVENT_AFTER_SUBMIT = 'afterSubmit';
    public const EVENT_AFTER_APPROVE = 'afterApprove';
    public const EVENT_AFTER_REJECT = 'afterReject';

    private const TRANSITIONS = [
        DraftWorkflow::STATE_PENDING => [
            DraftWorkflow::STATE_APPROVED,
            DraftWorkflow::STATE_REJECTED,
        ],
        DraftWorkflow::STATE_APPROVED => [],
        DraftWorkflow::STATE_REJECTED => [],
    ];

    public static function isTransitionAllowed(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function getByDraftId(int $draftId): ?DraftWorkflow
    {
        $record = DraftWorkflowRecord::findOne(['draftId' => $draftId]);
        return $record ? $this->modelFromRecord($record) : null;
    }

    public function getById(int $id): ?DraftWorkflow
    {
        $record = DraftWorkflowRecord::findOne(['id' => $id]);
        return $record ? $this->modelFromRecord($record) : null;
    }

    public function getByDraftIdOrId(int $idOrDraftId): ?DraftWorkflow
    {
        return $this->getById($idOrDraftId) ?? $this->getByDraftId($idOrDraftId);
    }

    public function canSubmit(User $user, Entry $draft): bool
    {
        if (!$draft->getIsDraft()) {
            return false;
        }
        $section = $draft->getSection();
        if ($section === null) {
            return false;
        }
        $draftBehavior = $draft->getBehavior('draft');
        $creatorId = $draftBehavior?->creatorId;
        if ($creatorId !== null && $creatorId !== $user->id && !$user->admin) {
            return false;
        }
        return $user->can("craftdelta-submitDraft:{$section->uid}");
    }

    public function canReview(User $user, DraftWorkflow $wf): bool
    {
        if ($user->admin) {
            return true;
        }
        if ($wf->assigneeId !== $user->id) {
            return false;
        }
        return $user->can("craftdelta-reviewDraft:{$wf->sectionUid}");
    }

    public function getEligibleAssignees(string $sectionUid, ?int $excludeUserId = null): array
    {
        $users = User::find()
            ->status(User::STATUS_ACTIVE)
            ->can("craftdelta-reviewDraft:{$sectionUid}")
            ->orderBy(['fullName' => SORT_ASC])
            ->all();

        if ($excludeUserId !== null) {
            $users = array_values(array_filter($users, fn($u) => $u->id !== $excludeUserId));
        }

        return $users;
    }

    public function submit(Entry $draft, int $assigneeId, User $submittedBy): DraftWorkflow
    {
        if (!$draft->getIsDraft()) {
            throw new InvalidArgumentException('Submit requires a draft entry.');
        }
        $section = $draft->getSection();
        if ($section === null) {
            throw new InvalidArgumentException('Draft has no section.');
        }

        $existing = $this->getByDraftId($draft->draftId);
        if ($existing !== null) {
            throw new InvalidArgumentException('A workflow already exists for this draft.');
        }

        $record = new DraftWorkflowRecord();
        $record->draftId = $draft->draftId;
        $record->canonicalEntryId = $draft->getCanonicalId();
        $record->sectionUid = $section->uid;
        $record->state = DraftWorkflow::STATE_PENDING;
        $record->submittedBy = $submittedBy->id;
        $record->assigneeId = $assigneeId;
        $record->save(false);

        $wf = $this->modelFromRecord($record);

        Delta::getInstance()->email->sendSubmitted($wf, $draft);

        $this->trigger(self::EVENT_AFTER_SUBMIT, new WorkflowEvent(['workflow' => $wf]));

        return $wf;
    }

    public function approveWholesale(DraftWorkflow $wf, ?DateTime $scheduledFor, User $reviewer): void
    {
        $this->assertTransition($wf->state, DraftWorkflow::STATE_APPROVED);

        $record = DraftWorkflowRecord::findOne(['id' => $wf->id]);
        if ($record === null) {
            throw new InvalidArgumentException('Workflow not found.');
        }

        $record->state = DraftWorkflow::STATE_APPROVED;
        $record->decidedBy = $reviewer->id;
        $record->scheduledFor = $scheduledFor ? Db::prepareDateForDb($scheduledFor) : null;
        $record->save(false);

        $wf = $this->modelFromRecord($record);

        if ($scheduledFor === null) {
            $this->applyDraftNow($wf);
        } else {
            Craft::$app->getQueue()->delay(max(0, $scheduledFor->getTimestamp() - time()))
                ->push(new ApplyScheduledDraft(['workflowId' => $wf->id]));
        }

        $draft = Craft::$app->getEntries()->getEntryById($wf->draftId, '*', ['drafts' => true]);
        if ($draft) {
            Delta::getInstance()->email->sendApproved($wf, $draft);
        }

        $this->trigger(self::EVENT_AFTER_APPROVE, new WorkflowEvent(['workflow' => $wf]));
    }

    public function approveGranular(DraftWorkflow $wf, array $acceptedFieldHandles, User $reviewer): void
    {
        $this->assertTransition($wf->state, DraftWorkflow::STATE_APPROVED);

        $record = DraftWorkflowRecord::findOne(['id' => $wf->id]);
        if ($record === null) {
            throw new InvalidArgumentException('Workflow not found.');
        }

        $record->state = DraftWorkflow::STATE_APPROVED;
        $record->decidedBy = $reviewer->id;
        $record->appliedAt = Db::prepareDateForDb(new DateTime());
        $record->save(false);

        $wf = $this->modelFromRecord($record);

        $draft = Craft::$app->getEntries()->getEntryById($wf->draftId, '*', ['drafts' => true]);
        if ($draft) {
            Delta::getInstance()->email->sendApproved($wf, $draft);
        }

        $this->trigger(self::EVENT_AFTER_APPROVE, new WorkflowEvent(['workflow' => $wf]));
    }

    public function reject(DraftWorkflow $wf, ?string $note, User $reviewer): void
    {
        $this->assertTransition($wf->state, DraftWorkflow::STATE_REJECTED);

        $record = DraftWorkflowRecord::findOne(['id' => $wf->id]);
        if ($record === null) {
            throw new InvalidArgumentException('Workflow not found.');
        }

        $record->state = DraftWorkflow::STATE_REJECTED;
        $record->decidedBy = $reviewer->id;
        $record->rejectNote = $note;
        $record->save(false);

        $wf = $this->modelFromRecord($record);

        $draft = Craft::$app->getEntries()->getEntryById($wf->draftId, '*', ['drafts' => true]);
        if ($draft) {
            Delta::getInstance()->email->sendRejected($wf, $draft);
        }

        $this->trigger(self::EVENT_AFTER_REJECT, new WorkflowEvent(['workflow' => $wf]));
    }

    public function applyDraftNow(DraftWorkflow $wf): void
    {
        $draft = Craft::$app->getEntries()->getEntryById($wf->draftId, '*', ['drafts' => true]);
        if ($draft === null) {
            throw new InvalidArgumentException('Draft no longer exists.');
        }

        Craft::$app->getDrafts()->applyDraft($draft);

        $record = DraftWorkflowRecord::findOne(['id' => $wf->id]);
        if ($record !== null) {
            $record->appliedAt = Db::prepareDateForDb(new DateTime());
            $record->scheduledFor = null;
            $record->save(false);
        }
    }

    private function assertTransition(string $from, string $to): void
    {
        if (!self::isTransitionAllowed($from, $to)) {
            throw new ForbiddenHttpException("Illegal transition: {$from} → {$to}");
        }
    }

    private function modelFromRecord(DraftWorkflowRecord $record): DraftWorkflow
    {
        return new DraftWorkflow([
            'id' => $record->id,
            'draftId' => $record->draftId,
            'canonicalEntryId' => $record->canonicalEntryId,
            'sectionUid' => $record->sectionUid,
            'state' => $record->state,
            'submittedBy' => $record->submittedBy,
            'assigneeId' => $record->assigneeId,
            'decidedBy' => $record->decidedBy,
            'rejectNote' => $record->rejectNote,
            'scheduledFor' => $record->scheduledFor ? new DateTime($record->scheduledFor) : null,
            'appliedAt' => $record->appliedAt ? new DateTime($record->appliedAt) : null,
            'dateCreated' => $record->dateCreated ? new DateTime($record->dateCreated) : null,
            'dateUpdated' => $record->dateUpdated ? new DateTime($record->dateUpdated) : null,
            'uid' => $record->uid,
        ]);
    }
}
