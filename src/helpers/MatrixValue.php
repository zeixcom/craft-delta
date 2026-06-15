<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\helpers;

use craft\elements\db\EntryQuery;
use craft\elements\ElementCollection;
use craft\elements\Entry;

/**
 * Normalizes a Matrix field value — which Craft returns as a query, an element
 * collection, or a raw array depending on element state — to a flat list of
 * entries. Non-entry members (e.g. unhydrated placeholders) are dropped.
 */
final class MatrixValue
{
    private function __construct()
    {
    }

    /**
     * @param EntryQuery|ElementCollection|list<Entry>|null $value
     * @return list<Entry>
     */
    public static function toEntries(EntryQuery|ElementCollection|array|null $value): array
    {
        return match (true) {
            $value === null => [],
            $value instanceof EntryQuery => $value->status(null)->all(),
            $value instanceof ElementCollection => array_values(array_filter($value->all(), static fn($e): bool => $e instanceof Entry)),
            default => array_values(array_filter($value, static fn($e): bool => $e instanceof Entry)),
        };
    }
}
