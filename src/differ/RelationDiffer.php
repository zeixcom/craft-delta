<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use craft\elements\db\ElementQuery;

/**
 * Diff for relational fields (Entries, Assets, Categories, Tags, Users).
 */
class RelationDiffer implements DifferInterface
{
    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        $oldElements = $this->resolveElements($oldValue);
        $newElements = $this->resolveElements($newValue);

        $oldById = $this->indexById($oldElements);
        $newById = $this->indexById($newElements);

        $added = array_diff_key($newById, $oldById);
        $removed = array_diff_key($oldById, $newById);

        if (empty($added) && empty($removed)) {
            return null;
        }

        $lines = [];

        foreach ($removed as $element) {
            $title = htmlspecialchars((string)$element, ENT_QUOTES, 'UTF-8');
            $lines[] = sprintf('<div class="delta-relation-removed">- %s</div>', $title);
        }

        foreach ($added as $element) {
            $title = htmlspecialchars((string)$element, ENT_QUOTES, 'UTF-8');
            $lines[] = sprintf('<div class="delta-relation-added">+ %s</div>', $title);
        }

        return implode("\n", $lines);
    }

    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        $oldElements = $this->resolveElements($oldValue);
        $newElements = $this->resolveElements($newValue);

        $oldIds = array_map(fn($e) => $e->id, $oldElements);
        $newIds = array_map(fn($e) => $e->id, $newElements);

        return [
            'additions' => count(array_diff($newIds, $oldIds)),
            'deletions' => count(array_diff($oldIds, $newIds)),
        ];
    }

    /**
     * Resolve an ElementQuery or array to a flat array of elements.
     */
    private function resolveElements(mixed $value): array
    {
        if ($value instanceof ElementQuery) {
            return $value->status(null)->all();
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Index elements by their ID for set comparison.
     */
    private function indexById(array $elements): array
    {
        $map = [];
        foreach ($elements as $element) {
            $map[$element->id] = $element;
        }

        return $map;
    }
}
