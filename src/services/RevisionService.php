<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use craft\base\Component;
use craft\elements\Entry;

/**
 * Handles loading revisions and canonical entries for comparison.
 */
class RevisionService extends Component
{
    /**
     * Get all revisions for a canonical entry, ordered newest first.
     */
    public function getRevisions(int $canonicalId, int $limit = 20, ?int $siteId = null): array
    {
        $query = Entry::find()
            ->revisionOf($canonicalId)
            ->status(null)
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit);

        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        return $query->all();
    }

    /**
     * Get all saved drafts for a canonical entry, ordered newest first.
     *
     * @return Entry[]
     */
    public function getDrafts(int $canonicalId, ?int $siteId = null, int $limit = 50): array
    {
        $query = Entry::find()
            ->draftOf($canonicalId)
            ->status(null)
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($limit);

        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        return $query->all();
    }

    /**
     * Load a specific revision by its element ID.
     */
    public function getRevision(int $revisionId, ?int $siteId = null): ?Entry
    {
        $query = Entry::find()
            ->id($revisionId)
            ->revisions(true)
            ->status(null);

        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        return $query->one();
    }

    /**
     * Load the canonical (current live) entry.
     */
    public function getCanonical(int $entryId, ?int $siteId = null): ?Entry
    {
        $query = Entry::find()
            ->id($entryId)
            ->status(null);

        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        return $query->one();
    }
}
