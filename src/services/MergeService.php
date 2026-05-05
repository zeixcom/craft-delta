<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use craft\base\Component;
use craft\elements\Entry;

/**
 * Owns the write side of review mode: validates accepted atoms against a
 * fresh diff, copies field/Matrix values from source to a new draft of the
 * canonical entry, saves once.
 *
 * Pure write-side. Shares no mutable state with DiffService.
 */
class MergeService extends Component
{
    /**
     * Parse a stable atom key into a structured array.
     *
     * @return array{kind: string, ...}
     * @throws \InvalidArgumentException when the key is malformed
     */
    public static function parseAtomKey(string $key): array
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Empty atom key');
        }

        $parts = explode(':', $key);
        $kind = $parts[0];

        switch ($kind) {
            case 'field':
                if (count($parts) !== 2 || $parts[1] === '') {
                    throw new \InvalidArgumentException("Malformed field atom: $key");
                }
                return ['kind' => 'field', 'handle' => $parts[1]];

            case 'matrix-block':
                if (count($parts) !== 4) {
                    throw new \InvalidArgumentException("Malformed matrix-block atom: $key");
                }
                $changeType = $parts[3];
                if (!in_array($changeType, ['added', 'removed', 'modified'], true)) {
                    throw new \InvalidArgumentException("Unknown change type: $changeType");
                }
                return [
                    'kind' => 'matrix-block',
                    'fieldHandle' => $parts[1],
                    'blockUid' => $parts[2],
                    'changeType' => $changeType,
                ];

            case 'matrix-reorder':
                if (count($parts) !== 2 || $parts[1] === '') {
                    throw new \InvalidArgumentException("Malformed matrix-reorder atom: $key");
                }
                return ['kind' => 'matrix-reorder', 'fieldHandle' => $parts[1]];

            default:
                throw new \InvalidArgumentException("Unknown atom kind: $kind");
        }
    }

    /**
     * Apply the user's accepted atoms onto a new draft of the canonical entry.
     *
     * @param string[] $acceptedAtoms List of stable atom keys (see spec §5.1)
     * @return Entry The newly saved draft
     */
    public function merge(Entry $canonical, Entry $source, array $acceptedAtoms): Entry
    {
        // TODO: implemented across Tasks 3-9.
        throw new \LogicException('MergeService::merge not implemented yet.');
    }
}
