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
