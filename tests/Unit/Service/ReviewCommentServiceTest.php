<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\models\ReviewComment;
use zeixcom\craftdelta\services\ReviewCommentService;

/**
 * The atom-id → anchor decomposition and the outdated check are pure, so they
 * are exercised here without a Craft kernel.
 */
class ReviewCommentServiceTest extends TestCase
{
    public function testNullAndEmptyAreGeneral(): void
    {
        foreach ([null, ''] as $value) {
            $a = ReviewCommentService::anchorFromAtomId($value);
            $this->assertSame(ReviewComment::ANCHOR_GENERAL, $a['anchorType']);
            $this->assertNull($a['fieldHandle']);
            $this->assertNull($a['blockUid']);
            $this->assertNull($a['atomId']);
        }
    }

    public function testFieldAnchor(): void
    {
        $a = ReviewCommentService::anchorFromAtomId('field:title');
        $this->assertSame(ReviewComment::ANCHOR_FIELD, $a['anchorType']);
        $this->assertSame('title', $a['fieldHandle']);
        $this->assertNull($a['blockUid']);
        $this->assertSame('field:title', $a['atomId']);
    }

    public function testMatrixBlockAnchor(): void
    {
        $a = ReviewCommentService::anchorFromAtomId('matrix-block:body:uid123:modified');
        $this->assertSame(ReviewComment::ANCHOR_ATOM, $a['anchorType']);
        $this->assertSame('body', $a['fieldHandle']);
        $this->assertSame('uid123', $a['blockUid']);
        $this->assertSame('matrix-block:body:uid123:modified', $a['atomId']);
    }

    public function testMatrixReorderAnchor(): void
    {
        $a = ReviewCommentService::anchorFromAtomId('matrix-reorder:body');
        $this->assertSame(ReviewComment::ANCHOR_ATOM, $a['anchorType']);
        $this->assertSame('body', $a['fieldHandle']);
        $this->assertNull($a['blockUid']);
    }

    public function testGeneralCommentNeverOutdated(): void
    {
        $this->assertFalse(ReviewCommentService::isOutdated(ReviewComment::ANCHOR_GENERAL, null, []));
    }

    public function testAnchoredCurrentWhenInLiveSet(): void
    {
        $this->assertFalse(ReviewCommentService::isOutdated(
            ReviewComment::ANCHOR_FIELD,
            'field:title',
            ['field:title', 'field:body'],
        ));
    }

    public function testAnchoredOutdatedWhenGoneFromLiveSet(): void
    {
        $this->assertTrue(ReviewCommentService::isOutdated(
            ReviewComment::ANCHOR_ATOM,
            'matrix-block:body:uid1:modified',
            ['field:title'],
        ));
    }
}
