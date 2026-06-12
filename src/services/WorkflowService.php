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
use zeixcom\craftdelta\enums\ReviewState;
use zeixcom\craftdelta\events\WorkflowEvent;
use zeixcom\craftdelta\helpers\DbDate;
use zeixcom\craftdelta\helpers\UserName;
use zeixcom\craftdelta\i18n\TranslationKeys;
use zeixcom\craftdelta\models\Review;
use zeixcom\craftdelta\models\ReviewReviewer;
use zeixcom\craftdelta\queue\jobs\ApplyScheduledDraft;
use zeixcom\craftdelta\records\ReviewRecord;
use zeixcom\craftdelta\records\ReviewReviewerRecord;

/**
 * Owns the review-request state machine and is the only writer of the
 * craftdelta_reviews / craftdelta_review_reviewers tables. Controllers stay thin
 * and delegate here.
 *
 * `reviews.state` is a CACHE of a derivation over the current round's reviewer
 * verdicts (see deriveState). declined / cancelled / published are explicit,
 * set by an action — never derived.
 *
 * @phpstan-import-type ReviewBuckets from \zeixcom\craftdelta\types\ArrayTypes
 */
class WorkflowService extends Component
{
    public const EVENT_AFTER_SUBMIT = 'afterSubmit';
    public const EVENT_AFTER_APPROVE = 'afterApprove';
    public const EVENT_AFTER_CHANGES_REQUESTED = 'afterChangesRequested';
    public const EVENT_AFTER_DECLINE = 'afterDecline';
    public const EVENT_AFTER_REREQUEST = 'afterReRequest';
    public const EVENT_AFTER_WITHDRAW = 'afterWithdraw';
    public const EVENT_AFTER_PUBLISH = 'afterPublish';

    /** @var list<string> States from which the cached state may still be re-derived from verdicts. */
    private const DERIVABLE_STATES = [
        ReviewState::Open->value,
        ReviewState::ChangesRequested->value,
        ReviewState::Approved->value,
    ];

    /**
     * Derive a review's overall state from its current round's reviewer verdicts.
     * Pure + static so the precedence rule is unit-testable without a kernel.
     *
     * Precedence: any "changes requested" blocks; else any "approved" passes;
     * else still open.
     *
     * @param string[] $verdicts Current-round verdict strings.
     */
    public static function deriveState(array $verdicts): string
    {
        if (in_array(ReviewReviewer::VERDICT_CHANGES_REQUESTED, $verdicts, true)) {
            return Review::STATE_CHANGES_REQUESTED;
        }
        if (in_array(ReviewReviewer::VERDICT_APPROVED, $verdicts, true)) {
            return Review::STATE_APPROVED;
        }
        return Review::STATE_OPEN;
    }

    public function getByDraftId(int $draftId): ?Review
    {
        $record = ReviewRecord::findOne(['draftId' => $draftId]);
        return $record ? $this->modelFromRecord($record) : null;
    }

    public function getById(int $id): ?Review
    {
        $record = ReviewRecord::findOne(['id' => $id]);
        return $record ? $this->modelFromRecord($record) : null;
    }

    /**
     * Reviews for the CP dashboard, grouped by the current user's relationship:
     * 'assigned' (I'm a current-round reviewer and it still needs me),
     * 'submitted' (I opened it), and 'all' (admins only).
     *
     * @return ReviewBuckets
     */
    public function getReviewsForDashboard(User $user): array
    {
        $submitted = array_map(
            fn(ReviewRecord $r) => $this->modelFromRecord($r),
            ReviewRecord::find()->where(['submittedBy' => $user->id])->orderBy(['dateUpdated' => SORT_DESC])->all(),
        );

        $assigned = [];
        $seen = [];
        /** @var ReviewReviewerRecord[] $rows */
        $rows = ReviewReviewerRecord::find()->where(['userId' => $user->id])->orderBy(['id' => SORT_DESC])->all();
        foreach ($rows as $row) {
            if (isset($seen[$row->reviewId])) {
                continue;
            }
            $review = $this->getById((int)$row->reviewId);
            if ($review !== null && (int)$row->round === $review->round && $review->isActive()) {
                $assigned[] = $review;
                $seen[$row->reviewId] = true;
            }
        }

        $all = [];
        if ($user->admin) {
            $all = array_map(
                fn(ReviewRecord $r) => $this->modelFromRecord($r),
                ReviewRecord::find()->orderBy(['dateUpdated' => SORT_DESC])->limit(200)->all(),
            );
        }

        return ['assigned' => $assigned, 'submitted' => $submitted, 'all' => $all];
    }

    /** Active reviews where the user is a current-round reviewer still pending. */
    public function countAwaitingVerdict(User $user): int
    {
        $count = 0;
        /** @var ReviewReviewerRecord[] $rows */
        $rows = ReviewReviewerRecord::find()
            ->where(['userId' => $user->id, 'verdict' => ReviewReviewer::VERDICT_PENDING])
            ->all();
        foreach ($rows as $row) {
            $review = $this->getById((int)$row->reviewId);
            if ($review !== null && (int)$row->round === $review->round && $review->isActive()) {
                $count++;
            }
        }
        return $count;
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
        /** @var \craft\behaviors\DraftBehavior|null $draftBehavior */
        $draftBehavior = $draft->getBehavior('draft');
        $creatorId = $draftBehavior?->creatorId;
        if ($creatorId !== null && $creatorId !== $user->id && !$user->admin) {
            return false;
        }
        return $user->can(Delta::PERMISSION_SUBMIT);
    }

    public function canReview(User $user, Review $review): bool
    {
        if ($user->admin) {
            return true;
        }
        if (!$user->can(Delta::PERMISSION_REVIEW)) {
            return false;
        }
        foreach ($review->reviewers as $reviewer) {
            if ($reviewer->userId === $user->id) {
                return true;
            }
        }
        return false;
    }

    public function canActAsAuthor(User $user, Review $review): bool
    {
        return $user->admin || $review->submittedBy === $user->id;
    }

    /**
     * @return list<User>
     */
    public function getEligibleAssignees(string $sectionUid, ?int $excludeUserId = null): array
    {
        // Holders of the general review permission who can actually reach this
        // section's drafts. ->can() already returns true for admins.
        $users = User::find()
            ->status(User::STATUS_ACTIVE)
            ->can(Delta::PERMISSION_REVIEW)
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

    /**
     * Open a review on a draft, requesting one or more reviewers.
     *
     * A withdrawn (cancelled, never-applied) review does not block: it is
     * re-opened in place with a new round, so authors can pull a request back,
     * revise, and resubmit the same draft. Declined stays terminal.
     *
     * @param int[] $reviewerIds
     */
    public function submit(Entry $draft, array $reviewerIds, User $submittedBy): Review
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
        if ($existing !== null && !($existing->state === Review::STATE_CANCELLED && $existing->appliedAt === null)) {
            throw new InvalidArgumentException('A review already exists for this draft.');
        }

        // Validate reviewers server-side — the picker is only a hint. Rejects the
        // author (excluded from eligible) and anyone who can't reach the section.
        $eligibleIds = array_map(
            static fn(User $u) => (int)$u->id,
            $this->getEligibleAssignees($section->uid, $submittedBy->id),
        );
        $reviewerIds = array_values(array_unique(array_map('intval', $reviewerIds)));
        if (count($reviewerIds) === 0) {
            throw new InvalidArgumentException('Select at least one reviewer.');
        }
        foreach ($reviewerIds as $rid) {
            if (!in_array($rid, $eligibleIds, true)) {
                throw new InvalidArgumentException('A selected reviewer is not eligible to review drafts in this section.');
            }
        }

        $review = Craft::$app->getDb()->transaction(function() use ($draft, $section, $submittedBy, $reviewerIds, $existing) {
            if ($existing !== null) {
                // Re-open the withdrawn review in place (the unique draftId
                // index forbids a second row). Conditional UPDATE so a
                // concurrent submit/conclude can't double-reopen.
                $round = $existing->round + 1;
                $claimed = ReviewRecord::updateAll(
                    [
                        'state' => Review::STATE_OPEN,
                        'round' => $round,
                        'submittedBy' => $submittedBy->id,
                        'decidedBy' => null,
                        'decisionNote' => null,
                        'scheduledFor' => null,
                        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                    ],
                    ['id' => $existing->id, 'state' => Review::STATE_CANCELLED, 'appliedAt' => null],
                );
                if ($claimed === 0) {
                    throw new InvalidArgumentException('A review already exists for this draft.');
                }
                foreach ($reviewerIds as $rid) {
                    $this->insertReviewer($existing->id, $rid, $round);
                }
                return $this->loadReview($existing->id);
            }

            $record = new ReviewRecord();
            $record->draftId = $draft->draftId;
            $record->canonicalEntryId = $draft->getCanonicalId();
            $record->sectionUid = $section->uid;
            $record->state = Review::STATE_OPEN;
            $record->round = 1;
            $record->submittedBy = $submittedBy->id;
            if (!$record->save(false)) {
                throw new InvalidArgumentException('Failed to persist review.');
            }
            foreach ($reviewerIds as $rid) {
                $this->insertReviewer($record->id, $rid, 1);
            }
            return $this->modelFromRecord($record);
        });

        $this->notifyReviewers($review, fn(Review $r, Entry $draft, int $reviewerUserId) =>
            Delta::getInstance()->email->sendSubmitted($r, $draft, $reviewerUserId));

        $this->trigger(self::EVENT_AFTER_SUBMIT, new WorkflowEvent(['review' => $review]));

        return $review;
    }

    /** A reviewer approves the current round. */
    public function approve(Review $review, User $reviewer): Review
    {
        $fresh = $this->recordVerdict($review, $reviewer, ReviewReviewer::VERDICT_APPROVED, null);
        $this->trigger(self::EVENT_AFTER_APPROVE, new WorkflowEvent(['review' => $fresh]));
        return $fresh;
    }

    /** A reviewer asks for changes (the iterate loop). */
    public function requestChanges(Review $review, User $reviewer, ?string $note): Review
    {
        $fresh = $this->recordVerdict($review, $reviewer, ReviewReviewer::VERDICT_CHANGES_REQUESTED, $note);
        $this->notifyAuthor($fresh, fn(Entry $draft, User $author) =>
            Delta::getInstance()->email->sendChangesRequested($fresh, $draft, $author, $note));
        $this->trigger(self::EVENT_AFTER_CHANGES_REQUESTED, new WorkflowEvent(['review' => $fresh]));
        return $fresh;
    }

    /** A reviewer declines outright (terminal hard-no). */
    public function decline(Review $review, User $reviewer, ?string $note): Review
    {
        $this->assertCanReview($reviewer, $review);

        $fresh = $this->transition(
            $review->id,
            [Review::STATE_OPEN, Review::STATE_CHANGES_REQUESTED, Review::STATE_APPROVED],
            Review::STATE_DECLINED,
            ['decidedBy' => $reviewer->id, 'decisionNote' => $note, 'scheduledFor' => null],
        );

        $this->notifyAuthor($fresh, fn(Entry $draft, User $author) =>
            Delta::getInstance()->email->sendDeclined($fresh, $draft, $author, $note));
        $this->trigger(self::EVENT_AFTER_DECLINE, new WorkflowEvent(['review' => $fresh]));
        return $fresh;
    }

    /** The author revised and re-requests review (changes_requested → open, round++). */
    public function reRequest(Review $review, User $author): Review
    {
        $this->assertCanActAsAuthor($author, $review);

        $reopened = Craft::$app->getDb()->transaction(function() use ($review) {
            $next = $this->transition(
                $review->id,
                [Review::STATE_CHANGES_REQUESTED],
                Review::STATE_OPEN,
                ['round' => $review->round + 1, 'scheduledFor' => null],
            );

            // Carry the reviewer set into the new round, reset to pending.
            /** @var ReviewReviewerRecord[] $previous */
            $previous = ReviewReviewerRecord::find()
                ->where(['reviewId' => $review->id, 'round' => $review->round])
                ->all();
            foreach ($previous as $row) {
                $this->insertReviewer($review->id, (int)$row->userId, $next->round);
            }

            return $next;
        });

        $fresh = $this->loadReview($reopened->id);
        $this->notifyReviewers($fresh, fn(Review $r, Entry $draft, int $reviewerUserId) =>
            Delta::getInstance()->email->sendReRequested($r, $draft, $reviewerUserId));
        $this->trigger(self::EVENT_AFTER_REREQUEST, new WorkflowEvent(['review' => $fresh]));
        return $fresh;
    }

    /** The author withdraws the request (terminal). */
    public function withdraw(Review $review, User $user): Review
    {
        $this->assertCanActAsAuthor($user, $review);

        $fresh = $this->transition(
            $review->id,
            [Review::STATE_OPEN, Review::STATE_CHANGES_REQUESTED, Review::STATE_APPROVED],
            Review::STATE_CANCELLED,
            ['decidedBy' => $user->id, 'scheduledFor' => null],
        );

        $this->trigger(self::EVENT_AFTER_WITHDRAW, new WorkflowEvent(['review' => $fresh]));
        return $fresh;
    }

    /**
     * Publish an approved review wholesale, now or scheduled. The caller
     * (controller) is responsible for the native canSave permission check.
     */
    public function publish(Review $review, ?DateTime $scheduledFor, User $publisher): Review
    {
        if (!$review->isApproved()) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::ONLY_APPROVED_REVIEW_CAN_PUBLISH));
        }

        if ($scheduledFor === null) {
            // applyDraftNow() publishes and fires the publish notification + event
            // on success — don't notify again here.
            $this->applyDraftNow($review);
            return $this->loadReview($review->id);
        }

        // Keep state approved (scheduled) and queue the apply.
        $this->transition(
            $review->id,
            [Review::STATE_APPROVED],
            Review::STATE_APPROVED,
            ['scheduledFor' => Db::prepareDateForDb($scheduledFor), 'decidedBy' => $publisher->id],
        );
        try {
            Craft::$app->getQueue()->delay(max(0, $scheduledFor->getTimestamp() - time()))
                ->push(new ApplyScheduledDraft(['reviewId' => $review->id]));
        } catch (\Throwable $e) {
            // The schedule is committed but the job push failed; clear it so
            // the review isn't stranded "scheduled" with nothing to apply it.
            ReviewRecord::updateAll(
                ['scheduledFor' => null],
                ['id' => $review->id, 'state' => Review::STATE_APPROVED, 'appliedAt' => null],
            );
            throw $e;
        }

        // Notify "approved — scheduled" now; the publish notification + event
        // fire when the queue job runs applyDraftNow().
        $fresh = $this->loadReview($review->id);
        $this->notifyAuthor($fresh, fn(Entry $draft, User $author) =>
            Delta::getInstance()->email->sendPublished($fresh, $draft, $author));
        return $fresh;
    }

    /**
     * Close a review because the reviewer resolved it through Review Mode's
     * granular apply. MergeService has ALREADY published the accepted atoms, so
     * this performs no publish of its own — it just marks the review published.
     */
    public function resolveByReview(Review $review, User $reviewer): void
    {
        $this->assertCanReview($reviewer, $review);

        $now = Db::prepareDateForDb(new DateTime());
        $claimed = ReviewRecord::updateAll(
            [
                'state' => Review::STATE_PUBLISHED,
                'appliedAt' => $now,
                'scheduledFor' => null,
                'decidedBy' => $reviewer->id,
                'dateUpdated' => $now,
            ],
            ['id' => $review->id, 'appliedAt' => null],
        );
        if ($claimed === 0) {
            return; // already concluded by another process
        }

        $this->notifyPublished($this->loadReview($review->id));
    }

    /**
     * Atomically claim and publish the draft to canonical. Used by publish()
     * (immediate) and by the scheduled queue job. The conditional UPDATE on
     * appliedAt is the lock that prevents a double-apply.
     */
    public function applyDraftNow(Review $review): void
    {
        $originalScheduledFor = $review->scheduledFor;

        $now = Db::prepareDateForDb(new DateTime());
        $claimed = ReviewRecord::updateAll(
            ['appliedAt' => $now, 'state' => Review::STATE_PUBLISHED, 'scheduledFor' => null, 'dateUpdated' => $now],
            ['id' => $review->id, 'appliedAt' => null],
        );
        if ($claimed === 0) {
            return; // already applied by another process
        }

        $draft = $this->getDraftEntry($review->draftId);
        if ($draft === null) {
            return; // draft gone; claim already marks it published
        }

        try {
            Craft::$app->getDrafts()->applyDraft($draft);
        } catch (\Throwable $e) {
            // Release the claim and restore the schedule so a retry re-applies.
            ReviewRecord::updateAll(
                [
                    'appliedAt' => null,
                    'state' => Review::STATE_APPROVED,
                    'scheduledFor' => $originalScheduledFor ? Db::prepareDateForDb($originalScheduledFor) : null,
                ],
                ['id' => $review->id],
            );
            throw $e;
        }

        // Published — notify the author and fire the event, exactly once per
        // actual publish (immediate or via the scheduled queue job).
        $this->notifyPublished($this->loadReview($review->id));
    }

    /**
     * Cancel any still-active review when its draft is deleted outside the
     * workflow (author discards the draft, admin cleanup, …). Must be called
     * from a BEFORE-delete listener, while reviews.draftId still points at the
     * draft — after deletion the FK SET NULLs it, stranding the row as a zombie
     * "open" review in every reviewer's dashboard. Publish-flow deletions are
     * naturally excluded: they set appliedAt before the draft is removed.
     */
    public function cancelForDeletedDraft(int $draftId): void
    {
        ReviewRecord::updateAll(
            [
                'state' => Review::STATE_CANCELLED,
                'scheduledFor' => null,
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            ],
            ['draftId' => $draftId, 'appliedAt' => null, 'state' => self::DERIVABLE_STATES],
        );
    }

    /**
     * Atomically move a review from one of `$fromStates` to `$to`, writing the
     * extra columns in the same conditional UPDATE. The `state IN (...)`
     * predicate is the lock: a concurrent transition that already moved the row
     * yields zero affected rows and aborts.
     *
     * @param string[] $fromStates
     * @param array<string, mixed> $attrs
     */
    private function transition(int $id, array $fromStates, string $to, array $attrs = []): Review
    {
        $attrs['state'] = $to;
        $attrs['dateUpdated'] = Db::prepareDateForDb(new DateTime());

        $claimed = ReviewRecord::updateAll($attrs, ['id' => $id, 'state' => $fromStates]);
        if ($claimed === 0) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::REVIEW_ALREADY_MOVED_ON));
        }

        return $this->loadReview($id);
    }

    /** Recompute and persist the cached state from the current round's verdicts. */
    private function recomputeState(int $reviewId, int $round): void
    {
        $verdicts = ReviewReviewerRecord::find()
            ->select(['verdict'])
            ->where(['reviewId' => $reviewId, 'round' => $round])
            ->column();

        $state = self::deriveState($verdicts);
        $attrs = ['state' => $state, 'dateUpdated' => Db::prepareDateForDb(new DateTime())];
        if ($state !== Review::STATE_APPROVED) {
            // Leaving "approved" rescinds any pending schedule, so a queued
            // ApplyScheduledDraft job from the old approval can never publish a
            // draft whose approval was taken back (the job requires a non-null,
            // due scheduledFor).
            $attrs['scheduledFor'] = null;
        }

        ReviewRecord::updateAll($attrs, ['id' => $reviewId, 'state' => self::DERIVABLE_STATES]);
    }

    /** Upsert a reviewer's verdict for the current round. */
    private function writeVerdict(int $reviewId, int $round, int $userId, string $verdict, ?string $note): void
    {
        $record = ReviewReviewerRecord::findOne(['reviewId' => $reviewId, 'userId' => $userId, 'round' => $round]);
        if ($record === null) {
            // An admin acting without being on the requested set joins the round.
            $record = new ReviewReviewerRecord();
            $record->reviewId = $reviewId;
            $record->userId = $userId;
            $record->round = $round;
        }
        $record->verdict = $verdict;
        $record->note = $note;
        $record->decidedAt = Db::prepareDateForDb(new DateTime());
        if (!$record->save(false)) {
            throw new InvalidArgumentException('Failed to persist reviewer verdict.');
        }
    }

    private function insertReviewer(int $reviewId, int $userId, int $round): void
    {
        $record = new ReviewReviewerRecord();
        $record->reviewId = $reviewId;
        $record->userId = $userId;
        $record->round = $round;
        $record->verdict = ReviewReviewer::VERDICT_PENDING;
        if (!$record->save(false)) {
            throw new InvalidArgumentException('Failed to persist reviewer.');
        }
    }

    /**
     * Resolve the review's draft entry and submitting author, then run the
     * callback. Used for author-facing notifications; a no-op if either is gone.
     *
     * @param callable(Entry, User): void $callback
     */
    private function notifyAuthor(Review $review, callable $callback): void
    {
        $draft = $this->getDraftEntry($review->draftId);
        $author = Craft::$app->getUsers()->getUserById($review->submittedBy);
        if ($draft !== null && $author !== null) {
            $callback($draft, $author);
        }
    }

    private function notifyPublished(Review $review): void
    {
        // After a wholesale publish the draft is gone (applyDraft deletes it),
        // so fall back to the canonical entry for the notification link/title.
        $entry = $this->getDraftEntry($review->draftId)
            ?? Craft::$app->getEntries()->getEntryById($review->canonicalEntryId);
        $author = Craft::$app->getUsers()->getUserById($review->submittedBy);
        if ($entry !== null && $author !== null) {
            Delta::getInstance()->email->sendPublished($review, $entry, $author);
        }
        $this->trigger(self::EVENT_AFTER_PUBLISH, new WorkflowEvent(['review' => $review]));
    }

    private function getDraftEntry(?int $draftId): ?Entry
    {
        if ($draftId === null) {
            return null;
        }
        return Delta::getInstance()->revision->getDraftByDraftId($draftId);
    }

    /**
     * @param callable(Review, Entry, int): void $send
     */
    private function notifyReviewers(Review $review, callable $send): void
    {
        $draftEntry = $this->getDraftEntry($review->draftId);
        if ($draftEntry === null) {
            return;
        }
        foreach ($review->reviewers as $reviewer) {
            $send($review, $draftEntry, $reviewer->userId);
        }
    }

    private function recordVerdict(Review $review, User $reviewer, string $verdict, ?string $note): Review
    {
        $this->assertCanReview($reviewer, $review);
        $this->assertActive($review);

        Craft::$app->getDb()->transaction(function() use ($review, $reviewer, $verdict, $note) {
            $this->writeVerdict($review->id, $review->round, $reviewer->id, $verdict, $note);
            $this->recomputeState($review->id, $review->round);
        });

        return $this->loadReview($review->id);
    }

    private function assertCanSubmit(User $user, Entry $draft): void
    {
        if (!$this->canSubmit($user, $draft)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NO_PERMISSION_SUBMIT_SECTION));
        }
    }

    private function assertCanReview(User $reviewer, Review $review): void
    {
        if (!$this->canReview($reviewer, $review)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_REVIEWER_FOR_DRAFT));
        }
    }

    private function assertCanActAsAuthor(User $user, Review $review): void
    {
        if (!$this->canActAsAuthor($user, $review)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::ONLY_AUTHOR_CAN_DO_THAT));
        }
    }

    private function assertActive(Review $review): void
    {
        if (!$review->isActive()) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::REVIEW_NO_LONGER_OPEN));
        }
    }

    private function loadReview(int $id): Review
    {
        $record = ReviewRecord::findOne(['id' => $id]);
        if ($record === null) {
            throw new InvalidArgumentException('Review not found.');
        }
        return $this->modelFromRecord($record);
    }

    private function modelFromRecord(ReviewRecord $record): Review
    {
        $review = new Review([
            'id' => $record->id,
            'draftId' => $record->draftId,
            'canonicalEntryId' => $record->canonicalEntryId,
            'sectionUid' => $record->sectionUid,
            'state' => $record->state,
            'round' => (int)$record->round,
            'submittedBy' => $record->submittedBy,
            'decidedBy' => $record->decidedBy,
            'decisionNote' => $record->decisionNote,
            'scheduledFor' => DbDate::parse($record->scheduledFor),
            'appliedAt' => DbDate::parse($record->appliedAt),
            'dateCreated' => DbDate::parse($record->dateCreated),
            'dateUpdated' => DbDate::parse($record->dateUpdated),
            'uid' => $record->uid,
        ]);
        $review->reviewers = $this->loadReviewers((int)$record->id, (int)$record->round);
        return $review;
    }

    /**
     * Load the current round's reviewer rows as models, with display names.
     *
     * @return ReviewReviewer[]
     */
    private function loadReviewers(int $reviewId, int $round): array
    {
        /** @var ReviewReviewerRecord[] $records */
        $records = ReviewReviewerRecord::find()
            ->where(['reviewId' => $reviewId, 'round' => $round])
            ->all();

        $models = [];
        foreach ($records as $record) {
            $model = new ReviewReviewer([
                'id' => $record->id,
                'reviewId' => $record->reviewId,
                'userId' => $record->userId,
                'round' => (int)$record->round,
                'verdict' => $record->verdict,
                'note' => $record->note,
                'decidedAt' => DbDate::parse($record->decidedAt),
                'dateCreated' => DbDate::parse($record->dateCreated),
                'dateUpdated' => DbDate::parse($record->dateUpdated),
                'uid' => $record->uid,
            ]);
            $user = Craft::$app->getUsers()->getUserById((int)$record->userId);
            $model->userName = UserName::of($user);
            $models[] = $model;
        }

        return $models;
    }

}
