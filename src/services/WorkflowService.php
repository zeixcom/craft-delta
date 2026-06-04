<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\DateTimeHelper;
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
        return $user->can('craftdelta-submitDraft');
    }

    public function canReview(User $user, DraftWorkflow $wf): bool
    {
        if ($user->admin) {
            return true;
        }
        if ($wf->assigneeId !== $user->id) {
            return false;
        }
        return $user->can('craftdelta-reviewDraft');
    }

    public function getEligibleAssignees(string $sectionUid, ?int $excludeUserId = null): array
    {
        // Holders of the general review permission. Section visibility is no
        // longer encoded in the permission itself, so we additionally keep only
        // reviewers who can actually reach this section's drafts — otherwise an
        // assignee could be picked but hit a 403 when opening the draft.
        // ->can() already returns true for admins, so they are never filtered out.
        $users = User::find()
            ->status(User::STATUS_ACTIVE)
            ->can('craftdelta-reviewDraft')
            ->orderBy(['fullName' => SORT_ASC])
            ->all();

        $users = array_values(array_filter(
            $users,
            fn($u) => $u->can("viewPeerEntryDrafts:{$sectionUid}")
        ));

        if ($excludeUserId !== null) {
            $users = array_values(array_filter($users, fn($u) => $u->id !== $excludeUserId));
        }

        return $users;
    }

    public function submit(Entry $draft, int $assigneeId, User $submittedBy): DraftWorkflow
    {
        $this->assertCanSubmit($submittedBy, $draft);

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

        // Validate the assignee server-side — the dropdown is only a hint. This
        // rejects self-assignment (the submitter is excluded) and any reviewer
        // who can't actually review this section's drafts, preventing both a
        // separation-of-duties bypass and workflows stranded on an ineligible
        // assignee who can never pass canReview().
        $eligibleIds = array_map(
            static fn(User $u) => (int)$u->id,
            $this->getEligibleAssignees($section->uid, $submittedBy->id),
        );
        if (!in_array($assigneeId, $eligibleIds, true)) {
            throw new InvalidArgumentException('The selected reviewer is not eligible to review drafts in this section.');
        }

        $record = new DraftWorkflowRecord();
        $record->draftId = $draft->draftId;
        $record->canonicalEntryId = $draft->getCanonicalId();
        $record->sectionUid = $section->uid;
        $record->state = DraftWorkflow::STATE_PENDING;
        $record->submittedBy = $submittedBy->id;
        $record->assigneeId = $assigneeId;
        if (!$record->save(false)) {
            throw new InvalidArgumentException('Failed to persist workflow submit state.');
        }

        $wf = $this->modelFromRecord($record);

        Delta::getInstance()->email->sendSubmitted($wf, $draft);

        $this->trigger(self::EVENT_AFTER_SUBMIT, new WorkflowEvent(['workflow' => $wf]));

        return $wf;
    }

    public function approveWholesale(DraftWorkflow $wf, ?DateTime $scheduledFor, User $reviewer): void
    {
        $this->assertCanReview($reviewer, $wf);
        $this->assertTransition($wf->state, DraftWorkflow::STATE_APPROVED);

        $wf = $this->mutateRecord($wf->id, function (DraftWorkflowRecord $record) use ($reviewer, $scheduledFor): void {
            $record->state = DraftWorkflow::STATE_APPROVED;
            $record->decidedBy = $reviewer->id;
            $record->scheduledFor = $scheduledFor ? Db::prepareDateForDb($scheduledFor) : null;
        });

        if ($scheduledFor === null) {
            $this->applyDraftNow($wf);
        } else {
            Craft::$app->getQueue()->delay(max(0, $scheduledFor->getTimestamp() - time()))
                ->push(new ApplyScheduledDraft(['workflowId' => $wf->id]));
        }

        $draft = $this->getDraftEntry($wf->draftId);
        if ($draft) {
            Delta::getInstance()->email->sendApproved($wf, $draft);
        }

        $this->trigger(self::EVENT_AFTER_APPROVE, new WorkflowEvent(['workflow' => $wf]));
    }

    /**
     * Close a pending workflow because the reviewer resolved it through Review
     * Mode's granular apply. By the time this runs, MergeService has ALREADY
     * published the accepted atoms to canonical — so, unlike approveWholesale(),
     * this performs NO publish of its own. It records the decision, stamps
     * appliedAt, and fires the approve event so notifications and third-party
     * integrations still run.
     *
     * The source draft is left as the caller's "delete source draft" option
     * dictated: kept (the rejected changes remain in it as a record of what was
     * declined) or already deleted (which cascade-removes this row, in which
     * case the caller never reaches this method).
     */
    public function resolveByReview(DraftWorkflow $wf, User $reviewer): void
    {
        $this->assertCanReview($reviewer, $wf);
        $this->assertTransition($wf->state, DraftWorkflow::STATE_APPROVED);

        $wf = $this->mutateRecord($wf->id, function (DraftWorkflowRecord $record) use ($reviewer): void {
            $record->state = DraftWorkflow::STATE_APPROVED;
            $record->decidedBy = $reviewer->id;
            $record->appliedAt = Db::prepareDateForDb(new DateTime());
        });

        $draft = $this->getDraftEntry($wf->draftId);
        if ($draft) {
            Delta::getInstance()->email->sendApproved($wf, $draft);
        }

        $this->trigger(self::EVENT_AFTER_APPROVE, new WorkflowEvent(['workflow' => $wf]));
    }

    public function reject(DraftWorkflow $wf, ?string $note, User $reviewer): void
    {
        $this->assertCanReview($reviewer, $wf);
        $this->assertTransition($wf->state, DraftWorkflow::STATE_REJECTED);

        $wf = $this->mutateRecord($wf->id, function (DraftWorkflowRecord $record) use ($reviewer, $note): void {
            $record->state = DraftWorkflow::STATE_REJECTED;
            $record->decidedBy = $reviewer->id;
            $record->rejectNote = $note;
        });

        $draft = $this->getDraftEntry($wf->draftId);
        if ($draft) {
            Delta::getInstance()->email->sendRejected($wf, $draft);
        }

        $this->trigger(self::EVENT_AFTER_REJECT, new WorkflowEvent(['workflow' => $wf]));
    }

    public function applyDraftNow(DraftWorkflow $wf): void
    {
        // Atomically claim the workflow before publishing: only the writer that
        // flips appliedAt from NULL proceeds. This serialises concurrent
        // triggers (e.g. the scheduled queue job firing while an admin also
        // applies, or a retried queue message) so a draft can never be applied
        // twice. The conditional UPDATE is the lock.
        $claimed = DraftWorkflowRecord::updateAll(
            ['appliedAt' => Db::prepareDateForDb(new DateTime()), 'scheduledFor' => null],
            ['id' => $wf->id, 'appliedAt' => null],
        );
        if ($claimed === 0) {
            return; // already applied by another process
        }

        $draft = $this->getDraftEntry($wf->draftId);
        if ($draft === null) {
            // Draft is gone (published/deleted elsewhere). The claim above
            // already marks this workflow applied, so there is nothing to do.
            return;
        }

        try {
            Craft::$app->getDrafts()->applyDraft($draft);
        } catch (\Throwable $e) {
            // Release the claim so the queue can retry the apply.
            DraftWorkflowRecord::updateAll(['appliedAt' => null], ['id' => $wf->id]);
            throw $e;
        }
    }

    private function getDraftEntry(int $draftId): ?Entry
    {
        return Entry::find()
            ->draftId($draftId)
            ->status(null)
            ->one();
    }

    private function assertCanSubmit(User $user, Entry $draft): void
    {
        if (!$this->canSubmit($user, $draft)) {
            throw new ForbiddenHttpException('You do not have permission to submit drafts for this section.');
        }
    }

    private function assertCanReview(User $reviewer, DraftWorkflow $wf): void
    {
        if (!$this->canReview($reviewer, $wf)) {
            throw new ForbiddenHttpException('You are not the assigned reviewer for this draft.');
        }
    }

    private function assertTransition(string $from, string $to): void
    {
        if (!self::isTransitionAllowed($from, $to)) {
            throw new ForbiddenHttpException("Illegal transition: {$from} → {$to}");
        }
    }

    /**
     * Load a workflow record by id, apply a mutation, persist it, and return the
     * refreshed model. Centralises the find / null-guard / save / hydrate
     * boilerplate shared by every state transition.
     *
     * @param callable(DraftWorkflowRecord): void $mutator
     */
    private function mutateRecord(int $id, callable $mutator): DraftWorkflow
    {
        $record = DraftWorkflowRecord::findOne(['id' => $id]);
        if ($record === null) {
            throw new InvalidArgumentException('Workflow not found.');
        }
        $mutator($record);
        if (!$record->save(false)) {
            throw new InvalidArgumentException('Failed to persist workflow state.');
        }
        return $this->modelFromRecord($record);
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
            'scheduledFor' => $this->parseDbDate($record->scheduledFor),
            'appliedAt' => $this->parseDbDate($record->appliedAt),
            'dateCreated' => $this->parseDbDate($record->dateCreated),
            'dateUpdated' => $this->parseDbDate($record->dateUpdated),
            'uid' => $record->uid,
        ]);
    }

    /**
     * Parse a datetime string from the DB into a DateTime. Craft stores
     * datetimes in UTC; a bare `new DateTime($str)` would misread them in the
     * server/user timezone (off by the UTC offset, varying with DST), which
     * then surfaces a wrong time in the approval email. DateTimeHelper pins UTC.
     */
    private function parseDbDate(?string $value): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = DateTimeHelper::toDateTime($value);
        return $date instanceof DateTime ? $date : null;
    }
}
