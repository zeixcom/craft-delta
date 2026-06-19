<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\controllers;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\AdminTable;
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
use zeixcom\craftdelta\models\ReviewReviewer;
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
        $user = $this->requireDashboardUser();

        $buckets = $this->availableBuckets($user);
        $keys = array_column($buckets, 'key');
        $selected = (string)Craft::$app->getRequest()->getParam('bucket', $keys[0] ?? 'assigned');
        if (!in_array($selected, $keys, true)) {
            $selected = $keys[0] ?? 'assigned';
        }

        // Badge count per visible bucket — the same visibility rules the table uses.
        foreach ($buckets as &$bucket) {
            $bucket['count'] = count($this->visibleReviews($user, $bucket['key']));
        }
        unset($bucket);

        return $this->renderTemplate('craft-delta/index', [
            'buckets' => $buckets,
            'selectedBucket' => $selected,
        ]);
    }

    /**
     * JSON feed for the reviews dashboard's Vue admin table (one bucket at a
     * time). Visibility rules per bucket live in visibleReviews().
     */
    public function actionTableData(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $user = $this->requireDashboardUser();

        $request = Craft::$app->getRequest();
        $page = max(1, (int)$request->getParam('page', 1));
        $limit = min(100, max(1, (int)$request->getParam('per_page', 50)));
        $search = trim((string)$request->getParam('search', ''));
        $sortField = (string)$request->getParam('sort.0.field', '');
        $sortDir = $request->getParam('sort.0.direction') === 'desc' ? SORT_DESC : SORT_ASC;

        // Gate the requested bucket by permission; fall back to the safe default.
        $bucket = (string)$request->getParam('bucket', 'assigned');
        $allowed = array_column($this->availableBuckets($user), 'key');
        if (!in_array($bucket, $allowed, true)) {
            $bucket = $allowed[0] ?? 'assigned';
        }

        $reviews = $this->visibleReviews($user, $bucket);
        $titles = $this->entryTitles($reviews); // one batched query, not getEntryById per row
        $rows = array_map(fn(Review $r) => $this->tableRow($r, $titles), $reviews);

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_filter($rows, static fn(array $r) =>
                str_contains(mb_strtolower($r['title']), $needle) || str_contains(mb_strtolower($r['statusLabel']), $needle));
        }

        // Status sorts by workflow-state rank, not the localized label text.
        $sortKey = match ($sortField) {
            '__slot:title' => 'title',
            'statusCell' => 'statusRank',
            'round' => 'round',
            default => null,
        };
        if ($sortKey !== null) {
            usort($rows, static fn(array $a, array $b) =>
                $sortDir === SORT_DESC ? ($b[$sortKey] <=> $a[$sortKey]) : ($a[$sortKey] <=> $b[$sortKey]));
        }

        $rows = array_values($rows);
        $total = count($rows);
        // Clamp the page so a stale/over-range page (e.g. after a search shrinks
        // the result set) returns the last page instead of an empty slice.
        $page = min($page, max(1, (int)ceil($total / $limit)));
        $rows = array_slice($rows, ($page - 1) * $limit, $limit);

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, $total, $limit),
            'data' => $rows,
        ]);
    }

    private function requireDashboardUser(): User
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || (!$user->admin && !$user->can(Permissions::SUBMIT) && !$user->can(Permissions::REVIEW))) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        }
        return $user;
    }

    /**
     * The dashboard tabs this user may see: assigned (reviewers), submitted
     * (authors), all (admins). Same gating as the Entries-index sources.
     *
     * @return array<int, array{key: string, label: string}>
     */
    private function availableBuckets(User $user): array
    {
        $buckets = [];
        if ($user->admin || $user->can(Permissions::REVIEW)) {
            $buckets[] = ['key' => 'assigned', 'label' => Craft::t('craft-delta', TranslationKeys::WORKFLOW_ASSIGNED_TO_ME)];
        }
        if ($user->admin || $user->can(Permissions::SUBMIT)) {
            $buckets[] = ['key' => 'submitted', 'label' => Craft::t('craft-delta', TranslationKeys::WORKFLOW_MY_SUBMISSIONS)];
        }
        if ($user->admin) {
            $buckets[] = ['key' => 'all', 'label' => Craft::t('craft-delta', TranslationKeys::WORKFLOW_ALL_REVIEWS)];
        }
        return $buckets;
    }

    /**
     * Reviews to show for a bucket. Actionable queues hide terminal reviews;
     * 'assigned' further narrows to reviews still awaiting THIS user's verdict
     * (matching the CP nav badge). The admin 'all' bucket shows everything so it
     * stays a complete audit view.
     *
     * @return list<Review>
     */
    private function visibleReviews(User $user, string $bucket): array
    {
        $reviews = Delta::getInstance()->workflow->getReviewsForBucket($user, $bucket);
        if ($bucket === 'all') {
            return array_values($reviews);
        }
        $reviews = array_filter($reviews, static fn(Review $r) => !$r->isTerminal());
        if ($bucket === 'assigned') {
            $reviews = array_filter($reviews, fn(Review $r) => $this->awaitingVerdict($r, (int)$user->id));
        }
        return array_values($reviews);
    }

    /** Does this user still owe a verdict on the review's current round? */
    private function awaitingVerdict(Review $review, int $userId): bool
    {
        foreach ($review->reviewers as $reviewer) {
            if ($reviewer->userId === $userId) {
                return $reviewer->verdict === ReviewReviewer::VERDICT_PENDING;
            }
        }
        return false;
    }

    /**
     * Batch-load canonical entry titles for a set of reviews in one query.
     *
     * @param list<Review> $reviews
     * @return array<int, string> canonicalEntryId => title
     */
    private function entryTitles(array $reviews): array
    {
        $ids = array_values(array_unique(array_map(static fn(Review $r) => $r->canonicalEntryId, $reviews)));
        if ($ids === []) {
            return [];
        }
        $titles = [];
        foreach (Entry::find()->id($ids)->status(null)->all() as $entry) {
            $titles[(int)$entry->id] = (string)$entry->title;
        }
        return $titles;
    }

    /** Stable workflow-state ordering for the Status column (locale-independent). */
    private function statusRank(Review $review): int
    {
        return match ($review->state) {
            Review::STATE_OPEN => 0,
            Review::STATE_APPROVED => 1,
            Review::STATE_PUBLISHED => 2,
            Review::STATE_DECLINED => 3,
            Review::STATE_CANCELLED => 4,
            default => 5,
        };
    }

    /**
     * One admin-table row for a review. Status + reviewer pills are pre-rendered
     * HTML (shown via column callbacks); `statusLabel` backs search, `statusRank`
     * backs the Status sort.
     *
     * @param array<int, string> $titles canonicalEntryId => title
     * @return array<string, mixed>
     */
    private function tableRow(Review $review, array $titles): array
    {
        $title = $titles[$review->canonicalEntryId] ?? ('#' . $review->canonicalEntryId);

        $pills = '';
        foreach ($review->reviewers as $reviewer) {
            $name = $reviewer->userName ?? ('#' . $reviewer->userId);
            $pills .= '<span class="delta-reviewer-pill delta-reviewer-pill--' . htmlspecialchars($reviewer->verdict) . '"'
                . ' title="' . htmlspecialchars($name) . '">' . htmlspecialchars($name) . '</span> ';
        }

        return [
            'id' => $review->id,
            'title' => $title,
            'url' => UrlHelper::cpUrl('delta-review', ['reviewId' => $review->id]),
            'statusCell' => '<span class="status ' . htmlspecialchars($review->statusColor()) . '"></span>' . htmlspecialchars($review->statusLabel()),
            'statusLabel' => $review->statusLabel(),
            'statusRank' => $this->statusRank($review),
            'round' => $review->round,
            'reviewers' => trim($pills),
        ];
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

        $previewUrl = ($draft instanceof Entry && $plugin->getSettings()->enablePreview)
            ? $this->draftPreviewUrl($canonical, $draft, $user)
            : null;

        return $this->renderTemplate('craft-delta/review', [
            'title' => $canonical->title ?? ('#' . $review->canonicalEntryId),
            'review' => $review,
            'workflow' => $review,
            'result' => $result,
            // Honor the Review Mode kill-switch: no accept/reject/apply when off
            // (matches DiffController::actionCompare). With reviewMode false the
            // header stepper, atom actions, and auto-enter all drop out.
            'reviewMode' => $result !== null && $review->isActive() && $plugin->getSettings()->enableReviewMode,
            // Default the diff to changed-only (honoring the setting) so the page
            // isn't a wall of "no changes" rows; a toggle reveals unchanged fields.
            'showUnchanged' => $plugin->getSettings()->defaultShowUnchanged,
            'entryUrl' => $canonical->getCpEditUrl(),
            'previewUrl' => $previewUrl,
            'isReviewer' => $plugin->workflow->canReview($user, $review),
            'entryId' => $review->canonicalEntryId,
            'siteId' => $canonical->siteId,
            'sourceRef' => 'draft:' . $review->draftId,
            'canonicalUpdatedAt' => $canonical->dateUpdated?->format(\DateTimeInterface::ATOM),
            'sourceUpdatedAt' => $draft?->dateUpdated?->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * A tokenized front-end URL that renders the DRAFT (not the live canonical),
     * for the side-by-side review preview — null when the section has no URLs.
     * The token is minted directly because PreviewController::actionPreview only
     * requires a valid token, not the session authorization that minting through
     * the CP's preview/create-token action would.
     */
    private function draftPreviewUrl(Entry $canonical, Entry $draft, User $user): ?string
    {
        $base = $draft->getUrl();
        if ($base === null) {
            return null;
        }

        $token = Craft::$app->getTokens()->createPreviewToken([
            'preview/preview', [
                'elementType' => Entry::class,
                'canonicalId' => $canonical->id,
                'siteId' => $canonical->siteId,
                'draftId' => $draft->draftId,
                'revisionId' => null,
                'userId' => $user->id,
            ],
        ]);

        return $token !== false ? UrlHelper::urlWithToken($base, $token) : null;
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

        // Return the confirmation message + status so the client shows a notice
        // and swaps the sidebar button for the status pill in place — no reload,
        // which would immediately wipe the notice.
        return $this->asJson([
            'success' => true,
            'review' => [
                'id' => $review->id,
                'state' => $review->state,
                'statusLabel' => $review->statusLabel(),
            ],
            'message' => Craft::t('craft-delta', TranslationKeys::WORKFLOW_DONE_SUBMITTED),
        ]);
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

        // Publishing applies the review to the canonical entry, so it requires
        // the dedicated apply permission — same gate as the granular apply path
        // (DiffController::actionApply). Native save rights alone aren't enough.
        if (!$user->can(Permissions::APPLY)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NO_PERMISSION_APPLY_REVIEW));
        }

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

        $this->notifyOfComment($plugin, $review, $user, $comment);

        return $this->asJson(['success' => true, 'comment' => $this->commentPayload($comment)]);
    }

    /** At most one comment email per recipient per review per this many seconds. */
    private const COMMENT_NOTIFY_COOLDOWN = 300;

    private function notifyOfComment(Delta $plugin, Review $review, User $commenter, ReviewComment $comment): void
    {
        $entry = ($review->draftId !== null ? $plugin->revision->getDraftByDraftId($review->draftId) : null)
            ?? Craft::$app->getEntries()->getEntryById($review->canonicalEntryId);
        if (!$entry instanceof Entry) {
            return;
        }

        // Author's comment → tell the reviewers; a reviewer's → tell the author.
        $recipientIds = (int)$commenter->id === (int)$review->submittedBy
            ? array_map(static fn(ReviewReviewer $r) => $r->userId, $review->reviewers)
            : [$review->submittedBy];

        $commenterName = UserName::of($commenter);
        $cache = Craft::$app->getCache();

        foreach (array_values(array_unique($recipientIds)) as $recipientId) {
            if ($recipientId === (int)$commenter->id) {
                continue;
            }
            // Debounce: one alert per recipient per review per cooldown window —
            // they open the review and read the whole thread, so a burst of
            // comments shouldn't be a burst of emails.
            $cacheKey = "craftdelta:comment-notified:{$review->id}:{$recipientId}";
            if ($cache->exists($cacheKey)) {
                continue;
            }
            $recipient = Craft::$app->getUsers()->getUserById($recipientId);
            if ($recipient === null) {
                continue;
            }
            $plugin->email->sendCommentNotification($review, $entry, $recipient, $commenterName, $comment->body);
            $cache->set($cacheKey, true, self::COMMENT_NOTIFY_COOLDOWN);
        }
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
