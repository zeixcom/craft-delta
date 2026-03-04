<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use Jfcherng\Diff\Differ;
use Jfcherng\Diff\Factory\RendererFactory;

/**
 * Diff for CKEditor / rich text fields.
 */
class HtmlDiffer implements DifferInterface
{
    public function __construct(
        private int $context = Differ::CONTEXT_ALL,
    ) {
    }

    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        $oldHtml = (string)($oldValue ?? '');
        $newHtml = (string)($newValue ?? '');

        if ($oldHtml === $newHtml) {
            return null;
        }

        $oldText = $this->htmlToText($oldHtml);
        $newText = $this->htmlToText($newHtml);

        if ($oldText === $newText) {
            return null;
        }

        $oldLines = explode("\n", $oldText);
        $newLines = explode("\n", $newText);

        $differ = new Differ($oldLines, $newLines, [
            'context' => $this->context,
        ]);

        $renderer = RendererFactory::make('SideBySide', [
            'detailLevel' => 'word',
            'showHeader' => false,
        ]);

        return $renderer->render($differ);
    }

    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        $oldText = $this->htmlToText((string)($oldValue ?? ''));
        $newText = $this->htmlToText((string)($newValue ?? ''));

        $old = str_word_count($oldText);
        $new = str_word_count($newText);

        return [
            'additions' => max(0, $new - $old),
            'deletions' => max(0, $old - $new),
        ];
    }

    /**
     * Convert HTML to plain text, preserving paragraph breaks.
     */
    private function htmlToText(string $html): string
    {
        $html = (string)preg_replace('/<\/(p|div|h[1-6]|li|tr|blockquote)>/i', "\n", $html);
        $html = (string)preg_replace('/<br\s*\/?>/i', "\n", $html);

        $text = strip_tags($html);

        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        $text = (string)preg_replace('/[ \t]+/', ' ', $text);
        $text = (string)preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
