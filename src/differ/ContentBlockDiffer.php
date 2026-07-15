<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use zeixcom\craftdelta\enums\DiffChangeType;

/**
 * Diffs a Content Block field (craft\fields\ContentBlock, Craft 5.8+).
 *
 * A Content Block field's value is a single craft\elements\ContentBlock element
 * that always exists — there is nothing to add, remove or reorder — so the only
 * thing worth diffing is its sub-fields. Without this differ the field falls
 * back to ScalarDiffer, which stringifies the element via Element::__toString().
 * A content block has no title, so that yields "Content Block <id>" — and since
 * a draft owns its own copy of the block, the diff read "Content Block 2149 →
 * Content Block 2153": two ids, and none of the actual content changes.
 *
 * Emits the same block-diff JSON as MatrixDiffer (one "modified" block whose
 * fieldChanges are the changed sub-fields), so the existing block renderer in
 * _diff-content.twig draws it — including sub-fields that are themselves rich
 * text or Matrix, which recurse back through FieldDiffService.
 *
 * ponytail: read-only visualization + whole-draft (publish) apply only.
 * Granular accept would need MergeService to re-own the nested block element the
 * way applyMatrixAtoms serializes Matrix blocks — a plain setFieldValue() copy
 * of another element's block is not safe — so the templates suppress atom
 * controls here, as they do for Neo. Teaching MergeService to apply a content
 * block is the upgrade path if reviewers need it.
 *
 * ponytail: collectFieldChanges duplicates MatrixDiffer/NeoDiffer's field-layout
 * walk. Three copies is the point where a shared trait starts paying for itself —
 * worth extracting if a fourth nested differ shows up.
 *
 * @phpstan-import-type DiffStats from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type MatrixBlockFieldChange from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type MatrixBlockChange from \zeixcom\craftdelta\types\ArrayTypes
 */
class ContentBlockDiffer implements DifferInterface
{
    public function __construct(
        private readonly NestedFieldDiffInterface $nestedFieldDiff,
    ) {
    }

    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        $changes = $this->collectFieldChanges($oldValue, $newValue);
        if ($changes === []) {
            return null;
        }

        $layoutSource = $this->toBlock($newValue) ?? $this->toBlock($oldValue);
        /** @var list<MatrixBlockChange> $blocks */
        $blocks = [[
            'type' => DiffChangeType::Modified->value,
            'blockUid' => (string)($layoutSource?->canonicalUid ?? ''),
            'fieldChanges' => $changes,
        ]];

        return json_encode($blocks, JSON_THROW_ON_ERROR);
    }

    /**
     * One block, always present: the changed sub-fields are the whole story.
     *
     * ponytail: this re-walks the sub-fields that diff() just walked, so a
     * changed content block diffs twice (FieldDiffService only calls getStats()
     * when diff() found changes). Fine for the handful of sub-fields a content
     * block holds; memoize the last [old, new] pair if one ever wraps something
     * heavy enough to notice.
     *
     * @return DiffStats
     */
    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        $changed = count($this->collectFieldChanges($oldValue, $newValue));
        return ['additions' => $changed, 'deletions' => $changed];
    }

    /**
     * Walk the block's field layout and diff each sub-field through the shared
     * FieldDiffService, so every sub-field gets its proper differ.
     *
     * @return list<MatrixBlockFieldChange>
     */
    private function collectFieldChanges(mixed $oldValue, mixed $newValue): array
    {
        $old = $this->toBlock($oldValue);
        $new = $this->toBlock($newValue);

        // Prefer the new side's layout: it reflects the current field config.
        $fieldLayout = ($new ?? $old)?->getFieldLayout();
        if ($fieldLayout === null) {
            return [];
        }

        $changes = [];
        foreach ($fieldLayout->getCustomFields() as $field) {
            $fieldDiff = $this->nestedFieldDiff->diff(
                $field,
                $this->subValue($old, $field),
                $this->subValue($new, $field),
            );
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

    /**
     * A revision saved before a sub-field existed has no value for it — treat
     * that as absent rather than letting getFieldValue() throw.
     */
    private function subValue(?Element $block, FieldInterface $field): mixed
    {
        if ($block === null || $field->handle === null) {
            return null;
        }
        try {
            return $block->getFieldValue($field->handle);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Craft hands the value over as the element itself, but a query or a
     * collection is possible depending on element state — normalize to one block.
     */
    private function toBlock(mixed $value): ?Element
    {
        $block = match (true) {
            $value instanceof Element => $value,
            $value instanceof ElementQueryInterface => $value->status(null)->one(),
            $value instanceof ElementCollection => $value->first(),
            default => null,
        };

        return $block instanceof Element ? $block : null;
    }
}
