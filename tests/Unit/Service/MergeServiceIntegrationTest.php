<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for MergeService::merge end-to-end.
 *
 * These exercise the parts that genuinely require a booted Craft kernel
 * (createDraft/saveElement/applyDraft, permissions, multisite, field-type
 * fidelity), which the plugin's plain-PHPUnit setup does not provide.
 *
 * The PURE contracts these scenarios depend on are now covered by real unit
 * tests in MergeServiceTest, so they no longer rely solely on a kernel:
 *   - atom parsing and validation
 *   - stale-atom detection (testValidateAtomsThrowsStaleWhenAcceptedAtomNoLongerOffered)
 *   - available-atom collection (testCollectAvailableAtoms...)
 *   - Matrix survivor set and ordering
 *   - Craft setFieldValue payload shape (testBuildMatrixSetValue...)
 *
 * When kernel boot is added, remove the markTestSkipped() calls and fill in
 * the bodies. The scenarios are listed in spec section 9.2.
 */
class MergeServiceIntegrationTest extends TestCase
{
    public function testMergeEndToEndWithFieldAndMatrixAtoms(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot in plugin test setup.');
    }

    public function testMergeRejectsStaleAtomsAfterCanonicalEdit(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot in plugin test setup.');
    }

    public function testMergeRequiresCreateEntryDraftsPermission(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot in plugin test setup.');
    }

    public function testMergeMultisiteIsolation(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot in plugin test setup.');
    }

    public function testMergeAttributeApplyForTitleAndSlug(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot in plugin test setup.');
    }

    public function testMergeFieldTypeFidelity(): void
    {
        $this->markTestSkipped('CKEditor with embeds, Asset with focals, Money with currency, Table.');
    }
}
