<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\util\AtomKey;

class AtomKeyTest extends TestCase
{
    public function testParseFieldAtom(): void
    {
        $parsed = AtomKey::parse('field:title');

        $this->assertSame('field', $parsed['kind']);
        $this->assertSame('title', $parsed['handle']);
    }

    public function testParseMatrixBlockAtom(): void
    {
        $parsed = AtomKey::parse('matrix-block:blocks:8a3f-1234:added');

        $this->assertSame('matrix-block', $parsed['kind']);
        $this->assertSame('blocks', $parsed['fieldHandle']);
        $this->assertSame('8a3f-1234', $parsed['blockUid']);
        $this->assertSame('added', $parsed['changeType']);
    }

    public function testParseMatrixReorderAtom(): void
    {
        $parsed = AtomKey::parse('matrix-reorder:blocks');

        $this->assertSame('matrix-reorder', $parsed['kind']);
        $this->assertSame('blocks', $parsed['fieldHandle']);
    }

    public function testParseMatrixFieldAtom(): void
    {
        $parsed = AtomKey::parse('matrix-field:blocks:8a3f-1234:heading');

        $this->assertSame('matrix-field', $parsed['kind']);
        $this->assertSame('blocks', $parsed['fieldHandle']);
        $this->assertSame('8a3f-1234', $parsed['blockUid']);
        $this->assertSame('heading', $parsed['subFieldHandle']);
    }

    public function testRejectsMalformedMatrixFieldAtom(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AtomKey::parse('matrix-field:blocks:8a3f-1234'); // missing sub-field handle
    }

    public function testRejectsMalformedAtom(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AtomKey::parse('bogus:thing');
    }

    public function testRejectsUnknownChangeType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AtomKey::parse('matrix-block:blocks:abc:exploded');
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AtomKey::parse('');
    }
}
