<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\models;

use craft\base\Model;

/**
 * @phpstan-import-type AggregateDiffStats from \zeixcom\craftdelta\types\ArrayTypes
 */
class DiffResult extends Model
{
    /** @var FieldDiff[] */
    public array $fieldDiffs = [];

    /** @return AggregateDiffStats */
    public function getStats(): array
    {
        $fieldsChanged = $additions = $deletions = 0;
        foreach ($this->fieldDiffs as $diff) {
            if (!$diff->hasChanges) {
                continue;
            }
            $fieldsChanged++;
            $additions += $diff->stats['additions'];
            $deletions += $diff->stats['deletions'];
        }
        return ['fieldsChanged' => $fieldsChanged, 'additions' => $additions, 'deletions' => $deletions];
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
