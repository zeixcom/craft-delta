<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\enums;

/**
 * Change classification emitted by field differs and encoded in atom keys.
 */
enum DiffChangeType: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Modified = 'modified';
    case Reordered = 'reordered';

    /** @return list<string> */
    public static function atomValues(): array
    {
        return [
            self::Added->value,
            self::Removed->value,
            self::Modified->value,
        ];
    }
}
