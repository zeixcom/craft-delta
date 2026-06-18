<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

trait WordCountStats
{
    /**
     * Saturating word-count delta between two plain-text strings.
     *
     * @return array{additions: int, deletions: int}
     */
    private function wordCountStats(string $old, string $new): array
    {
        $oldWords = str_word_count($old);
        $newWords = str_word_count($new);
        return ['additions' => max(0, $newWords - $oldWords), 'deletions' => max(0, $oldWords - $newWords)];
    }
}
