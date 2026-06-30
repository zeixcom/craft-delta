<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

/**
 * Diffs a composite/structured field value (an SEO meta model, a link list, any
 * model or nested array) by flattening it to a stable, sorted `key: value` text
 * block and diffing that with TextDiffer. Reuses TextDiffer's word/line-level
 * rendering, so it needs no template support, and a changed attribute shows up
 * as its own changed line (e.g. `title: <old> -> <new>`).
 *
 * Generic on purpose: serves Beacon's SeoMeta, SEOmatic's SeoSettings, and (once
 * installed) Hyper/Linkit link fields. A field-type-specific differ is the
 * upgrade path only if a particular field needs smarter labelling or filtering.
 *
 * @phpstan-import-type DiffStats from \zeixcom\craftdelta\types\ArrayTypes
 */
class StructDiffer implements DifferInterface
{
    private const MAX_DEPTH = 5;

    public function __construct(
        private readonly TextDiffer $textDiffer,
    ) {
    }

    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        return $this->textDiffer->diff($this->canonicalText($oldValue), $this->canonicalText($newValue));
    }

    /** @return DiffStats */
    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        return $this->textDiffer->getStats($this->canonicalText($oldValue), $this->canonicalText($newValue));
    }

    /** Flatten to sorted "dotted.key: value" lines; empties dropped so they don't read as changes. */
    private function canonicalText(mixed $value): string
    {
        $flat = [];
        $this->flatten('', $this->toArray($value), $flat, 0);
        ksort($flat);
        $lines = [];
        foreach ($flat as $key => $val) {
            $lines[] = "$key: $val";
        }
        return implode("\n", $lines);
    }

    /** @return array<array-key, mixed> */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        // Collections (Hyper's LinkCollection, Craft's ElementCollection, …) hold
        // their items behind an iterator, not public props — iterate to reach them.
        if ($value instanceof \Traversable) {
            return iterator_to_array($value, false);
        }
        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                try {
                    /** @var array<array-key, mixed> $arr */
                    $arr = (array)$value->toArray();
                    return $arr;
                } catch (\Throwable) {
                    // fall through to public properties
                }
            }
            return get_object_vars($value);
        }
        return $value === null ? [] : ['value' => $value];
    }

    /**
     * @param array<array-key, mixed> $arr
     * @param array<string, string> $out
     */
    private function flatten(string $prefix, array $arr, array &$out, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            $out[$prefix] = '…';
            return;
        }
        foreach ($arr as $k => $v) {
            $key = $prefix === '' ? (string)$k : "$prefix.$k";
            if (is_array($v)) {
                if ($v !== []) {
                    $this->flatten($key, $v, $out, $depth + 1);
                }
            } elseif (is_object($v)) {
                $this->flatten($key, $this->toArray($v), $out, $depth + 1);
            } elseif ($v !== null && $v !== '') {
                $out[$key] = $this->scalarString($v);
            }
        }
    }

    private function scalarString(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v instanceof \BackedEnum) {
            return (string)$v->value;
        }
        if (is_scalar($v) || $v instanceof \Stringable) {
            return (string)$v;
        }
        return get_debug_type($v);
    }
}
