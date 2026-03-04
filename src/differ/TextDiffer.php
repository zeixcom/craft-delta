<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use Jfcherng\Diff\Differ;
use Jfcherng\Diff\Factory\RendererFactory;

/**
 * Word-level diff for plain text fields.
 */
class TextDiffer implements DifferInterface
{
    public function __construct(
        private int $context = 3,
    ) {
    }

    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        $old = (string)($oldValue ?? '');
        $new = (string)($newValue ?? '');

        if ($old === $new) {
            return null;
        }

        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);

        $differ = new Differ($oldLines, $newLines, [
            'context' => $this->context,
            'ignoreWhitespace' => false,
            'ignoreCase' => false,
        ]);

        $renderer = RendererFactory::make('SideBySide', [
            'detailLevel' => 'word',
            'showHeader' => false,
            'spacesToNbsp' => false,
        ]);

        return $renderer->render($differ);
    }

    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        $old = str_word_count((string)($oldValue ?? ''));
        $new = str_word_count((string)($newValue ?? ''));

        return [
            'additions' => max(0, $new - $old),
            'deletions' => max(0, $old - $new),
        ];
    }

}
