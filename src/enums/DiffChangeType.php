<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\enums;

/** Change classification emitted by field differs and encoded in atom keys. */
enum DiffChangeType: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Modified = 'modified';
    case Reordered = 'reordered';

    /** @return list<value-of<self>> */
    public static function atomValues(): array
    {
        return array_map(static fn(self $c) => $c->value, [self::Added, self::Removed, self::Modified]);
    }
}
