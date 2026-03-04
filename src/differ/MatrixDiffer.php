<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use Craft;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use zeixcom\craftdelta\Delta;

/**
 * Diff for Matrix / nested entry fields.
 */
class MatrixDiffer implements DifferInterface
{
    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        $oldEntries = $this->resolveEntries($oldValue);
        $newEntries = $this->resolveEntries($newValue);

        $oldById = $this->indexByCanonicalId($oldEntries);
        $newById = $this->indexByCanonicalId($newEntries);

        $changes = [];

        foreach ($oldById as $id => $entry) {
            if (!isset($newById[$id])) {
                $change = [
                    'type' => 'removed',
                    'blockType' => $entry->type->name ?? 'Block',
                    'summary' => $this->summarizeEntry($entry),
                ];
                $fieldChanges = $this->extractEntryFields($entry, false);
                if (!empty($fieldChanges)) {
                    $change['fieldChanges'] = $fieldChanges;
                }
                $changes[] = $change;
            }
        }

        foreach ($newById as $id => $entry) {
            if (!isset($oldById[$id])) {
                $change = [
                    'type' => 'added',
                    'blockType' => $entry->type->name ?? 'Block',
                    'summary' => $this->summarizeEntry($entry),
                ];
                $fieldChanges = $this->extractEntryFields($entry, true);
                if (!empty($fieldChanges)) {
                    $change['fieldChanges'] = $fieldChanges;
                }
                $changes[] = $change;
            }
        }

        foreach ($newById as $id => $newEntry) {
            if (isset($oldById[$id])) {
                $oldEntry = $oldById[$id];
                $fieldChanges = $this->compareBlockFields($oldEntry, $newEntry);

                if (!empty($fieldChanges)) {
                    $changes[] = [
                        'type' => 'modified',
                        'blockType' => $newEntry->type->name ?? 'Block',
                        'summary' => $this->summarizeEntry($newEntry),
                        'fieldChanges' => $fieldChanges,
                    ];
                }
            }
        }

        $oldOrder = array_keys($oldById);
        $newOrder = array_keys($newById);
        $commonOld = array_values(array_intersect($oldOrder, $newOrder));
        $commonNew = array_values(array_intersect($newOrder, $oldOrder));
        if ($commonOld !== $commonNew) {
            $changes[] = ['type' => 'reordered'];
        }

        if (empty($changes)) {
            return null;
        }

        return json_encode($changes, JSON_THROW_ON_ERROR);
    }

    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        $oldEntries = $this->resolveEntries($oldValue);
        $newEntries = $this->resolveEntries($newValue);

        $oldIds = array_map(fn($e) => $e->canonicalId, $oldEntries);
        $newIds = array_map(fn($e) => $e->canonicalId, $newEntries);

        return [
            'additions' => count(array_diff($newIds, $oldIds)),
            'deletions' => count(array_diff($oldIds, $newIds)),
        ];
    }

    /**
     * Resolve an EntryQuery or array to a flat array of Entry objects.
     *
     * @return Entry[]
     */
    private function resolveEntries(mixed $value): array
    {
        if ($value instanceof EntryQuery) {
            return $value->status(null)->all();
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Index entries by their canonical ID for stable matching across drafts/revisions.
     *
     * @return array<int, Entry>
     */
    private function indexByCanonicalId(array $entries): array
    {
        $map = [];
        foreach ($entries as $entry) {
            $cid = $entry->canonicalId;
            if (isset($map[$cid])) {
                Craft::warning(
                    "MatrixDiffer: duplicate canonicalId $cid — entry {$entry->id} overwrites {$map[$cid]->id}",
                    __METHOD__,
                );
            }
            $map[$cid] = $entry;
        }

        return $map;
    }

    /**
     * Generate a short summary label for a block entry.
     */
    private function summarizeEntry(Entry $entry): string
    {
        return $entry->title ?? mb_substr(strip_tags((string)$entry), 0, 80);
    }

    /**
     * Extract all non-empty field values from an entry, diffing each against null.
     *
     * @return array<int, array{handle: string, label: string, fieldType: string, diffHtml: ?string}>
     */
    private function extractEntryFields(Entry $entry, bool $isNew): array
    {
        $fieldLayout = $entry->getFieldLayout();
        if (!$fieldLayout) {
            return [];
        }

        $plugin = Delta::getInstance();
        if ($plugin === null) {
            return [];
        }

        $fields = [];
        foreach ($fieldLayout->getCustomFields() as $field) {
            $value = $entry->getFieldValue($field->handle);

            $oldVal = $isNew ? null : $value;
            $newVal = $isNew ? $value : null;

            try {
                $fieldDiff = $plugin->fieldDiff->diff($field, $oldVal, $newVal);
                if ($fieldDiff !== null && $fieldDiff->hasChanges) {
                    $fields[] = [
                        'handle' => $field->handle,
                        'label' => $field->name,
                        'fieldType' => get_class($field),
                        'diffHtml' => $fieldDiff->diffHtml,
                    ];
                }
            } catch (\Exception $e) {
                Craft::warning(
                    "MatrixDiffer: failed to diff field '{$field->handle}': {$e->getMessage()}",
                    __METHOD__,
                );
            }
        }

        return $fields;
    }

    /**
     * Compare sub-fields within a block using FieldDiffService.
     *
     * @return array<int, array{handle: string, label: string, fieldType: string, diffHtml: ?string}>
     */
    private function compareBlockFields(Entry $old, Entry $new): array
    {
        $fieldLayout = $new->getFieldLayout();
        if (!$fieldLayout) {
            return [];
        }

        $changedFields = [];
        $plugin = Delta::getInstance();

        foreach ($fieldLayout->getCustomFields() as $field) {
            if ($plugin === null) {
                continue;
            }

            $oldVal = $old->getFieldValue($field->handle);
            $newVal = $new->getFieldValue($field->handle);

            $fieldDiff = $plugin->fieldDiff->diff($field, $oldVal, $newVal);
            if ($fieldDiff === null || !$fieldDiff->hasChanges) {
                continue;
            }
            $changedFields[] = [
                'handle' => $field->handle,
                'label' => $field->name,
                'fieldType' => get_class($field),
                'diffHtml' => $fieldDiff->diffHtml,
            ];
        }

        return $changedFields;
    }
}
