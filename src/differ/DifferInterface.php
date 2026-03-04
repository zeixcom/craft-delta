<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

/**
 * Contract for field type differs.
 *
 * Each differ handles comparison of a specific type of field value
 * and produces an HTML representation of the changes.
 */
interface DifferInterface
{
    /**
     * Produce a diff between two field values.
     *
     * @param mixed $oldValue The value from the older revision
     * @param mixed $newValue The value from the newer revision
     * @return string|null HTML diff output, or null if values are identical
     */
    public function diff(mixed $oldValue, mixed $newValue): ?string;

    /**
     * Get statistics about the changes between two values.
     *
     * @return array{additions: int, deletions: int}
     */
    public function getStats(mixed $oldValue, mixed $newValue): array;
}
