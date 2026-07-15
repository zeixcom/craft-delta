<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Address;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use zeixcom\craftdelta\enums\DiffChangeType;

/**
 * Diffs an Addresses field (craft\fields\Addresses).
 *
 * Addresses are *owned nested elements* (craft\elements\Address is a
 * NestedElementInterface, and the field is an ElementContainerFieldInterface),
 * so this mirrors MatrixDiffer: index by canonicalId, then classify each address
 * as added / removed / modified, plus a reorder signal.
 *
 * Two traps this avoids:
 *
 *  - RelationDiffer would be wrong. Addresses are owned, not related: a draft
 *    gets its own copies with fresh ids, so an id-based relation diff would call
 *    every address removed-and-re-added on every draft.
 *  - Walking only the field layout would be wrong. An address's content lives in
 *    *native* properties (addressLine1, locality, postalCode, …), not custom
 *    fields, so both are diffed here.
 *
 * Without a differ the field fell back to ScalarDiffer, whose value is an
 * AddressQuery — and ElementQuery::__toString() returns the constant string
 * "craft\elements\db\ElementQuery" regardless of content, so *every* address
 * change read as no change at all.
 *
 * ponytail: read-only visualization + whole-draft (publish) apply only, as for
 * Neo and Content Block — granular accept would need MergeService to re-own the
 * nested address elements the way applyMatrixAtoms serializes Matrix blocks.
 *
 * @phpstan-import-type DiffStats from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type MatrixBlockFieldChange from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type MatrixBlockChange from \zeixcom\craftdelta\types\ArrayTypes
 */
class AddressesDiffer implements DifferInterface
{
    /**
     * Content-bearing native attributes, in reading order (label → who → where).
     * Deliberately an allowlist: the alternative — reflecting over every public
     * property — would drag in housekeeping like dateUpdated/sortOrder as fake
     * "changes". AddressesDifferTest pins this against craft\elements\Address, so
     * a new Craft attribute fails the test loudly instead of going unnoticed.
     */
    public const NATIVE_ATTRIBUTES = [
        'title',            // the address's "Label"
        'fullName',
        'firstName',
        'lastName',
        'organization',
        'organizationTaxId',
        'addressLine1',
        'addressLine2',
        'addressLine3',
        'dependentLocality',
        'locality',
        'administrativeArea',
        'postalCode',
        'sortingCode',
        'countryCode',
        'latitude',
        'longitude',
    ];

    public function __construct(
        private readonly NestedFieldDiffInterface $nestedFieldDiff,
        private readonly ScalarDiffer $scalarDiffer,
    ) {
    }

    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        $oldById = $this->indexByCanonicalId($this->toAddresses($oldValue));
        $newById = $this->indexByCanonicalId($this->toAddresses($newValue));
        /** @var list<MatrixBlockChange> $changes */
        $changes = [];

        foreach ($oldById as $id => $address) {
            if (!isset($newById[$id])) {
                $changes[] = $this->addressChange($address, DiffChangeType::Removed, false);
            }
        }

        foreach ($newById as $id => $address) {
            if (!isset($oldById[$id])) {
                $changes[] = $this->addressChange($address, DiffChangeType::Added, true);
            } elseif ($fieldChanges = $this->collectChanges($oldById[$id], $address)) {
                $changes[] = [
                    'type' => DiffChangeType::Modified->value,
                    'blockUid' => (string)$address->canonicalUid,
                    'blockType' => Address::displayName(),
                    'summary' => $this->summarize($address),
                    'fieldChanges' => $fieldChanges,
                ];
            }
        }

        if ($this->reordered($oldById, $newById)) {
            $changes[] = ['type' => DiffChangeType::Reordered->value];
        }

        return $changes === [] ? null : json_encode($changes, JSON_THROW_ON_ERROR);
    }

    /**
     * Added/removed addresses only — a modified one nets zero, matching
     * MatrixDiffer (and keeping this cheap: no re-walk of every attribute).
     *
     * @return DiffStats
     */
    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        $oldIds = array_keys($this->indexByCanonicalId($this->toAddresses($oldValue)));
        $newIds = array_keys($this->indexByCanonicalId($this->toAddresses($newValue)));
        return [
            'additions' => count(array_diff($newIds, $oldIds)),
            'deletions' => count(array_diff($oldIds, $newIds)),
        ];
    }

    /**
     * Craft returns an Addresses value as a query, a collection, or a raw array
     * depending on element state — normalize to a flat list.
     *
     * @return list<Address>
     */
    private function toAddresses(mixed $value): array
    {
        $items = match (true) {
            $value instanceof ElementQueryInterface => $value->status(null)->all(),
            $value instanceof ElementCollection => $value->all(),
            is_array($value) => $value,
            default => [],
        };
        return array_values(array_filter($items, static fn($a): bool => $a instanceof Address));
    }

    /** @param list<Address> $addresses @return array<int, Address> */
    private function indexByCanonicalId(array $addresses): array
    {
        $map = [];
        foreach ($addresses as $address) {
            $cid = $address->canonicalId;
            if ($cid === null) {
                continue;
            }
            if (isset($map[$cid])) {
                Craft::warning(
                    "AddressesDiffer: duplicate canonicalId $cid — address {$address->id} overwrites {$map[$cid]->id}",
                    __METHOD__,
                );
            }
            $map[$cid] = $address;
        }
        return $map;
    }

    /**
     * @param array<int, Address> $oldById
     * @param array<int, Address> $newById
     */
    private function reordered(array $oldById, array $newById): bool
    {
        $oldOrder = array_keys($oldById);
        $newOrder = array_keys($newById);
        return array_values(array_intersect($oldOrder, $newOrder)) !== array_values(array_intersect($newOrder, $oldOrder));
    }

    /** @return MatrixBlockChange */
    private function addressChange(Address $address, DiffChangeType $type, bool $isNew): array
    {
        $change = [
            'type' => $type->value,
            'blockUid' => (string)$address->canonicalUid,
            'blockType' => Address::displayName(),
            'summary' => $this->summarize($address),
        ];
        // One side is absent, so show the whole address as added/removed content.
        if ($fieldChanges = $isNew
            ? $this->collectChanges(null, $address)
            : $this->collectChanges($address, null)) {
            $change['fieldChanges'] = $fieldChanges;
        }
        return $change;
    }

    /**
     * An address's native attributes *and* its custom fields, in that order.
     *
     * @return list<MatrixBlockFieldChange>
     */
    private function collectChanges(?Address $old, ?Address $new): array
    {
        $changes = $this->collectNativeChanges($old, $new);
        foreach ($this->collectFieldChanges($old, $new) as $fieldChange) {
            $changes[] = $fieldChange;
        }
        return $changes;
    }

    /** @return list<MatrixBlockFieldChange> */
    private function collectNativeChanges(?Address $old, ?Address $new): array
    {
        $labelSource = $new ?? $old;
        if ($labelSource === null) {
            return [];
        }

        $changes = [];
        foreach (self::NATIVE_ATTRIBUTES as $attr) {
            $oldAttr = $old?->$attr;
            $newAttr = $new?->$attr;
            $diffHtml = $this->scalarDiffer->diff($oldAttr, $newAttr);
            if ($diffHtml === null) {
                continue;
            }
            $changes[] = [
                'handle' => $attr,
                'label' => $labelSource->getAttributeLabel($attr),
                // No field class: the template renders this through its plain
                // diff-HTML branch, which is what ScalarDiffer emits.
                'fieldType' => '',
                'diffHtml' => $diffHtml,
            ];
        }
        return $changes;
    }

    /**
     * Custom fields on the address's field layout, recursed through the shared
     * FieldDiffService so each gets its proper differ.
     *
     * @return list<MatrixBlockFieldChange>
     */
    private function collectFieldChanges(?Address $old, ?Address $new): array
    {
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
                    'handle' => (string)$field->handle,
                    'label' => (string)$field->name,
                    'fieldType' => $field::class,
                    'diffHtml' => $fieldDiff->diffHtml,
                ];
            }
        }
        return $changes;
    }

    /** An address saved before a custom field existed has no value for it. */
    private function subValue(?Address $address, FieldInterface $field): mixed
    {
        if ($address === null || $field->handle === null) {
            return null;
        }
        try {
            return $address->getFieldValue($field->handle);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The label, falling back to a one-line street/city so the block is identifiable. */
    private function summarize(Address $address): string
    {
        $summary = $address->title
            ?? implode(', ', array_filter([$address->addressLine1, $address->locality]));
        return mb_substr($summary, 0, 80);
    }
}
