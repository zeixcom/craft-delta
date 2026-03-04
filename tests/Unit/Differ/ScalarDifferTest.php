<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Differ;

use zeixcom\craftdelta\differ\ScalarDiffer;
use PHPUnit\Framework\TestCase;

class ScalarDifferTest extends TestCase
{
    private ScalarDiffer $differ;

    protected function setUp(): void
    {
        $this->differ = new ScalarDiffer();
    }

    public function testIdenticalStringsReturnNull(): void
    {
        $this->assertNull($this->differ->diff('hello', 'hello'));
    }

    public function testIdenticalNullsReturnNull(): void
    {
        $this->assertNull($this->differ->diff(null, null));
    }

    public function testIdenticalNumbersReturnNull(): void
    {
        $this->assertNull($this->differ->diff(42, 42));
    }

    public function testIdenticalBooleansReturnNull(): void
    {
        $this->assertNull($this->differ->diff(true, true));
    }

    public function testDifferentStringsProduceDiff(): void
    {
        $result = $this->differ->diff('old value', 'new value');
        $this->assertNotNull($result);
        $this->assertStringContainsString('delta-del', $result);
        $this->assertStringContainsString('delta-ins', $result);
        $this->assertStringContainsString('old value', $result);
        $this->assertStringContainsString('new value', $result);
    }

    public function testContainsArrow(): void
    {
        $result = $this->differ->diff('a', 'b');
        $this->assertNotNull($result);
        $this->assertStringContainsString('→', $result);
    }

    public function testNullToValueShowsEmpty(): void
    {
        $result = $this->differ->diff(null, 'hello');
        $this->assertNotNull($result);
        $this->assertStringContainsString('(empty)', $result);
        $this->assertStringContainsString('hello', $result);
    }

    public function testValueToNullShowsEmpty(): void
    {
        $result = $this->differ->diff('hello', null);
        $this->assertNotNull($result);
        $this->assertStringContainsString('hello', $result);
        $this->assertStringContainsString('(empty)', $result);
    }

    public function testEmptyStringShowsEmpty(): void
    {
        $result = $this->differ->diff('', 'hello');
        $this->assertNotNull($result);
        $this->assertStringContainsString('(empty)', $result);
    }

    public function testBooleanDisplaysYesNo(): void
    {
        $result = $this->differ->diff(false, true);
        $this->assertNotNull($result);
        $this->assertStringContainsString('No', $result);
        $this->assertStringContainsString('Yes', $result);
    }

    public function testBooleanTrueToFalse(): void
    {
        $result = $this->differ->diff(true, false);
        $this->assertNotNull($result);
        $this->assertStringContainsString('Yes', $result);
        $this->assertStringContainsString('No', $result);
    }

    public function testDateTimeFormatted(): void
    {
        $old = new \DateTime('2026-01-15 10:00:00');
        $new = new \DateTime('2026-02-20 14:30:00');
        $result = $this->differ->diff($old, $new);
        $this->assertNotNull($result);
        $this->assertStringContainsString('Jan 15, 2026', $result);
        $this->assertStringContainsString('Feb 20, 2026', $result);
    }

    public function testIdenticalDateTimesReturnNull(): void
    {
        $date = new \DateTime('2026-01-15 10:00:00');
        $same = new \DateTime('2026-01-15 10:00:00');
        $this->assertNull($this->differ->diff($date, $same));
    }

    public function testNumberDiff(): void
    {
        $result = $this->differ->diff(10, 25);
        $this->assertNotNull($result);
        $this->assertStringContainsString('10', $result);
        $this->assertStringContainsString('25', $result);
    }

    public function testHtmlEntitiesEscaped(): void
    {
        $result = $this->differ->diff('<script>alert("xss")</script>', 'safe');
        $this->assertNotNull($result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testStatsIdenticalValues(): void
    {
        $stats = $this->differ->getStats('same', 'same');
        $this->assertSame(0, $stats['additions']);
        $this->assertSame(0, $stats['deletions']);
    }

    public function testStatsDifferentValues(): void
    {
        $stats = $this->differ->getStats('old', 'new');
        $this->assertSame(1, $stats['additions']);
        $this->assertSame(1, $stats['deletions']);
    }
}
