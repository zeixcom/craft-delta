<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

/**
 * Diffs a link-field value (Hyper's LinkCollection, and other link lists) with
 * friendly per-link labels — `type` / `value` / `text` / `new window` / `aria`
 * — rather than the raw serialization keys a generic flatten would surface.
 *
 * Reads each link's public attributes (`linkValue`, `linkText`, `newWindow`,
 * `ariaLabel`) and a duck-typed `getType()`, so it names no verbb\hyper\* class
 * and stays an optional integration. Output is canonical text fed to TextDiffer,
 * so a changed link reads as `text: <old> -> <new>` with no template support.
 *
 * @phpstan-import-type DiffStats from \zeixcom\craftdelta\types\ArrayTypes
 */
class LinkDiffer implements DifferInterface
{
    /** Public link attributes worth showing, in display order, with friendly labels. */
    private const ATTRIBUTES = [
        'linkValue' => 'value',
        'linkText' => 'text',
        'newWindow' => 'new window',
        'ariaLabel' => 'aria',
        'urlSuffix' => 'url suffix',
    ];

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

    private function canonicalText(mixed $value): string
    {
        $blocks = [];
        foreach ($this->toLinks($value) as $i => $link) {
            $map = $this->linkToMap($link);
            if ($map === []) {
                continue;
            }
            $lines = ['Link ' . ($i + 1)];
            foreach ($map as $label => $val) {
                $lines[] = "  $label: $val";
            }
            $blocks[] = implode("\n", $lines);
        }
        return implode("\n\n", $blocks);
    }

    /** @return list<mixed> */
    private function toLinks(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (is_object($value) && method_exists($value, 'getLinks')) {
            $links = $value->getLinks();
            return is_array($links) ? array_values($links) : [];
        }
        if ($value instanceof \Traversable) {
            return array_values(iterator_to_array($value, false));
        }
        return [];
    }

    /** @return array<string, string> */
    private function linkToMap(mixed $link): array
    {
        if (!is_object($link)) {
            return [];
        }
        $vars = get_object_vars($link);
        $map = [];

        if (method_exists($link, 'getType')) {
            $type = $link->getType();
            if (is_string($type) && $type !== '') {
                $map['type'] = $this->shortType($type);
            }
        }

        foreach (self::ATTRIBUTES as $prop => $label) {
            $val = $this->stringify($vars[$prop] ?? null);
            if ($val !== null) {
                $map[$label] = $val;
            }
        }
        return $map;
    }

    /** `verbb\hyper\links\Url` -> `Url` (the class basename reads cleaner than the FQN). */
    private function shortType(string $type): string
    {
        $pos = strrpos($type, '\\');
        return $pos === false ? $type : substr($type, $pos + 1);
    }

    private function stringify(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 'yes' : null;
        }
        if (is_scalar($v) || $v instanceof \Stringable) {
            return (string)$v;
        }
        // element objects, arrays, etc. — skip rather than dump internals
        return null;
    }
}
