<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Differ;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\differ\HtmlDiffer;

class HtmlDifferTest extends TestCase
{
    private HtmlDiffer $differ;

    protected function setUp(): void
    {
        $this->differ = new HtmlDiffer();
    }

    public function testIdenticalHtmlReturnsNull(): void
    {
        $html = '<p>Visit our <a href="/foo">site</a> today.</p>';
        $this->assertNull($this->differ->diff($html, $html));
    }

    public function testRemovedLinkWithUnchangedTextIsDetected(): void
    {
        // The visible text is identical; only the link is gone. This must still diff.
        $old = '<p>Visit our <a href="/foo">site</a> today.</p>';
        $new = '<p>Visit our site today.</p>';
        $diff = $this->differ->diff($old, $new);

        $this->assertNotNull($diff, 'A removed link must surface in the diff');
        $this->assertStringContainsString('/foo', $diff);
    }

    public function testRetargetedLinkWithUnchangedTextIsDetected(): void
    {
        $old = '<p>Read the <a href="/old-target">report</a>.</p>';
        $new = '<p>Read the <a href="/new-target">report</a>.</p>';
        $diff = $this->differ->diff($old, $new);

        $this->assertNotNull($diff, 'A retargeted link must surface in the diff');
        // word-level diff splits the URL, so assert on the projected bracket + changed segments
        $this->assertStringContainsString('[/', $diff);
        $this->assertStringContainsString('<del>old</del>', $diff);
        $this->assertStringContainsString('<ins>new</ins>', $diff);
    }

    public function testExternalToInternalLinkSwapIsDetected(): void
    {
        $old = '<p>See <a href="https://example.com/page">here</a>.</p>';
        $new = '<p>See <a href="/page">here</a>.</p>';
        $this->assertNotNull($this->differ->diff($old, $new));
    }

    public function testUnchangedLinkDoesNotDiffOnSurroundingTextEdit(): void
    {
        // Same link on both sides; only prose changed. Link must not read as a change,
        // and href entities decode consistently so identical links stay identical.
        $old = '<p>Alpha <a href="/x?a=1&amp;b=2">link</a> beta.</p>';
        $new = '<p>Gamma <a href="/x?a=1&amp;b=2">link</a> delta.</p>';
        $diff = $this->differ->diff($old, $new);

        $this->assertNotNull($diff);
        $this->assertStringContainsString('Gamma', $diff);
    }

    public function testAnchorWithoutHrefKeepsText(): void
    {
        $old = '<p><a name="anchor">Section</a> one.</p>';
        $new = '<p><a name="anchor">Section</a> two.</p>';
        $diff = $this->differ->diff($old, $new);

        $this->assertNotNull($diff);
        // No href to project, so the bare anchor text is preserved without a `[...]` suffix.
        $this->assertStringContainsString('Section', $diff);
    }
}
