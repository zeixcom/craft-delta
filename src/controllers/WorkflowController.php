<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\controllers;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\base\InvalidArgumentException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\enums\CommentAnchorType;
use zeixcom\craftdelta\helpers\Limits;
use zeixcom\craftdelta\helpers\PlainText;
use zeixcom\craftdelta\helpers\UserName;
use zeixcom\craftdelta\i18n\TranslationKeys;
use zeixcom\craftdelta\models\Review;
use zeixcom\craftdelta\models\ReviewComment;
use zeixcom\craftdelta\Permissions;
use zeixcom\craftdelta\services\MergeService;

/**
 * Endpoints for the review-request workflow. The WorkflowService owns all state;
 * this controller stays thin: resolve params, enforce the native publish gate
 * where relevant, delegate, and shape the JSON response.
 *
 * @phpstan-import-type AuthenticatedReviewTuple from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type CommentJsonPayload from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type CommentJsonFields from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type CommentJsonReply from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type ReviewSummaryPayload from \zeixcom\craftdelta\types\ArrayTypes
 */
class WorkflowController extends Controller
{
    /** Block every workflow endpoint when the feature is switched off. */
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

    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || (!$user->admin && !$user->can(Permissions::SUBMIT) && !$user->can(Permissions::REVIEW))) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        }

        $plugin = Delta::getInstance();
        $data = $plugin->workflow->getReviewsForDashboard($user);
        $rows = fn(array $reviews) => array_map($this->dashboardRow(...), $reviews);

        return $this->renderTemplate('craft-delta/index', [
            'assigned' => $rows($data['assigned']),
            'submitted' => $rows($data['submitted']),
            'all' => $rows($data['all']),
            'isAdmin' => $user->admin,
        ]);
    }

    /**
     * The dedicated review workspace: the diff for one review (canonical vs its
     * draft) plus the workflow apparatus, rendered as a full CP page rather than
     * inside the diff slideout.
     */
    public function actionReview(): Response
    {
        $this->requireCpRequest();

        $request = Craft::$app->getRequest();
        $siteIdRaw = $request->getParam('siteId');
        $siteId = $siteIdRaw !== null ? (int)$siteIdRaw : null;

        [$plugin, $review, $user] = $this->loadAuthenticatedReview((int)$request->getRequiredParam('reviewId'));
        $this->assertCanView($plugin, $review, $user);

        $canonical = $plugin->revision->getCanonical($review->canonicalEntryId, $siteId);
        if (!$canonical instanceof Entry) {
            throw new NotFoundHttpException(Craft::t('craft-delta', TranslationKeys::ENTRY_NOT_FOUND));
        }

        // Published reviews keep no draft (it's deleted on apply, FK SET NULLs
        // draftId) — there's nothing to diff, so render the closed-state page.
        $draft = $review->draftId !== null
            ? $plugin->revision->getDraftByDraftId($review->draftId, $canonical->id, $siteId)
            : null;

        $result = null;
        if ($draft instanceof Entry) {
            // Canonical-relative order so atom-ids match the merge re-run at apply
            // time, exactly as actionCompare does in review mode.
            $result = $plugin->diff->compare($canonical, $draft);
        }

        return $this->renderTemplate('craft-delta/review', [
            'title' => $canonical->title ?? ('#' . $review->canonicalEntryId),
            'review' => $review,
            'workflow' => $review,
            'result' => $result,
            // Honor the Review Mode kill-switch: no accept/reject/apply when off
            // (matches DiffController::actionCompare). With reviewMode false the
            // header stepper, atom actions, and auto-enter all drop out.
            'reviewMode' => $result !== null && $review->isActive() && $plugin->getSettings()->enableReviewMode,
            'isReviewer' => $plugin->workflow->canReview($user, $review),
            'entryId' => $review->canonicalEntryId,
            'siteId' => $canonical->siteId,
            'sourceRef' => 'draft:' . $review->draftId,
            'canonicalUpdatedAt' => $canonical->dateUpdated?->format(\DateTimeInterface::ATOM),
            'sourceUpdatedAt' => $draft?->dateUpdated?->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function actionSubmit(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $reviewerIds = (array)$request->getBodyParam('reviewerIds', []);
        if (count($reviewerIds) > Limits::REVIEWER_IDS_MAX) {
            return $this->failure(TranslationKeys::FAILED_SUBMIT_FOR_REVIEW);
        }

        $draft = Delta::getInstance()->revision->getDraftByDraftId((int)$request->getRequiredBodyParam('draftId'));
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

    public function actionApprove(): Response
    {
        [$plugin, $review, $user] = $this->resolveReview();
        return $this->asJson(['success' => true, 'review' => $this->reviewPayload($plugin->workflow->approve($review, $user))]);
    }

    public function actionDecline(): Response
    {
        return $this->verdictResponse(fn($p, $r, $u, $note) => $p->workflow->decline($r, $u, $note));
    }

    public function actionWithdraw(): Response
    {
        [$plugin, $review, $user] = $this->resolveReview();
        return $this->asJson(['success' => true, 'review' => $this->reviewPayload($plugin->workflow->withdraw($review, $user))]);
    }

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
            $scheduledFor = DateTimeHelper::toDateTime($scheduledForRaw);
            if ($scheduledFor === false) {
                return $this->failure(TranslationKeys::SCHEDULE_FAILED);
            }
        }

        $plugin->workflow->publish($review, $scheduledFor, $user);
        return $this->asJson([
            'success' => true,
            'redirectUrl' => $canonical->getCpEditUrl() ?? UrlHelper::cpUrl("entries/{$review->canonicalEntryId}"),
        ]);
    }

    public function actionAssignees(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can(Permissions::SUBMIT)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        }

        return $this->asJson([
            'success' => true,
            'assignees' => array_map(
                static fn(User $u) => ['id' => $u->id, 'name' => UserName::of($u)],
                Delta::getInstance()->workflow->getEligibleAssignees(Craft::$app->getRequest()->getRequiredParam('sectionUid'), $user->id),
            ),
        ]);
    }

    public function actionComment(): Response
    {
        [$plugin, $review, $user] = $this->resolveReview();
        $this->assertCanView($plugin, $review, $user);
        $this->assertActiveReview($review);

        $request = Craft::$app->getRequest();
        $atomId = $request->getBodyParam('atomId');
        $parentRaw = $request->getBodyParam('parentId');

        try {
            $comment = $plugin->reviewComment->addComment(
                $review,
                $user,
                (string)$request->getBodyParam('body', ''),
                is_string($atomId) && $atomId !== '' ? $atomId : null,
                $parentRaw !== null && $parentRaw !== '' ? (int)$parentRaw : null,
            );
        } catch (InvalidArgumentException) {
            return $this->failure(TranslationKeys::WORKFLOW_COMMENT_FAILED);
        }

        return $this->asJson(['success' => true, 'comment' => $this->commentPayload($comment)]);
    }

    public function actionResolveComment(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $commentId = (int)Craft::$app->getRequest()->getRequiredBodyParam('commentId');
        $plugin = Delta::getInstance();
        $comment = $plugin->reviewComment->getById($commentId)
            ?? throw new NotFoundHttpException(Craft::t('craft-delta', TranslationKeys::WORKFLOW_NOT_FOUND));

        [$plugin, $review, $user] = $this->loadAuthenticatedReview($comment->reviewId);
        $this->assertCanResolveComment($plugin, $review, $user);

        return $this->asJson([
            'success' => true,
            'comment' => $this->commentPayload($plugin->reviewComment->resolveComment($commentId, (bool)Craft::$app->getRequest()->getBodyParam('resolved', true))),
        ]);
    }

    public function actionThread(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();

        [$plugin, $review, $user] = $this->loadAuthenticatedReview((int)Craft::$app->getRequest()->getRequiredParam('reviewId'));
        $this->assertCanView($plugin, $review, $user);

        return $this->asJson([
            'success' => true,
            'comments' => array_map([$this, 'commentPayload'], $plugin->reviewComment->commentsForReview($review->id, $this->liveAtomKeys($plugin, $review))),
        ]);
    }

    private function verdictResponse(callable $handler): Response
    {
        [$plugin, $review, $user] = $this->resolveReview();
        try {
            $note = $this->noteParam();
        } catch (InvalidArgumentException) {
            return $this->failure(TranslationKeys::WORKFLOW_ACTION_FAILED);
        }
        return $this->asJson(['success' => true, 'review' => $this->reviewPayload($handler($plugin, $review, $user, $note))]);
    }

    /** @return array{review: Review, title: string} */
    private function dashboardRow(Review $review): array
    {
        $canonical = Craft::$app->getEntries()->getEntryById($review->canonicalEntryId);
        return [
            'review' => $review,
            'title' => $canonical?->title ?? ('#' . $review->canonicalEntryId),
        ];
    }

    private function assertCanView(Delta $plugin, Review $review, User $user): void
    {
        if (!$plugin->workflow->canReview($user, $review) && !$plugin->workflow->canActAsAuthor($user, $review)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        }
    }

    private function assertCanResolveComment(Delta $plugin, Review $review, User $user): void
    {
        if (!$plugin->workflow->canReview($user, $review)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_REVIEWER_FOR_DRAFT));
        }
    }

    private function assertActiveReview(Review $review): void
    {
        if (!$review->isActive()) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::REVIEW_NO_LONGER_OPEN));
        }
    }

    /** @return string[]|null */
    private function liveAtomKeys(Delta $plugin, Review $review): ?array
    {
        if ($review->draftId === null) {
            return null;
        }
        $canonical = Craft::$app->getEntries()->getEntryById($review->canonicalEntryId);
        $draft = $plugin->revision->getDraftByDraftId($review->draftId);
        if (!$canonical instanceof Entry || !$draft instanceof Entry) {
            return null;
        }
        return MergeService::collectAvailableAtoms($plugin->diff->compare($canonical, $draft));
    }

    /** @return CommentJsonPayload */
    private function commentPayload(ReviewComment $c): array
    {
        return [...$this->commentFields($c), 'replies' => array_map($this->commentReplyPayload(...), $c->replies)];
    }

    /** @return CommentJsonReply */
    private function commentReplyPayload(ReviewComment $c): array
    {
        return [...$this->commentFields($c), 'replies' => []];
    }

    /** @return CommentJsonFields */
    private function commentFields(ReviewComment $c): array
    {
        return [
            'id' => $c->id,
            'body' => $c->body,
            'authorName' => $c->authorName,
            'anchorType' => CommentAnchorType::from($c->anchorType)->value,
            'fieldHandle' => $c->fieldHandle,
            'blockUid' => $c->blockUid,
            'atomId' => $c->atomId,
            'resolved' => $c->resolved,
            'outdated' => $c->outdated,
            'round' => $c->round,
            'parentId' => $c->parentId,
            'dateCreated' => $c->dateCreated?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return AuthenticatedReviewTuple */
    private function loadAuthenticatedReview(int $reviewId): array
    {
        $plugin = Delta::getInstance();
        $review = $plugin->workflow->getById($reviewId)
            ?? throw new NotFoundHttpException(Craft::t('craft-delta', TranslationKeys::WORKFLOW_NOT_FOUND));
        $user = Craft::$app->getUser()->getIdentity()
            ?? throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        return [$plugin, $review, $user];
    }

    /** @return AuthenticatedReviewTuple */
    private function resolveReview(): array
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();
        return $this->loadAuthenticatedReview((int)Craft::$app->getRequest()->getRequiredBodyParam('reviewId'));
    }

    private function noteParam(): ?string
    {
        $note = Craft::$app->getRequest()->getBodyParam('note');
        if (!is_string($note) || $note === '') {
            return null;
        }
        $note = PlainText::normalize(trim($note));
        if ($note === null) {
            return null;
        }
        if (mb_strlen($note) > Limits::WORKFLOW_NOTE_MAX) {
            throw new InvalidArgumentException('Note is too long.');
        }
        return $note;
    }

    /** @return ReviewSummaryPayload */
    private function reviewPayload(Review $review): array
    {
        return ['id' => $review->id, 'state' => $review->state, 'round' => $review->round];
    }

    private function failure(string $messageKey): Response
    {
        return $this->asJson(['success' => false, 'error' => Craft::t('craft-delta', $messageKey)])->setStatusCode(422);
    }
}
