<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\models;

use craft\base\Model;

/**
 * @phpstan-import-type AggregateDiffStats from \zeixcom\craftdelta\types\ArrayTypes
 */
class DiffResult extends Model
{
    /**
     * @var FieldDiff[]
     */
    public array $fieldDiffs = [];

    public ?int $olderRevisionId = null;
    public ?int $newerRevisionId = null;
    public ?int $olderRevisionNum = null;
    public ?int $newerRevisionNum = null;

    /** @return AggregateDiffStats */
    public function getStats(): array
    {
        $totalAdded = 0;
        $totalRemoved = 0;
        $fieldsChanged = 0;

        foreach ($this->fieldDiffs as $diff) {
            if ($diff->hasChanges) {
                $fieldsChanged++;
                $totalAdded += $diff->stats['additions'] ?? 0;
                $totalRemoved += $diff->stats['deletions'] ?? 0;
            }
        }

        return [
            'fieldsChanged' => $fieldsChanged,
            'additions' => $totalAdded,
            'deletions' => $totalRemoved,
        ];
    }

    public function hasChanges(): bool
    {
        foreach ($this->fieldDiffs as $diff) {
            if ($diff->hasChanges) {
                return true;
            }
        }

        return false;
    }
}
