<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use DateTime;
use Money\Money;
use zeixcom\craftdelta\helpers\DiffHtml;

/**
 * @phpstan-import-type DiffStats from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type ScalarFieldValue from \zeixcom\craftdelta\types\ArrayTypes
 */
class ScalarDiffer implements DifferInterface
{
    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        if ($this->normalize($oldValue) === $this->normalize($newValue)) {
            return null;
        }

        return DiffHtml::scalarChange($this->display($oldValue), $this->display($newValue));
    }

    /** @return DiffStats */
    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        return $this->normalize($oldValue) === $this->normalize($newValue)
            ? ['additions' => 0, 'deletions' => 0]
            : ['additions' => 1, 'deletions' => 1];
    }

    /** @param ScalarFieldValue $value */
    private function normalize(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            // treat "off" (false) and "absent" (null) as the same state
            is_bool($value) => $value ? '1' : '',
            $value instanceof DateTime => $value->format('Y-m-d H:i:s'),
            $value instanceof Money => $value->getAmount() . ' ' . $value->getCurrency()->getCode(),
            is_object($value) => $this->stringifyObject($value, false),
            default => (string)$value,
        };
    }

    /** @param ScalarFieldValue $value */
    private function display(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '(empty)',
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof DateTime => $value->format('M j, Y g:ia'),
            $value instanceof Money => number_format((int)$value->getAmount() / 100, 2) . ' ' . $value->getCurrency()->getCode(),
            is_object($value) => $this->stringifyObject($value, true),
            default => (string)$value,
        };
    }

    private function stringifyObject(object $value, bool $pretty): string
    {
        if (method_exists($value, '__toString')) {
            return (string)$value;
        }
        $flags = JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0);
        return json_encode($value, $flags) ?: $value::class;
    }
}
