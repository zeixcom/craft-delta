<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use craft\base\Component;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;

class RevisionService extends Component
{
    /** @return list<Entry> */
    public function getRevisions(int $canonicalId, int $limit = 20, ?int $siteId = null): array
    {
        return $this->withSiteId(
            Entry::find()->revisionOf($canonicalId)->status(null)->orderBy(['dateCreated' => SORT_DESC])->limit($limit),
            $siteId,
        )->all();
    }

    /** @return list<Entry> */
    public function getDrafts(int $canonicalId, ?int $siteId = null, int $limit = 50): array
    {
        return $this->withSiteId(
            Entry::find()->draftOf($canonicalId)->status(null)->orderBy(['dateUpdated' => SORT_DESC])->limit($limit),
            $siteId,
        )->all();
    }

    public function getRevision(int $revisionId, ?int $siteId = null): ?Entry
    {
        return $this->withSiteId(
            Entry::find()->id($revisionId)->revisions(true)->status(null),
            $siteId,
        )->one();
    }

    /** Load a draft by its drafts-table id (the value on $entry->draftId). */
    public function getDraftByDraftId(int $draftId, ?int $draftOf = null, ?int $siteId = null): ?Entry
    {
        $query = Entry::find()->draftId($draftId)->status(null);
        if ($draftOf !== null) {
            $query->draftOf($draftOf);
        }
        return $this->withSiteId($query, $siteId)->one();
    }

    public function getCanonical(int $entryId, ?int $siteId = null): ?Entry
    {
        return $this->withSiteId(Entry::find()->id($entryId)->status(null), $siteId)->one();
    }

    private function withSiteId(EntryQuery $query, ?int $siteId): EntryQuery
    {
        return $siteId !== null ? $query->siteId($siteId) : $query;
    }
}
