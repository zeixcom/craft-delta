<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use craft\base\Component;
use craft\elements\User;
use yii\base\InvalidArgumentException;
use zeixcom\craftdelta\enums\AtomKind;
use zeixcom\craftdelta\helpers\DbDate;
use zeixcom\craftdelta\helpers\Limits;
use zeixcom\craftdelta\helpers\PlainText;
use zeixcom\craftdelta\helpers\UserName;
use zeixcom\craftdelta\models\Review;
use zeixcom\craftdelta\models\ReviewComment;
use zeixcom\craftdelta\records\ReviewCommentRecord;
use zeixcom\craftdelta\util\AtomKey;

/**
 * Owns review comments: anchored (field/atom) or general feedback, with one
 * level of replies. "Outdated" is derived at read time against the live diff's
 * atom set — never stored.
 *
 * @phpstan-import-type CommentAnchor from \zeixcom\craftdelta\types\ArrayTypes
 */
class ReviewCommentService extends Component
{
    /**
     * Decompose a client atom-id into a stable anchor. A null/empty id is a
     * general (request-level) comment. Mirrors the atom-key grammar in
     * {@see AtomKey} so anchored comments map back onto the diff.
     *
     * @return CommentAnchor
     */
    public static function anchorFromAtomId(?string $atomId): array
    {
        if ($atomId === null || $atomId === '') {
            return ['anchorType' => ReviewComment::ANCHOR_GENERAL, 'fieldHandle' => null, 'blockUid' => null, 'atomId' => null];
        }

        $parsed = AtomKey::parse($atomId);
        return match ($parsed['kind']) {
            AtomKind::Field->value => ['anchorType' => ReviewComment::ANCHOR_FIELD, 'fieldHandle' => $parsed['handle'], 'blockUid' => null, 'atomId' => $atomId],
            AtomKind::MatrixBlock->value => ['anchorType' => ReviewComment::ANCHOR_ATOM, 'fieldHandle' => $parsed['fieldHandle'], 'blockUid' => $parsed['blockUid'], 'atomId' => $atomId],
            AtomKind::MatrixReorder->value => ['anchorType' => ReviewComment::ANCHOR_ATOM, 'fieldHandle' => $parsed['fieldHandle'], 'blockUid' => null, 'atomId' => $atomId],
        };
    }

    /** @param string[] $liveAtomKeys */
    public static function isOutdated(string $anchorType, ?string $atomId, array $liveAtomKeys): bool
    {
        return $anchorType !== ReviewComment::ANCHOR_GENERAL
            && $atomId !== null
            && !in_array($atomId, $liveAtomKeys, true);
    }

    public function addComment(Review $review, User $author, string $body, ?string $atomId = null, ?int $parentId = null): ReviewComment
    {
        $body = PlainText::normalize(trim($body)) ?? '';
        if ($body === '') {
            throw new InvalidArgumentException('Comment body is required.');
        }
        if (mb_strlen($body) > Limits::COMMENT_BODY_MAX) {
            throw new InvalidArgumentException('Comment body is too long.');
        }

        if ($parentId !== null) {
            $parent = ReviewCommentRecord::findOne(['id' => $parentId, 'reviewId' => $review->id]);
            if ($parent === null) {
                throw new InvalidArgumentException('Invalid parent comment.');
            }
            if ($parent->parentId !== null) {
                throw new InvalidArgumentException('Only one level of replies is supported.');
            }
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
        $record = ReviewCommentRecord::findOne(['id' => $commentId])
            ?? throw new InvalidArgumentException('Comment not found.');
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

    /** @param string[]|null $liveAtomKeys @return ReviewComment[] */
    public function commentsForReview(int $reviewId, ?array $liveAtomKeys = null): array
    {
        $byId = [];
        foreach (ReviewCommentRecord::find()->where(['reviewId' => $reviewId])->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC])->all() as $record) {
            $byId[($model = $this->modelFromRecord($record, $liveAtomKeys))->id] = $model;
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

    /** @param string[]|null $liveAtomKeys */
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
            'dateCreated' => DbDate::parse($record->dateCreated),
            'dateUpdated' => DbDate::parse($record->dateUpdated),
            'uid' => $record->uid,
        ]);
        $model->authorName = UserName::byId((int)$record->authorId);
        if ($liveAtomKeys !== null) {
            $model->outdated = self::isOutdated($model->anchorType, $model->atomId, $liveAtomKeys);
        }
        return $model;
    }
}
