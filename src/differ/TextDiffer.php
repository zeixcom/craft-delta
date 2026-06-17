<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use Jfcherng\Diff\Differ;
use Jfcherng\Diff\Factory\RendererFactory;

/**
 * @phpstan-import-type DiffStats from \zeixcom\craftdelta\types\ArrayTypes
 */
class TextDiffer implements DifferInterface
{
    use WordCountStats;

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

        $differ = new Differ(explode("\n", $old), explode("\n", $new), [
            'context' => $this->context,
            'ignoreWhitespace' => false,
            'ignoreCase' => false,
        ]);

        return RendererFactory::make('SideBySide', [
            'detailLevel' => 'word',
            'showHeader' => false,
            'spacesToNbsp' => false,
        ])->render($differ);
    }

    /** @return DiffStats */
    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        return $this->wordCountStats((string)($oldValue ?? ''), (string)($newValue ?? ''));
    }
}
