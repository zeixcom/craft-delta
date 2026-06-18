<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

/**
 * Craft field values are heterogeneous; `mixed` is intentional on the interface.
 * Concrete differs narrow internally (resolveEntries, normalize, etc.).
 *
 * @phpstan-import-type DiffStats from \zeixcom\craftdelta\types\ArrayTypes
 */
interface DifferInterface
{
    /**
     * @return string|null HTML diff output, or null if values are identical
     */
    public function diff(mixed $oldValue, mixed $newValue): ?string;

    /** @return DiffStats */
    public function getStats(mixed $oldValue, mixed $newValue): array;
}
