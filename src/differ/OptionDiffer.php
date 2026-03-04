<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\SingleOptionFieldData;

/**
 * Diff for option fields (Dropdown, Radio, Checkboxes, MultiSelect).
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
            return sprintf(
                '<span class="delta-del">%s</span> → <span class="delta-ins">%s</span>',
                htmlspecialchars($oldLabels ?: '(none)', ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($newLabels ?: '(none)', ENT_QUOTES, 'UTF-8'),
            );
        }

        $oldArr = is_array($oldLabels) ? $oldLabels : [$oldLabels];
        $newArr = is_array($newLabels) ? $newLabels : [$newLabels];

        $added = array_diff($newArr, $oldArr);
        $removed = array_diff($oldArr, $newArr);

        $lines = [];
        foreach ($removed as $label) {
            $lines[] = sprintf(
                '<div class="delta-relation-removed">- %s</div>',
                htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            );
        }
        foreach ($added as $label) {
            $lines[] = sprintf(
                '<div class="delta-relation-added">+ %s</div>',
                htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            );
        }

        return implode("\n", $lines);
    }

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
     * Extract display labels from single or multi-option field data.
     *
     * @return string|string[]
     */
    private function resolveLabels(mixed $value): string|array
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
