<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\SingleOptionFieldData;
use zeixcom\craftdelta\helpers\DiffHtml;

/**
 * @phpstan-import-type DiffStats from \zeixcom\craftdelta\types\ArrayTypes
 */
class OptionDiffer implements DifferInterface
{
    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        $oldLabels = $this->resolveLabels($oldValue);
        $newLabels = $this->resolveLabels($newValue);

        if ($oldLabels === $newLabels) {
            return null;
        }

        if (is_string($oldLabels) && is_string($newLabels)) {
            return DiffHtml::scalarChange($oldLabels ?: '(none)', $newLabels ?: '(none)');
        }

        $oldArr = is_array($oldLabels) ? $oldLabels : [$oldLabels];
        $newArr = is_array($newLabels) ? $newLabels : [$newLabels];

        $lines = [
            ...array_map(
                static fn(string $label) => DiffHtml::relationLine($label, false),
                array_diff($oldArr, $newArr),
            ),
            ...array_map(
                static fn(string $label) => DiffHtml::relationLine($label, true),
                array_diff($newArr, $oldArr),
            ),
        ];

        // Same selections in a different order: no membership change to show.
        return $lines === [] ? null : implode("\n", $lines);
    }

    /** @return DiffStats */
    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        $oldLabels = $this->resolveLabels($oldValue);
        $newLabels = $this->resolveLabels($newValue);

        if ($oldLabels === $newLabels) {
            return ['additions' => 0, 'deletions' => 0];
        }

        if (is_string($oldLabels) && is_string($newLabels)) {
            return ['additions' => 1, 'deletions' => 1];
        }

        $oldArr = is_array($oldLabels) ? $oldLabels : [$oldLabels];
        $newArr = is_array($newLabels) ? $newLabels : [$newLabels];

        return [
            'additions' => count(array_diff($newArr, $oldArr)),
            'deletions' => count(array_diff($oldArr, $newArr)),
        ];
    }

    /**
     * @param SingleOptionFieldData|MultiOptionsFieldData|list<string>|string|null $value
     * @return string|list<string>
     */
    private function resolveLabels(SingleOptionFieldData|MultiOptionsFieldData|array|string|null $value): string|array
    {
        if ($value instanceof SingleOptionFieldData) {
            return $value->label ?? (string)$value;
        }

        if ($value instanceof MultiOptionsFieldData) {
            $labels = [];
            foreach ($value as $option) {
                $labels[] = $option->label ?? (string)$option;
            }

            return $labels;
        }

        if (is_array($value)) {
            return $value;
        }

        return (string)($value ?? '');
    }
}
