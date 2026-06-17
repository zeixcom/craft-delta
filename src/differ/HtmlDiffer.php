<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use Jfcherng\Diff\Differ;
use Jfcherng\Diff\Factory\RendererFactory;

/**
 * @phpstan-import-type DiffStats from \zeixcom\craftdelta\types\ArrayTypes
 */
class HtmlDiffer implements DifferInterface
{
    use WordCountStats;

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

        $differ = new Differ(explode("\n", $oldText), explode("\n", $newText), ['context' => $this->context]);

        return RendererFactory::make('SideBySide', [
            'detailLevel' => 'word',
            'showHeader' => false,
        ])->render($differ);
    }

    /** @return DiffStats */
    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        return $this->wordCountStats(
            $this->htmlToText((string)($oldValue ?? '')),
            $this->htmlToText((string)($newValue ?? '')),
        );
    }

    private function htmlToText(string $html): string
    {
        $html = (string)preg_replace('/<\/(p|div|h[1-6]|li|tr|blockquote)>/i', "\n", $html);
        $html = (string)preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = (string)preg_replace('/[ \t]+/', ' ', $text);
        return trim((string)preg_replace('/\n{3,}/', "\n\n", $text));
    }
}
