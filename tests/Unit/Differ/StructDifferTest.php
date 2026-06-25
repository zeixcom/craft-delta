<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Differ;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\differ\StructDiffer;
use zeixcom\craftdelta\differ\TextDiffer;

class StructDifferTest extends TestCase
{
    private StructDiffer $differ;

    protected function setUp(): void
    {
        $this->differ = new StructDiffer(new TextDiffer(3));
    }

    public function testIdenticalStructsReturnNull(): void
    {
        $v = ['title' => 'Home', 'description' => 'Welcome'];
        $this->assertNull($this->differ->diff($v, $v));
    }

    public function testChangedAttributeIsDiffed(): void
    {
        $html = $this->differ->diff(
            ['title' => 'Old Title', 'description' => 'Same'],
            ['title' => 'New Title', 'description' => 'Same'],
        );
        $this->assertNotNull($html);
        $this->assertStringContainsString('Old', $html);
        $this->assertStringContainsString('New', $html);
    }

    public function testEmptyAndNullAttributesAreNotNoise(): void
    {
        // '' and null both drop out, so these two structs are equivalent.
        $this->assertNull($this->differ->diff(
            ['title' => 'X', 'canonical' => '', 'robots' => []],
            ['title' => 'X', 'canonical' => null],
        ));
    }

    public function testNestedArraysFlattenByDottedKey(): void
    {
        $html = $this->differ->diff(
            ['openGraph' => ['title' => 'A']],
            ['openGraph' => ['title' => 'B']],
        );
        $this->assertNotNull($html);
        $this->assertStringContainsString('A', $html);
        $this->assertStringContainsString('B', $html);
    }

    public function testTraversableCollectionsAreIterated(): void
    {
        // mimics a link collection (Hyper's LinkCollection) of per-link structs
        $old = new \ArrayObject([['url' => '/home', 'text' => 'Home']]);
        $new = new \ArrayObject([['url' => '/home', 'text' => 'Start']]);
        $html = $this->differ->diff($old, $new);
        $this->assertNotNull($html);
        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('Start', $html);
    }

    public function testObjectValuesUsePublicProperties(): void
    {
        // mimics a model value (e.g. Beacon's SeoMeta) with public props
        $old = (object)['title' => 'Alpha', 'robots' => ['index']];
        $new = (object)['title' => 'Beta', 'robots' => ['index']];
        $html = $this->differ->diff($old, $new);
        $this->assertNotNull($html);
        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringContainsString('Beta', $html);
    }
}
