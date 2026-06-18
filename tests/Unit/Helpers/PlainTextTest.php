<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\helpers\PlainText;

class PlainTextTest extends TestCase
{
    public function testNormalizeStripsControlCharacters(): void
    {
        $this->assertSame("hello\nworld", PlainText::normalize("hello\x00\nworld"));
    }

    public function testNormalizeReturnsNullForEmpty(): void
    {
        $this->assertNull(PlainText::normalize(''));
        $this->assertNull(PlainText::normalize(null));
    }
}
