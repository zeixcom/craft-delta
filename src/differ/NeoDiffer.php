<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use Craft;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use zeixcom\craftdelta\enums\DiffChangeType;
use zeixcom\craftdelta\i18n\TranslationKeys;

/**
 * Diffs a Neo field (benf\neo\Field) the way MatrixDiffer diffs Matrix: index
 * blocks by canonicalId, classify added/removed/modified, and flag a structure
 * change when block order OR nesting level differs (Neo's hierarchy — the one
 * thing Matrix lacks).
 *
 * Neo blocks are craft\base\Element instances, so this references no benf\neo\*
 * class (getType() is duck-typed) — Neo stays an optional, un-required
 * integration like CKEditor, present only as a ::class string in the differ map.
 *
 * ponytail: read-only block visualization + whole-draft (publish) apply only.
 * Per-block granular accept uses Matrix-specific `matrix-block:` atoms, so the
 * templates suppress atom controls for Neo. Teaching MergeService to apply Neo
 * blocks one atom at a time is the upgrade path if reviewers need it.
 *
 * @phpstan-import-type DiffStats from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type MatrixBlockFieldChange from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type MatrixBlockChange from \zeixcom\craftdelta\types\ArrayTypes
 */
class NeoDiffer implements DifferInterface
{
    public function __construct(
        private readonly NestedFieldDiffInterface $nestedFieldDiff,
    ) {
    }

    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        $oldById = $this->indexByCanonicalId($this->toBlocks($oldValue));
        $newById = $this->indexByCanonicalId($this->toBlocks($newValue));
        /** @var list<MatrixBlockChange> $changes */
        $changes = [];

        foreach ($oldById as $id => $block) {
            if (!isset($newById[$id])) {
                $changes[] = $this->blockChange($block, DiffChangeType::Removed, false);
            }
        }

        foreach ($newById as $id => $block) {
            if (!isset($oldById[$id])) {
                $changes[] = $this->blockChange($block, DiffChangeType::Added, true);
            } elseif ($fieldChanges = $this->collectFieldChanges($block, fn(FieldInterface $field): array => [
                $oldById[$id]->getFieldValue($field->handle),
                $block->getFieldValue($field->handle),
            ])) {
                $changes[] = [
                    'type' => DiffChangeType::Modified->value,
                    'blockUid' => (string)$block->canonicalUid,
                    'blockType' => $this->blockTypeName($block),
                    'summary' => $this->summarize($block),
                    'fieldChanges' => $fieldChanges,
                ];
            }
        }

        if ($this->structureChanged($oldById, $newById)) {
            $changes[] = ['type' => DiffChangeType::Reordered->value];
        }

        return $changes === [] ? null : json_encode($changes, JSON_THROW_ON_ERROR);
    }

    /** @return DiffStats */
    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        $oldIds = array_keys($this->indexByCanonicalId($this->toBlocks($oldValue)));
        $newIds = array_keys($this->indexByCanonicalId($this->toBlocks($newValue)));
        return [
            'additions' => count(array_diff($newIds, $oldIds)),
            'deletions' => count(array_diff($oldIds, $newIds)),
        ];
    }

    /**
     * Neo returns its value as a block query, an element collection, or a raw
     * array depending on element state — normalize to a flat list of blocks.
     *
     * @return list<Element>
     */
    private function toBlocks(mixed $value): array
    {
        $items = match (true) {
            $value instanceof ElementQueryInterface => $value->status(null)->all(),
            $value instanceof ElementCollection => $value->all(),
            is_array($value) => $value,
            default => [],
        };
        return array_values(array_filter($items, static fn($b): bool => $b instanceof Element));
    }

    /** @param list<Element> $blocks @return array<int, Element> */
    private function indexByCanonicalId(array $blocks): array
    {
        $map = [];
        foreach ($blocks as $block) {
            $cid = $block->canonicalId;
            if ($cid === null) {
                continue;
            }
            if (isset($map[$cid])) {
                Craft::warning("NeoDiffer: duplicate canonicalId $cid — block {$block->id} overwrites {$map[$cid]->id}", __METHOD__);
            }
            $map[$cid] = $block;
        }
        return $map;
    }

    /**
     * Structure changed if the surviving blocks' order differs, or any surviving
     * block's nesting level changed (Neo indent/outdent).
     *
     * @param array<int, Element> $oldById
     * @param array<int, Element> $newById
     */
    private function structureChanged(array $oldById, array $newById): bool
    {
        $oldOrder = array_keys($oldById);
        $newOrder = array_keys($newById);
        if (array_values(array_intersect($oldOrder, $newOrder)) !== array_values(array_intersect($newOrder, $oldOrder))) {
            return true;
        }
        foreach ($newById as $id => $block) {
            if (isset($oldById[$id]) && $oldById[$id]->level !== $block->level) {
                return true;
            }
        }
        return false;
    }

    /** @return MatrixBlockChange */
    private function blockChange(Element $block, DiffChangeType $type, bool $isNew): array
    {
        $change = [
            'type' => $type->value,
            'blockUid' => (string)$block->canonicalUid,
            'blockType' => $this->blockTypeName($block),
            'summary' => $this->summarize($block),
        ];
        if ($fieldChanges = $this->collectFieldChanges($block, fn(FieldInterface $field): array => $isNew
            ? [null, $block->getFieldValue($field->handle)]
            : [$block->getFieldValue($field->handle), null])) {
            $change['fieldChanges'] = $fieldChanges;
        }
        return $change;
    }

    /**
     * Walk a block's field layout and collect changed fields, recursing through
     * the shared FieldDiffService (so a Neo block's own fields — including nested
     * Matrix/Neo — diff with their proper differ).
     *
     * @param callable(FieldInterface): array{0: mixed, 1: mixed} $valuePair
     * @return list<MatrixBlockFieldChange>
     */
    private function collectFieldChanges(Element $layoutSource, callable $valuePair): array
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

    private function blockTypeName(Element $block): string
    {
        if (method_exists($block, 'getType')) {
            $type = $block->getType();
            if (is_object($type) && property_exists($type, 'name') && $type->name !== null) {
                return (string)$type->name;
            }
        }
        return Craft::t('craft-delta', TranslationKeys::BLOCK);
    }

    private function summarize(Element $block): string
    {
        return mb_substr(strip_tags((string)$block), 0, 80);
    }
}
