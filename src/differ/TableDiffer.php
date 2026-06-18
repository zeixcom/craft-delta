<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use zeixcom\craftdelta\enums\DiffChangeType;

/**
 * @phpstan-import-type DiffStats from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type TableCellDiff from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type TableRow from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type TableRowChange from \zeixcom\craftdelta\types\ArrayTypes
 */
class TableDiffer implements DifferInterface
{
    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        $oldRows = $this->rows($oldValue);
        $newRows = $this->rows($newValue);
        if ($oldRows === $newRows) {
            return null;
        }

        /** @var list<TableRowChange> $changes */
        $changes = [];
        $maxRows = max(count($oldRows), count($newRows));
        for ($i = 0; $i < $maxRows; $i++) {
            $oldRow = $oldRows[$i] ?? null;
            $newRow = $newRows[$i] ?? null;
            if ($oldRow === null && $newRow !== null) {
                $changes[] = ['type' => DiffChangeType::Added->value, 'row' => $i + 1, 'values' => $newRow];
            } elseif ($newRow === null && $oldRow !== null) {
                $changes[] = ['type' => DiffChangeType::Removed->value, 'row' => $i + 1, 'values' => $oldRow];
            } elseif ($oldRow !== null && $newRow !== null && $oldRow !== $newRow) {
                $changes[] = [
                    'type' => DiffChangeType::Modified->value,
                    'row' => $i + 1,
                    'cells' => $this->compareCells($oldRow, $newRow),
                    // Unchanged cells (identical on both sides) for the
                    // template's fallback rendering of modified rows.
                    'values' => $newRow,
                ];
            }
        }

        return $changes === [] ? null : json_encode($changes, JSON_THROW_ON_ERROR);
    }

    /** @return DiffStats */
    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        $oldRows = $this->rows($oldValue);
        $newRows = $this->rows($newValue);
        $added = $removed = 0;
        for ($i = 0, $max = max(count($oldRows), count($newRows)); $i < $max; $i++) {
            if (!isset($oldRows[$i])) {
                $added++;
            } elseif (!isset($newRows[$i])) {
                $removed++;
            }
        }
        return ['additions' => $added, 'deletions' => $removed];
    }

    /** @return list<TableRow> */
    private function rows(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param TableRow $oldRow
     * @param TableRow $newRow
     * @return list<TableCellDiff>
     */
    private function compareCells(array $oldRow, array $newRow): array
    {
        $diffs = [];
        foreach (array_unique([...array_keys($oldRow), ...array_keys($newRow)]) as $col) {
            $oldVal = (string)($oldRow[$col] ?? '');
            $newVal = (string)($newRow[$col] ?? '');
            if ($oldVal !== $newVal) {
                $diffs[] = ['col' => $col, 'old' => $oldVal, 'new' => $newVal];
            }
        }
        return $diffs;
    }
}
