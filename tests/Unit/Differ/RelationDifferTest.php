<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Differ;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\differ\RelationDiffer;

class RelationDifferTest extends TestCase
{
    private RelationDiffer $differ;

    /** @var array<int, ElementInterface> */
    private array $hydrationStubs = [];

    /** @var array<int, string|null> */
    private array $thumbStubs = [];

    protected function setUp(): void
    {
        $this->hydrationStubs = [];
        $this->thumbStubs = [];

        $this->differ = new class($this) extends RelationDiffer {
            public function __construct(private RelationDifferTest $test)
            {
            }

            protected function lookupElementById(int $id): ?ElementInterface
            {
                return $this->test->stubElementById($id);
            }

            protected function lookupAssetThumbUrl(Asset $asset): ?string
            {
                return $this->test->stubThumbUrlFor($asset);
            }
        };
    }

    public function stubElementById(int $id): ?ElementInterface
    {
        return $this->hydrationStubs[$id] ?? null;
    }

    public function stubThumbUrlFor(Asset $asset): ?string
    {
        return $this->thumbStubs[$asset->id] ?? null;
    }

    private function makeEntryStub(int $id, string $title): Entry
    {
        $entry = $this->getMockBuilder(Entry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__toString'])
            ->getMock();
        $entry->id = $id;
        $entry->title = $title;
        $entry->method('__toString')->willReturn($title);

        return $entry;
    }

    private function skipUnlessCraftKernel(): void
    {
        $this->markTestSkipped('Asset rendering needs a Craft kernel — Yii magic getters reach Craft::$app.');
    }


    public function testBothNullReturnsNull(): void
    {
        $this->assertNull($this->differ->diff(null, null));
    }

    public function testBothEmptyArraysReturnsNull(): void
    {
        $this->assertNull($this->differ->diff([], []));
    }

    public function testEmptyToNullReturnsNull(): void
    {
        $this->assertNull($this->differ->diff([], null));
    }

    public function testIdenticalSetsReturnNull(): void
    {
        $a = $this->makeEntryStub(1, 'A');
        $b = $this->makeEntryStub(2, 'B');
        $this->assertNull($this->differ->diff([$a, $b], [$a, $b]));
    }

    public function testSingleAddedElementRendersAddedLine(): void
    {
        $entry = $this->makeEntryStub(42, 'New Entry');
        $result = $this->differ->diff([], [$entry]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('delta-relation-added', $result);
        $this->assertStringContainsString('+ New Entry', $result);
        $this->assertStringNotContainsString('delta-relation-removed', $result);
    }

    public function testSingleRemovedElementRendersRemovedLine(): void
    {
        $entry = $this->makeEntryStub(42, 'Old Entry');
        $result = $this->differ->diff([$entry], []);

        $this->assertNotNull($result);
        $this->assertStringContainsString('delta-relation-removed', $result);
        $this->assertStringContainsString('- Old Entry', $result);
        $this->assertStringNotContainsString('delta-relation-added', $result);
    }

    public function testSetDifferenceRendersBothLines(): void
    {
        $kept = $this->makeEntryStub(1, 'Kept');
        $removed = $this->makeEntryStub(2, 'Gone');
        $added = $this->makeEntryStub(3, 'Fresh');

        $result = $this->differ->diff([$kept, $removed], [$kept, $added]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('- Gone', $result);
        $this->assertStringContainsString('+ Fresh', $result);
        $this->assertStringNotContainsString('Kept', $result);
    }

    public function testTitleHtmlIsEscaped(): void
    {
        $entry = $this->makeEntryStub(1, '<script>alert("xss")</script>');
        $result = $this->differ->diff([], [$entry]);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testElementCollectionIsResolved(): void
    {
        $entry = $this->makeEntryStub(7, 'Collection Entry');
        $collection = new ElementCollection([$entry]);

        $result = $this->differ->diff(new ElementCollection(), $collection);

        $this->assertNotNull($result);
        $this->assertStringContainsString('+ Collection Entry', $result);
    }

    public function testElementCollectionsCompareAsSets(): void
    {
        $a = $this->makeEntryStub(1, 'A');
        $b = $this->makeEntryStub(2, 'B');
        $c = $this->makeEntryStub(3, 'C');

        $result = $this->differ->diff(
            new ElementCollection([$a, $b]),
            new ElementCollection([$b, $c]),
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('- A', $result);
        $this->assertStringContainsString('+ C', $result);
        $this->assertStringNotContainsString('B', $result);
    }

    public function testRawIntegerIdsAreHydrated(): void
    {
        $entry = $this->makeEntryStub(900, 'Hydrated Entry');
        $this->hydrationStubs[900] = $entry;

        $result = $this->differ->diff([], [900]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('+ Hydrated Entry', $result);
        $this->assertStringNotContainsString('+ 900', $result, 'Raw IDs must not leak through to the rendered output');
    }

    public function testRawNumericStringIdsAreHydrated(): void
    {
        $entry = $this->makeEntryStub(900, 'Hydrated Entry');
        $this->hydrationStubs[900] = $entry;

        $result = $this->differ->diff([], ['900']);

        $this->assertNotNull($result);
        $this->assertStringContainsString('+ Hydrated Entry', $result);
    }

    public function testRawIdsThatFailToHydrateAreSkipped(): void
    {
        $result = $this->differ->diff([], [12345]);
        $this->assertNull($result);
    }

    public function testMixedRawIdsAndElementsBothResolve(): void
    {
        $hydrated = $this->makeEntryStub(900, 'Hydrated');
        $direct = $this->makeEntryStub(901, 'Direct');
        $this->hydrationStubs[900] = $hydrated;

        $result = $this->differ->diff([], [900, $direct]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('+ Hydrated', $result);
        $this->assertStringContainsString('+ Direct', $result);
    }

    public function testElementsWithNullIdAreIgnored(): void
    {
        $valid = $this->makeEntryStub(1, 'Real');
        $orphan = $this->makeEntryStub(0, 'Orphan');
        $orphan->id = null;

        $result = $this->differ->diff([], [$valid, $orphan]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('+ Real', $result);
        $this->assertStringNotContainsString('Orphan', $result);
    }

    public function testAssetRendersThumbnailMarkup(): void
    {
        $this->skipUnlessCraftKernel();
    }
    public function testAssetWithoutThumbUrlRendersEmptyPlaceholder(): void
    {
        $this->skipUnlessCraftKernel();
    }
    public function testAssetFilenameIsHtmlEscaped(): void
    {
        $this->skipUnlessCraftKernel();
    }
    public function testNonImageAssetSkipsDimensionsButShowsSize(): void
    {
        $this->skipUnlessCraftKernel();
    }
    public function testRemovedAssetUsesRemovedClass(): void
    {
        $this->skipUnlessCraftKernel();
    }

    public function testStatsForIdenticalSetsAreZero(): void
    {
        $a = $this->makeEntryStub(1, 'A');
        $stats = $this->differ->getStats([$a], [$a]);
        $this->assertSame(0, $stats['additions']);
        $this->assertSame(0, $stats['deletions']);
    }

    public function testStatsForAddedElement(): void
    {
        $a = $this->makeEntryStub(1, 'A');
        $stats = $this->differ->getStats([], [$a]);
        $this->assertSame(1, $stats['additions']);
        $this->assertSame(0, $stats['deletions']);
    }

    public function testStatsForRemovedElement(): void
    {
        $a = $this->makeEntryStub(1, 'A');
        $stats = $this->differ->getStats([$a], []);
        $this->assertSame(0, $stats['additions']);
        $this->assertSame(1, $stats['deletions']);
    }

    public function testStatsForSwappedElement(): void
    {
        $a = $this->makeEntryStub(1, 'A');
        $b = $this->makeEntryStub(2, 'B');
        $stats = $this->differ->getStats([$a], [$b]);
        $this->assertSame(1, $stats['additions']);
        $this->assertSame(1, $stats['deletions']);
    }

    public function testStatsForElementCollections(): void
    {
        $a = $this->makeEntryStub(1, 'A');
        $b = $this->makeEntryStub(2, 'B');
        $stats = $this->differ->getStats(
            new ElementCollection([$a]),
            new ElementCollection([$a, $b]),
        );
        $this->assertSame(1, $stats['additions']);
        $this->assertSame(0, $stats['deletions']);
    }
}
