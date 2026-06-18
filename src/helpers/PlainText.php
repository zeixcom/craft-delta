<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\helpers;

/** Normalization for user-authored plain text (comments, workflow notes, email). */
final class PlainText
{
    /** Strip ASCII control characters that could affect plain-text email rendering. */
    public static function normalize(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        return $text === '' ? null : $text;
    }
}
