<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use zeixcom\craftdelta\differ\HtmlDiffer;
use zeixcom\craftdelta\differ\NeoDiffer;
use zeixcom\craftdelta\differ\TextDiffer;
use zeixcom\craftdelta\services\FieldDiffService;

class FieldDiffServiceTest extends TestCase
{
    /**
     * Third-party field types we claim first-class support for must map to a real
     * differ. A typo in the FQN would silently fall back to ScalarDiffer (logged at
     * INFO only), so pin the mapping here. Kernel-free: reads the static default map
     * via reflection, so the optional plugins don't need to be installed.
     */
    public function testThirdPartyTextFieldsMapToRichDiffers(): void
    {
        $map = (new ReflectionClass(FieldDiffService::class))->getDefaultProperties()['differMap'];

        self::assertSame(HtmlDiffer::class, $map['spicyweb\\tinymce\\fields\\TinyMCE'] ?? null);
        self::assertSame(TextDiffer::class, $map['nystudio107\\codefield\\fields\\Code'] ?? null);
    }

    /**
     * Neo (a nested-block field) must route to NeoDiffer, not the ScalarDiffer
     * fallback — otherwise its blocks would render as a single opaque value.
     */
    public function testNeoFieldMapsToNeoDiffer(): void
    {
        $map = (new ReflectionClass(FieldDiffService::class))->getDefaultProperties()['differMap'];

        self::assertSame(NeoDiffer::class, $map['benf\\neo\\Field'] ?? null);
    }
}
