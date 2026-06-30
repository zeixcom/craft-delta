<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Differ;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\differ\LinkDiffer;
use zeixcom\craftdelta\differ\TextDiffer;

class LinkDifferTest extends TestCase
{
    private LinkDiffer $differ;

    protected function setUp(): void
    {
        $this->differ = new LinkDiffer(new TextDiffer(3));
    }

    /** A LinkCollection-like value: an object exposing getLinks(): list of link models. */
    private function collection(object ...$links): object
    {
        return new class($links) {
            /** @param list<object> $links */
            public function __construct(private array $links)
            {
            }

            /** @return list<object> */
            public function getLinks(): array
            {
                return $this->links;
            }
        };
    }

    /** A Hyper-Link-like model: public attributes + a getType() method. */
    private function link(string $value, ?string $text, bool $newWindow = false): object
    {
        return new class($value, $text, $newWindow) {
            public mixed $linkValue;
            public ?string $linkText;
            public ?bool $newWindow;
            public ?string $ariaLabel = null;
            public ?string $urlSuffix = null;

            public function __construct(mixed $value, ?string $text, bool $newWindow)
            {
                $this->linkValue = $value;
                $this->linkText = $text;
                $this->newWindow = $newWindow;
            }

            public function getType(): string
            {
                return 'verbb\\hyper\\links\\Url';
            }
        };
    }

    public function testIdenticalLinksReturnNull(): void
    {
        $a = $this->collection($this->link('https://a.test', 'A'));
        $b = $this->collection($this->link('https://a.test', 'A'));
        $this->assertNull($this->differ->diff($a, $b));
    }

    public function testChangedLinkUsesFriendlyLabels(): void
    {
        $old = $this->collection($this->link('https://example.com', 'Example Site'));
        $new = $this->collection($this->link('https://changed.org', 'Changed Link'));
        $html = $this->differ->diff($old, $new);

        $this->assertNotNull($html);
        // friendly labels, not raw serialization keys
        $this->assertStringContainsString('value', $html);
        $this->assertStringContainsString('text', $html);
        $this->assertStringContainsString('Url', $html);          // short type, not the FQN
        $this->assertStringNotContainsString('linkValue', $html); // the raw prop name must not leak
        // the actual change
        $this->assertStringContainsString('Example Site', $html);
        $this->assertStringContainsString('Changed Link', $html);
    }

    public function testAddedLinkIsDetected(): void
    {
        $old = $this->collection($this->link('https://a.test', 'A'));
        $new = $this->collection($this->link('https://a.test', 'A'), $this->link('https://b.test', 'B'));
        $html = $this->differ->diff($old, $new);

        $this->assertNotNull($html);
        $this->assertStringContainsString('Link 2', $html);
        $this->assertStringContainsString('B', $html);
    }
}
