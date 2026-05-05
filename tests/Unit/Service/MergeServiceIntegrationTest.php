<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for MergeService::merge end-to-end.
 *
 * These tests require Craft kernel boot, which the plugin's test setup does
 * not yet provide (matches the existing `RelationDifferTest` pattern where
 * five Asset rendering tests are also skipped pending kernel bootstrap).
 *
 * When kernel boot is added, remove the markTestSkipped() calls and fill in
 * the test bodies. The scenarios are listed in spec §9.2.
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
