<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\base\InvalidArgumentException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\i18n\TranslationKeys;
use zeixcom\craftdelta\models\Review;
use zeixcom\craftdelta\models\ReviewComment;
use zeixcom\craftdelta\services\MergeService;

/**
 * Endpoints for the review-request workflow. The WorkflowService owns all state;
 * this controller stays thin: resolve params, enforce the native publish gate
 * where relevant, delegate, and shape the JSON response.
 */
class WorkflowController extends Controller
{
    /**
     * Block every workflow endpoint when the feature is switched off, so the
     * `enableWorkflow` setting is a real kill-switch rather than only hiding
     * the sidebar Submit button.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        if (!Delta::getInstance()->getSettings()->enableWorkflow) {
            throw new NotFoundHttpException(Craft::t('craft-delta', TranslationKeys::REVIEW_WORKFLOW_DISABLED));
        }
        return true;
    }

    /** The Reviews dashboard (CP nav landing page). */
    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || (!$user->admin && !$user->can(Delta::PERMISSION_SUBMIT) && !$user->can(Delta::PERMISSION_REVIEW))) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        }

        $data = Delta::getInstance()->workflow->getReviewsForDashboard($user);

        // Enrich each review with its canonical entry's title + edit URL so the
        // template doesn't run element queries.
        $rows = static function(array $reviews): array {
            return array_map(static function(Review $review): array {
                $entry = Craft::$app->getEntries()->getEntryById($review->canonicalEntryId);
                return [
                    'review' => $review,
                    'title' => $entry?->title ?? ('#' . $review->canonicalEntryId),
                    'url' => $entry?->getCpEditUrl(),
                ];
            }, $reviews);
        };

        return $this->renderTemplate('craft-delta/index', [
            'assigned' => $rows($data['assigned']),
            'submitted' => $rows($data['submitted']),
            'all' => $rows($data['all']),
            'isAdmin' => $user->admin,
        ]);
    }

    /** Open a review on a draft, requesting one or more reviewers. */
    public function actionSubmit(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $draftId = (int)$request->getRequiredBodyParam('draftId');
        // Accept a single id or an array; the service validates + dedupes.
        $reviewerIds = (array)$request->getBodyParam('reviewerIds', []);

        // `draftId` is the drafts-table id (matching $entry->draftId everywhere
        // else in this plugin), not an element id.
        $draft = Entry::find()->draftId($draftId)->status(null)->one();
        if (!$draft instanceof Entry || !$draft->getIsDraft()) {
            throw new NotFoundHttpException(Craft::t('craft-delta', TranslationKeys::DRAFT_NOT_FOUND));
        }

        $user = Craft::$app->getUser()->getIdentity();
        $plugin = Delta::getInstance();
        if (!$user || !$plugin->workflow->canSubmit($user, $draft)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NO_PERMISSION_SUBMIT_SECTION));
        }

        try {
            $review = $plugin->workflow->submit($draft, array_map('intval', $reviewerIds), $user);
        } catch (InvalidArgumentException) {
            return $this->failure(TranslationKeys::FAILED_SUBMIT_FOR_REVIEW);
        }

        return $this->asJson(['success' => true, 'review' => $this->reviewPayload($review)]);
    }

    /** A reviewer records an approval verdict on the current round. */
    public function actionApprove(): Response
    {
        [$plugin, $review, $user] = $this->resolveReview();
        $fresh = $plugin->workflow->approve($review, $user);
        return $this->asJson(['success' => true, 'review' => $this->reviewPayload($fresh)]);
    }

    /** A reviewer asks for changes (the iterate loop). */
    public function actionRequestChanges(): Response
    {
        [$plugin, $review, $user] = $this->resolveReview();
        $note = $this->noteParam();
        $fresh = $plugin->workflow->requestChanges($review, $user, $note);
        return $this->asJson(['success' => true, 'review' => $this->reviewPayload($fresh)]);
    }

    /** A reviewer declines outright (terminal). */
    public function actionDecline(): Response
    {
        [$plugin, $review, $user] = $this->resolveReview();
        $note = $this->noteParam();
        $fresh = $plugin->workflow->decline($review, $user, $note);
        return $this->asJson(['success' => true, 'review' => $this->reviewPayload($fresh)]);
    }

    /** The author re-requests review after revising (changes_requested → open). */
    public function actionReRequest(): Response
    {
        [$plugin, $review, $user] = $this->resolveReview();
        $fresh = $plugin->workflow->reRequest($review, $user);
        return $this->asJson(['success' => true, 'review' => $this->reviewPayload($fresh)]);
    }

    /** The author withdraws the request (terminal). */
    public function actionWithdraw(): Response
    {
        [$plugin, $review, $user] = $this->resolveReview();
        $fresh = $plugin->workflow->withdraw($review, $user);
        return $this->asJson(['success' => true, 'review' => $this->reviewPayload($fresh)]);
    }

    /**
     * Publish an approved review wholesale, now or scheduled. Gated by the native
     * canSave permission so a reviewer without publish rights can't push live.
     */
    public function actionPublish(): Response
    {
        [$plugin, $review, $user] = $this->resolveReview();

        $canonical = Craft::$app->getEntries()->getEntryById($review->canonicalEntryId);
        if ($canonical === null) {
            throw new NotFoundHttpException(Craft::t('craft-delta', TranslationKeys::ENTRY_NOT_FOUND));
        }
        if (!$canonical->canSave($user)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NO_PERMISSION_PUBLISH));
        }

        $scheduledForRaw = Craft::$app->getRequest()->getBodyParam('scheduledFor');
        $scheduledFor = null;
        if ($scheduledForRaw !== null && $scheduledForRaw !== '') {
            // Parse in the active user's timezone; a bare new DateTime() would
            // misread a wall-clock string in the system timezone.
            $scheduledFor = DateTimeHelper::toDateTime($scheduledForRaw);
            if ($scheduledFor === false) {
                return $this->failure(TranslationKeys::SCHEDULE_FAILED);
            }
        }

        $plugin->workflow->publish($review, $scheduledFor, $user);

        $redirectUrl = $canonical->getCpEditUrl() ?? UrlHelper::cpUrl("entries/{$review->canonicalEntryId}");
        return $this->asJson(['success' => true, 'redirectUrl' => $redirectUrl]);
    }

    /** Eligible reviewers for the submit picker. */
    public function actionAssignees(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();

        $sectionUid = Craft::$app->getRequest()->getRequiredParam('sectionUid');

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can(Delta::PERMISSION_SUBMIT)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        }

        $assignees = Delta::getInstance()->workflow->getEligibleAssignees($sectionUid, $user->id);

        return $this->asJson([
            'success' => true,
            'assignees' => array_map(fn($u) => [
                'id' => $u->id,
                'name' => $u->fullName ?: $u->username,
            ], $assignees),
        ]);
    }

    // ---------------------------------------------------------------------
    // Comments (Phase 2)
    // ---------------------------------------------------------------------

    /** Add a comment — general (no atomId) or anchored to a diff atom. */
    public function actionComment(): Response
    {
        [$plugin, $review, $user] = $this->resolveReview();
        $this->assertCanView($plugin, $review, $user);

        $request = Craft::$app->getRequest();
        $body = (string)$request->getBodyParam('body', '');
        $atomId = $request->getBodyParam('atomId');
        $parentRaw = $request->getBodyParam('parentId');

        try {
            $comment = $plugin->reviewComment->addComment(
                $review,
                $user,
                $body,
                is_string($atomId) && $atomId !== '' ? $atomId : null,
                $parentRaw !== null && $parentRaw !== '' ? (int)$parentRaw : null,
            );
        } catch (InvalidArgumentException) {
            return $this->failure(TranslationKeys::WORKFLOW_COMMENT_FAILED);
        }

        return $this->asJson(['success' => true, 'comment' => $this->commentPayload($comment)]);
    }

    /** Mark a comment resolved/unresolved. */
    public function actionResolveComment(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $commentId = (int)$request->getRequiredBodyParam('commentId');
        $resolved = (bool)$request->getBodyParam('resolved', true);

        $plugin = Delta::getInstance();
        $comment = $plugin->reviewComment->getById($commentId);
        if ($comment === null) {
            throw new NotFoundHttpException(Craft::t('craft-delta', TranslationKeys::WORKFLOW_NOT_FOUND));
        }
        $review = $plugin->workflow->getById($comment->reviewId);
        if ($review === null) {
            throw new NotFoundHttpException(Craft::t('craft-delta', TranslationKeys::WORKFLOW_NOT_FOUND));
        }
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        }
        $this->assertCanView($plugin, $review, $user);

        $updated = $plugin->reviewComment->resolveComment($commentId, $resolved);
        return $this->asJson(['success' => true, 'comment' => $this->commentPayload($updated)]);
    }

    /** The comment thread for a review, with each anchored comment's outdated flag. */
    public function actionThread(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();

        $reviewId = (int)Craft::$app->getRequest()->getRequiredParam('reviewId');
        $plugin = Delta::getInstance();
        $review = $plugin->workflow->getById($reviewId);
        if ($review === null) {
            throw new NotFoundHttpException(Craft::t('craft-delta', TranslationKeys::WORKFLOW_NOT_FOUND));
        }
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        }
        $this->assertCanView($plugin, $review, $user);

        $comments = $plugin->reviewComment->commentsForReview($review->id, $this->liveAtomKeys($review));

        return $this->asJson([
            'success' => true,
            'comments' => array_map([$this, 'commentPayload'], $comments),
        ]);
    }

    /**
     * Reviewers and the author may view/comment. Throws otherwise.
     */
    private function assertCanView(Delta $plugin, Review $review, \craft\elements\User $user): void
    {
        if (!$plugin->workflow->canReview($user, $review) && !$plugin->workflow->canActAsAuthor($user, $review)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        }
    }

    /**
     * The live diff's atom keys, used to flag outdated comments. Null when the
     * review has no draft (published) or the diff can't be computed.
     *
     * @return string[]|null
     */
    private function liveAtomKeys(Review $review): ?array
    {
        if ($review->draftId === null) {
            return null;
        }
        $plugin = Delta::getInstance();
        $canonical = Craft::$app->getEntries()->getEntryById($review->canonicalEntryId);
        $draft = Entry::find()->draftId($review->draftId)->status(null)->one();
        if (!$canonical instanceof Entry || !$draft instanceof Entry) {
            return null;
        }
        try {
            return MergeService::collectAvailableAtoms($plugin->diff->compare($canonical, $draft));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function commentPayload(ReviewComment $c): array
    {
        return [
            'id' => $c->id,
            'body' => $c->body,
            'authorName' => $c->authorName,
            'anchorType' => $c->anchorType,
            'fieldHandle' => $c->fieldHandle,
            'blockUid' => $c->blockUid,
            'atomId' => $c->atomId,
            'resolved' => $c->resolved,
            'outdated' => $c->outdated,
            'round' => $c->round,
            'parentId' => $c->parentId,
            'dateCreated' => $c->dateCreated?->format(\DateTimeInterface::ATOM),
            'replies' => array_map([$this, 'commentPayload'], $c->replies),
        ];
    }

    /**
     * Shared setup for the verdict/transition actions: enforce JSON+CP+POST,
     * load the review (404 if missing), and resolve the acting user (403 if
     * none). Per-action permission rules live in the service.
     *
     * @return array{0: Delta, 1: Review, 2: \craft\elements\User}
     */
    private function resolveReview(): array
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $reviewId = (int)Craft::$app->getRequest()->getRequiredBodyParam('reviewId');
        $plugin = Delta::getInstance();
        $review = $plugin->workflow->getById($reviewId);
        if ($review === null) {
            throw new NotFoundHttpException(Craft::t('craft-delta', TranslationKeys::WORKFLOW_NOT_FOUND));
        }

        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        }

        return [$plugin, $review, $user];
    }

    private function noteParam(): ?string
    {
        $note = Craft::$app->getRequest()->getBodyParam('note');
        return is_string($note) && $note !== '' ? $note : null;
    }

    /** @return array<string, mixed> */
    private function reviewPayload(Review $review): array
    {
        return [
            'id' => $review->id,
            'state' => $review->state,
            'round' => $review->round,
        ];
    }

    private function failure(string $messageKey): Response
    {
        return $this->asJson([
            'success' => false,
            'error' => Craft::t('craft-delta', $messageKey),
        ])->setStatusCode(422);
    }
}
