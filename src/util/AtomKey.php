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
        $kind = $parts[0];

        switch ($kind) {
            case AtomKind::Field->value:
                if (count($parts) !== 2 || $parts[1] === '') {
                    throw new \InvalidArgumentException("Malformed field atom: $key");
                }
                return ['kind' => AtomKind::Field->value, 'handle' => $parts[1]];

            case AtomKind::MatrixBlock->value:
                if (count($parts) !== 4) {
                    throw new \InvalidArgumentException("Malformed matrix-block atom: $key");
                }
                $changeType = $parts[3];
                if (DiffChangeType::tryFrom($changeType) === null) {
                    throw new \InvalidArgumentException("Unknown change type: $changeType");
                }
                return [
                    'kind' => AtomKind::MatrixBlock->value,
                    'fieldHandle' => $parts[1],
                    'blockUid' => $parts[2],
                    'changeType' => $changeType,
                ];

            case AtomKind::MatrixReorder->value:
                if (count($parts) !== 2 || $parts[1] === '') {
                    throw new \InvalidArgumentException("Malformed matrix-reorder atom: $key");
                }
                return ['kind' => AtomKind::MatrixReorder->value, 'fieldHandle' => $parts[1]];

            default:
                throw new \InvalidArgumentException("Unknown atom kind: $kind");
        }
    }
}
