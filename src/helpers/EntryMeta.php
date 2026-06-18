<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\helpers;

use craft\behaviors\DraftBehavior;
use craft\behaviors\RevisionBehavior;
use craft\elements\Entry;

/** Typed accessors for Craft's draft/revision behaviors, which getBehavior() returns untyped. */
final class EntryMeta
{
    public static function draft(Entry $entry): ?DraftBehavior
    {
        $behavior = $entry->getBehavior('draft');
        return $behavior instanceof DraftBehavior ? $behavior : null;
    }

    public static function revision(Entry $entry): ?RevisionBehavior
    {
        $behavior = $entry->getBehavior('revision');
        return $behavior instanceof RevisionBehavior ? $behavior : null;
    }
}
