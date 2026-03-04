<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

/**
 * Diff for Table fields.
 */
class TableDiffer implements DifferInterface
{
    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        $oldRows = is_array($oldValue) ? array_values($oldValue) : [];
        $newRows = is_array($newValue) ? array_values($newValue) : [];

        if ($oldRows === $newRows) {
            return null;
        }

        $changes = [];
        $maxRows = max(count($oldRows), count($newRows));

        for ($i = 0; $i < $maxRows; $i++) {
            $oldRow = $oldRows[$i] ?? null;
            $newRow = $newRows[$i] ?? null;

            if ($oldRow === null && $newRow !== null) {
                $changes[] = [
                    'type' => 'added',
                    'row' => $i + 1,
                    'values' => $newRow,
                ];
            } elseif ($newRow === null && $oldRow !== null) {
                $changes[] = [
                    'type' => 'removed',
                    'row' => $i + 1,
                    'values' => $oldRow,
                ];
            } elseif ($oldRow !== null && $newRow !== null && $oldRow !== $newRow) {
                $changes[] = [
                    'type' => 'modified',
                    'row' => $i + 1,
                    'cells' => $this->compareCells($oldRow, $newRow),
                ];
            }
        }

        if (empty($changes)) {
            return null;
        }

        return json_encode($changes, JSON_THROW_ON_ERROR);
    }

    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        $oldRows = is_array($oldValue) ? $oldValue : [];
        $newRows = is_array($newValue) ? $newValue : [];

        $added = 0;
        $removed = 0;
        $maxRows = max(count($oldRows), count($newRows));

        for ($i = 0; $i < $maxRows; $i++) {
            if (!isset($oldRows[$i])) {
                $added++;
            } elseif (!isset($newRows[$i])) {
                $removed++;
            }
        }

        return [
            'additions' => $added,
            'deletions' => $removed,
        ];
    }

    /**
     * Compare cells within a row and return per-cell diffs.
     *
     * @return array<int, array{col: string, old: string, new: string}>
     */
    private function compareCells(array $oldRow, array $newRow): array
    {
        $allColumns = array_unique(array_merge(
            array_keys($oldRow),
            array_keys($newRow),
        ));

        $cellDiffs = [];

        foreach ($allColumns as $col) {
            $oldVal = (string)($oldRow[$col] ?? '');
            $newVal = (string)($newRow[$col] ?? '');

            if ($oldVal !== $newVal) {
                $cellDiffs[] = [
                    'col' => $col,
                    'old' => $oldVal,
                    'new' => $newVal,
                ];
            }
        }

        return $cellDiffs;
    }
}
