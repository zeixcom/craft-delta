<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Differ;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\differ\WordCountStats;

/** Exposes the private trait method so the pure arithmetic can be tested. */
final class WordCountStatsHarness
{
    use WordCountStats;

    /** @return array{additions: int, deletions: int} */
    public function stats(string $old, string $new): array
    {
        return $this->wordCountStats($old, $new);
    }
}

class WordCountStatsTest extends TestCase
{
    private WordCountStatsHarness $h;

    protected function setUp(): void
    {
        $this->h = new WordCountStatsHarness();
    }

    public function testIdenticalTextHasNoDelta(): void
    {
        $this->assertSame(['additions' => 0, 'deletions' => 0], $this->h->stats('one two three', 'one two three'));
    }

    public function testAddedWordsCountAsAdditions(): void
    {
        $this->assertSame(['additions' => 2, 'deletions' => 0], $this->h->stats('one two', 'one two three four'));
    }

    public function testRemovedWordsCountAsDeletions(): void
    {
        $this->assertSame(['additions' => 0, 'deletions' => 3], $this->h->stats('one two three four', 'one'));
    }

    public function testSaturatesAtZeroNeverNegative(): void
    {
        // It reports a single signed net delta on the positive side only; the
        // opposite side saturates at 0 rather than going negative.
        $s = $this->h->stats('alpha', 'alpha beta gamma');
        $this->assertSame(2, $s['additions']);
        $this->assertSame(0, $s['deletions']);
    }

    public function testEqualCountReportsNoChangeEvenWhenWordsDiffer(): void
    {
        // It's a word-COUNT delta, not a set diff: 3 words → 3 words nets zero
        // even though every word changed. This is the known, intended ceiling.
        $this->assertSame(['additions' => 0, 'deletions' => 0], $this->h->stats('one two three', 'four five six'));
    }

    public function testEmptyStrings(): void
    {
        $this->assertSame(['additions' => 0, 'deletions' => 0], $this->h->stats('', ''));
        $this->assertSame(['additions' => 2, 'deletions' => 0], $this->h->stats('', 'two words'));
        $this->assertSame(['additions' => 0, 'deletions' => 2], $this->h->stats('two words', ''));
    }
}
