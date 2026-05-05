<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\services\MergeService;

class MergeServiceTest extends TestCase
{
    public function testParseFieldAtom(): void
    {
        $parsed = MergeService::parseAtomKey('field:title');

        $this->assertSame('field', $parsed['kind']);
        $this->assertSame('title', $parsed['handle']);
    }

    public function testParseMatrixBlockAtom(): void
    {
        $parsed = MergeService::parseAtomKey('matrix-block:blocks:8a3f-1234:added');

        $this->assertSame('matrix-block', $parsed['kind']);
        $this->assertSame('blocks', $parsed['fieldHandle']);
        $this->assertSame('8a3f-1234', $parsed['blockUid']);
        $this->assertSame('added', $parsed['changeType']);
    }

    public function testParseMatrixReorderAtom(): void
    {
        $parsed = MergeService::parseAtomKey('matrix-reorder:blocks');

        $this->assertSame('matrix-reorder', $parsed['kind']);
        $this->assertSame('blocks', $parsed['fieldHandle']);
    }

    public function testRejectsMalformedAtom(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MergeService::parseAtomKey('bogus:thing');
    }

    public function testRejectsUnknownChangeType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MergeService::parseAtomKey('matrix-block:blocks:abc:exploded');
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MergeService::parseAtomKey('');
    }
}
