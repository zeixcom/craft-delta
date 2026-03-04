<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

/**
 * Simple before → after diff for scalar values.
 */
class ScalarDiffer implements DifferInterface
{
    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        $old = $this->normalize($oldValue);
        $new = $this->normalize($newValue);

        if ($old === $new) {
            return null;
        }

        $oldDisplay = $this->display($oldValue);
        $newDisplay = $this->display($newValue);

        return sprintf(
            '<span class="delta-del">%s</span> → <span class="delta-ins">%s</span>',
            htmlspecialchars($oldDisplay, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($newDisplay, ENT_QUOTES, 'UTF-8'),
        );
    }

    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        $old = $this->normalize($oldValue);
        $new = $this->normalize($newValue);

        if ($old === $new) {
            return ['additions' => 0, 'deletions' => 0];
        }

        return [
            'additions' => 1,
            'deletions' => 1,
        ];
    }

    /**
     * Normalize a value to a comparable string representation.
     */
    private function normalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \DateTime) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof \Money\Money) {
            return $value->getAmount() . ' ' . $value->getCurrency()->getCode();
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: get_class($value);
        }

        return (string)$value;
    }

    /**
     * Format a value for user-facing display in the diff output.
     */
    private function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '(empty)';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($value instanceof \DateTime) {
            return $value->format('M j, Y g:ia');
        }

        if ($value instanceof \Money\Money) {
            $amount = (int)$value->getAmount();
            $currency = $value->getCurrency()->getCode();
            return number_format($amount / 100, 2) . ' ' . $currency;
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: get_class($value);
        }

        return (string)$value;
    }
}
