<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use zeixcom\craftdelta\enums\DiffChangeType;
use zeixcom\craftdelta\helpers\MatrixValue;
use zeixcom\craftdelta\i18n\TranslationKeys;

/**
 * @phpstan-import-type DiffStats from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type MatrixBlockFieldChange from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type MatrixBlockChange from \zeixcom\craftdelta\types\ArrayTypes
 */
class MatrixDiffer implements DifferInterface
{
    public function __construct(
        private readonly NestedFieldDiffInterface $nestedFieldDiff,
    ) {
    }

    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        $oldById = $this->indexByCanonicalId(MatrixValue::toEntries($oldValue));
        $newById = $this->indexByCanonicalId(MatrixValue::toEntries($newValue));
        /** @var list<MatrixBlockChange> $changes */
        $changes = [];
        // unchanged blocks are context only, so they don't count as a change
        $hasRealChange = false;

        foreach ($oldById as $id => $entry) {
            if (!isset($newById[$id])) {
                $changes[] = $this->blockChange($entry, DiffChangeType::Removed, false);
                $hasRealChange = true;
            }
        }

        foreach ($newById as $id => $entry) {
            if (!isset($oldById[$id])) {
                $changes[] = $this->blockChange($entry, DiffChangeType::Added, true);
                $hasRealChange = true;
            } elseif ($fieldChanges = $this->collectFieldChanges($entry, fn(FieldInterface $field): array => [
                $oldById[$id]->getFieldValue($field->handle),
                $entry->getFieldValue($field->handle),
            ])) {
                $changes[] = [
                    'type' => DiffChangeType::Modified->value,
                    'blockUid' => $entry->canonicalUid,
                    'blockType' => $entry->type->name ?? Craft::t('craft-delta', TranslationKeys::BLOCK),
                    'summary' => $this->summarizeEntry($entry),
                    'fieldChanges' => $fieldChanges,
                ];
                $hasRealChange = true;
            } else {
                // context block: no fieldChanges, no atom
                $changes[] = [
                    'type' => DiffChangeType::Unchanged->value,
                    'blockUid' => $entry->canonicalUid,
                    'blockType' => $entry->type->name ?? Craft::t('craft-delta', TranslationKeys::BLOCK),
                    'summary' => $this->summarizeEntry($entry),
                ];
            }
        }

        $oldOrder = array_keys($oldById);
        $newOrder = array_keys($newById);
        if (array_values(array_intersect($oldOrder, $newOrder)) !== array_values(array_intersect($newOrder, $oldOrder))) {
            $changes[] = ['type' => DiffChangeType::Reordered->value];
            $hasRealChange = true;
        }

        return $hasRealChange ? json_encode($changes, JSON_THROW_ON_ERROR) : null;
    }

    /** @return DiffStats */
    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        $oldIds = array_map(static fn(Entry $e) => $e->canonicalId, MatrixValue::toEntries($oldValue));
        $newIds = array_map(static fn(Entry $e) => $e->canonicalId, MatrixValue::toEntries($newValue));
        return [
            'additions' => count(array_diff($newIds, $oldIds)),
            'deletions' => count(array_diff($oldIds, $newIds)),
        ];
    }

    /** @param list<Entry> $entries @return array<int, Entry> */
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

    private function summarizeEntry(Entry $entry): string
    {
        return $entry->title ?? mb_substr(strip_tags((string)$entry), 0, 80);
    }

    /** @return MatrixBlockChange */
    private function blockChange(Entry $entry, DiffChangeType $type, bool $isNew): array
    {
        $change = [
            'type' => $type->value,
            'blockUid' => $entry->canonicalUid,
            'blockType' => $entry->type->name ?? Craft::t('craft-delta', TranslationKeys::BLOCK),
            'summary' => $this->summarizeEntry($entry),
        ];
        if ($fieldChanges = $this->collectFieldChanges($entry, fn(FieldInterface $field): array => $isNew
            ? [null, $entry->getFieldValue($field->handle)]
            : [$entry->getFieldValue($field->handle), null])) {
            $change['fieldChanges'] = $fieldChanges;
        }
        return $change;
    }

    /**
     * Walk a block's field layout and collect the changed fields. The value
     * picker supplies the [old, new] pair to diff for each field, so the same
     * walk serves added/removed blocks (one side null) and modified blocks.
     *
     * @param callable(FieldInterface): array{0: mixed, 1: mixed} $valuePair
     * @return list<MatrixBlockFieldChange>
     */
    private function collectFieldChanges(Entry $layoutSource, callable $valuePair): array
    {
        $fieldLayout = $layoutSource->getFieldLayout();
        if (!$fieldLayout) {
            return [];
        }

        $changes = [];
        foreach ($fieldLayout->getCustomFields() as $field) {
            [$old, $new] = $valuePair($field);
            $fieldDiff = $this->nestedFieldDiff->diff($field, $old, $new);
            if ($fieldDiff?->hasChanges) {
                $changes[] = [
                    'handle' => $field->handle,
                    'label' => $field->name,
                    'fieldType' => $field::class,
                    'diffHtml' => $fieldDiff->diffHtml,
                ];
            }
        }
        return $changes;
    }
}
