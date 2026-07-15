<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Differ;

use CommerceGuys\Addressing\AddressFormat\AddressField;
use craft\elements\Address;
use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\differ\AddressesDiffer;

/**
 * AddressesDiffer diffs an address's *native* properties (its content lives
 * there, not in custom fields) from a hand-maintained allowlist. These tests
 * pin that list against craft\elements\Address so it can't silently rot: an
 * attribute Craft adds — or one we typo — would otherwise just never show up in
 * a diff, which is the exact failure this differ exists to fix.
 *
 * Kernel-free: reflection only, no Craft app booted.
 */
class AddressesDifferTest extends TestCase
{
    /**
     * Every attribute we claim to diff must actually exist on Address —
     * a typo would silently drop that attribute from every diff.
     */
    public function testEveryNativeAttributeExistsOnAddress(): void
    {
        foreach (AddressesDiffer::NATIVE_ATTRIBUTES as $attr) {
            self::assertTrue(
                property_exists(Address::class, $attr) || $attr === 'title',
                "AddressesDiffer::NATIVE_ATTRIBUTES lists '$attr', which is not a property of craft\\elements\\Address",
            );
        }
    }

    /** The allowlist must not contain duplicates (a duplicate renders twice). */
    public function testNativeAttributesAreUnique(): void
    {
        $attrs = AddressesDiffer::NATIVE_ATTRIBUTES;
        self::assertSame(array_values(array_unique($attrs)), array_values($attrs));
    }

    /**
     * The reverse guard, and the one that matters: every field of the addressing
     * spec that Craft exposes as a property must be diffed. This is where real
     * growth happens — addressLine3 arrived in Craft 5.x this way — and
     * AddressField's values are documented to match Address's property names.
     *
     * Deliberately *not* "every public property of Address minus a denylist":
     * Address inherits dozens of plumbing props (isProvisionalDraft,
     * updateSearchIndexImmediately, …), so that denylist would need padding on
     * every Craft upgrade and would rot into noise instead of a real check.
     */
    public function testEverySpecAddressFieldIsDiffed(): void
    {
        foreach (AddressField::getAll() as $field) {
            // The spec has givenName/additionalName/familyName; Craft models
            // those with NameTrait's fullName/firstName/lastName instead.
            if (!property_exists(Address::class, $field)) {
                continue;
            }

            self::assertContains(
                $field,
                AddressesDiffer::NATIVE_ATTRIBUTES,
                "craft\\elements\\Address has the address-spec property '$field', but AddressesDiffer "
                . 'never diffs it — changes to it would be invisible. Add it to NATIVE_ATTRIBUTES.',
            );
        }
    }

    /** The name properties Craft uses in place of the spec's given/family names. */
    public function testCraftNamePropertiesAreDiffed(): void
    {
        foreach (['fullName', 'firstName', 'lastName'] as $attr) {
            self::assertContains($attr, AddressesDiffer::NATIVE_ATTRIBUTES);
        }
    }
}
