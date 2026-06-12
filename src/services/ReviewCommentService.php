<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use yii\base\InvalidArgumentException;
use zeixcom\craftdelta\models\Review;
use zeixcom\craftdelta\models\ReviewComment;
use zeixcom\craftdelta\records\ReviewCommentRecord;

/**
 * Owns review comments: anchored (field/atom) or general feedback, with one
 * level of replies. "Outdated" is derived at read time against the live diff's
 * atom set — never stored.
 */
class ReviewCommentService extends Component
{
    /**
     * Decompose a client atom-id into a stable anchor. A null/empty id is a
     * general (request-level) comment. Mirrors the atom-key grammar in
     * MergeService so anchored comments map back onto the diff.
     *
     * @return array{anchorType: string, fieldHandle: ?string, blockUid: ?string, atomId: ?string}
     */
    public static function anchorFromAtomId(?string $atomId): array
    {
        if ($atomId === null || $atomId === '') {
            return ['anchorType' => ReviewComment::ANCHOR_GENERAL, 'fieldHandle' => null, 'blockUid' => null, 'atomId' => null];
        }

        $parsed = MergeService::parseAtomKey($atomId); // throws on malformed

        // No default arm: parseAtomKey() throws on any other kind.
        return match ($parsed['kind']) {
            'field' => ['anchorType' => ReviewComment::ANCHOR_FIELD, 'fieldHandle' => $parsed['handle'], 'blockUid' => null, 'atomId' => $atomId],
            'matrix-block' => ['anchorType' => ReviewComment::ANCHOR_ATOM, 'fieldHandle' => $parsed['fieldHandle'], 'blockUid' => $parsed['blockUid'], 'atomId' => $atomId],
            'matrix-reorder' => ['anchorType' => ReviewComment::ANCHOR_ATOM, 'fieldHandle' => $parsed['fieldHandle'], 'blockUid' => null, 'atomId' => $atomId],
        };
    }

    /**
     * A comment is outdated when its anchor no longer resolves to a live atom.
     * General comments are never outdated. Pure so it can be unit-tested.
     *
     * @param string[] $liveAtomKeys
     */
    public static function isOutdated(string $anchorType, ?string $atomId, array $liveAtomKeys): bool
    {
        if ($anchorType === ReviewComment::ANCHOR_GENERAL || $atomId === null) {
            return false;
        }
        return !in_array($atomId, $liveAtomKeys, true);
    }

    public function addComment(Review $review, User $author, string $body, ?string $atomId = null, ?int $parentId = null): ReviewComment
    {
        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('Comment body is required.');
        }

        $anchor = self::anchorFromAtomId($atomId);

        $record = new ReviewCommentRecord();
        $record->reviewId = $review->id;
        $record->round = $review->round;
        $record->authorId = $author->id;
        $record->body = $body;
        $record->anchorType = $anchor['anchorType'];
        $record->fieldHandle = $anchor['fieldHandle'];
        $record->blockUid = $anchor['blockUid'];
        $record->atomId = $anchor['atomId'];
        $record->parentId = $parentId;
        if (!$record->save(false)) {
            throw new InvalidArgumentException('Failed to save comment.');
        }

        return $this->modelFromRecord($record, null);
    }

    public function resolveComment(int $commentId, bool $resolved): ReviewComment
    {
        $record = ReviewCommentRecord::findOne(['id' => $commentId]);
        if ($record === null) {
            throw new InvalidArgumentException('Comment not found.');
        }
        $record->resolved = $resolved;
        if (!$record->save(false)) {
            throw new InvalidArgumentException('Failed to update comment.');
        }
        return $this->modelFromRecord($record, null);
    }

    public function getById(int $commentId): ?ReviewComment
    {
        $record = ReviewCommentRecord::findOne(['id' => $commentId]);
        return $record ? $this->modelFromRecord($record, null) : null;
    }

    /**
     * All comments for a review as a thread tree (top-level + replies), ordered
     * oldest-first. When $liveAtomKeys is provided, each anchored comment's
     * `outdated` flag is computed against it.
     *
     * @param string[]|null $liveAtomKeys
     * @return ReviewComment[] top-level comments, each with ->replies
     */
    public function commentsForReview(int $reviewId, ?array $liveAtomKeys = null): array
    {
        $records = ReviewCommentRecord::find()
            ->where(['reviewId' => $reviewId])
            ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        $byId = [];
        foreach ($records as $record) {
            $model = $this->modelFromRecord($record, $liveAtomKeys);
            $byId[$model->id] = $model;
        }

        $top = [];
        foreach ($byId as $model) {
            if ($model->parentId !== null && isset($byId[$model->parentId])) {
                $byId[$model->parentId]->replies[] = $model;
            } else {
                $top[] = $model;
            }
        }

        return $top;
    }

    /**
     * @param string[]|null $liveAtomKeys
     */
    private function modelFromRecord(ReviewCommentRecord $record, ?array $liveAtomKeys): ReviewComment
    {
        $model = new ReviewComment([
            'id' => $record->id,
            'reviewId' => $record->reviewId,
            'round' => (int)$record->round,
            'authorId' => $record->authorId,
            'body' => $record->body,
            'anchorType' => $record->anchorType,
            'fieldHandle' => $record->fieldHandle,
            'blockUid' => $record->blockUid,
            'atomId' => $record->atomId,
            'resolved' => (bool)$record->resolved,
            'parentId' => $record->parentId,
            'dateCreated' => $this->parseDbDate($record->dateCreated),
            'dateUpdated' => $this->parseDbDate($record->dateUpdated),
            'uid' => $record->uid,
        ]);

        $author = Craft::$app->getUsers()->getUserById((int)$record->authorId);
        $model->authorName = $author ? ($author->fullName ?: $author->username) : null;

        if ($liveAtomKeys !== null) {
            $model->outdated = self::isOutdated($model->anchorType, $model->atomId, $liveAtomKeys);
        }

        return $model;
    }

    /**
     * Parse a UTC datetime string from the DB. A bare `new DateTime($str)` would
     * misread it in the server/user timezone; DateTimeHelper pins UTC.
     */
    private function parseDbDate(?string $value): ?\DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = \craft\helpers\DateTimeHelper::toDateTime($value);
        return $date instanceof \DateTime ? $date : null;
    }
}
