<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\util;

use zeixcom\craftdelta\enums\AtomKind;
use zeixcom\craftdelta\enums\DiffChangeType;

/**
 * Parse and validate stable atom keys shared by merge, review comments, and the
 * client UI. Pure static helpers — no service dependencies.
 *
 * @phpstan-import-type ParsedAtomKey from \zeixcom\craftdelta\types\ArrayTypes
 */
final class AtomKey
{
    /**
     * @return ParsedAtomKey
     * @throws \InvalidArgumentException when the key is malformed
     */
    public static function parse(string $key): array
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Empty atom key');
        }

        $parts = explode(':', $key);
        return match ($parts[0]) {
            AtomKind::Field->value => count($parts) !== 2 || $parts[1] === ''
                ? throw new \InvalidArgumentException("Malformed field atom: $key")
                : ['kind' => AtomKind::Field->value, 'handle' => $parts[1]],
            AtomKind::MatrixBlock->value => count($parts) !== 4
                ? throw new \InvalidArgumentException("Malformed matrix-block atom: $key")
                : (DiffChangeType::tryFrom($parts[3]) === null
                    ? throw new \InvalidArgumentException("Unknown change type: {$parts[3]}")
                    : [
                        'kind' => AtomKind::MatrixBlock->value,
                        'fieldHandle' => $parts[1],
                        'blockUid' => $parts[2],
                        'changeType' => $parts[3],
                    ]),
            AtomKind::MatrixField->value => count($parts) !== 4 || $parts[1] === '' || $parts[2] === '' || $parts[3] === ''
                ? throw new \InvalidArgumentException("Malformed matrix-field atom: $key")
                : [
                    'kind' => AtomKind::MatrixField->value,
                    'fieldHandle' => $parts[1],
                    'blockUid' => $parts[2],
                    'subFieldHandle' => $parts[3],
                ],
            AtomKind::MatrixReorder->value => count($parts) !== 2 || $parts[1] === ''
                ? throw new \InvalidArgumentException("Malformed matrix-reorder atom: $key")
                : ['kind' => AtomKind::MatrixReorder->value, 'fieldHandle' => $parts[1]],
            default => throw new \InvalidArgumentException("Unknown atom kind: {$parts[0]}"),
        };
    }
}
