<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use craft\base\Component;
use craft\elements\Entry;
use zeixcom\craftdelta\i18n\TranslationKeys;
use zeixcom\craftdelta\models\DiffResult;

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
     * @return array{kind: 'field', handle: string}|array{kind: 'matrix-block', fieldHandle: string, blockUid: string, changeType: string}|array{kind: 'matrix-reorder', fieldHandle: string}
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
     * Validate that every accepted atom corresponds to a real atom in the
     * fresh diff. Malformed atoms throw InvalidArgumentException; unknown
     * atoms throw StaleAtomException.
     *
     * @param string[] $availableAtoms All atom keys present in the fresh diff
     * @param string[] $acceptedAtoms  The user's accepted atoms
     * @throws \InvalidArgumentException
     * @throws StaleAtomException
     */
    public static function validateAtoms(array $availableAtoms, array $acceptedAtoms): void
    {
        $available = array_flip($availableAtoms);

        foreach ($acceptedAtoms as $atom) {
            self::parseAtomKey($atom); // throws on malformed

            if (!isset($available[$atom])) {
                throw new StaleAtomException("Atom '$atom' is not present in the fresh diff");
            }
        }
    }

    /**
     * Step A of the Matrix merge: build the surviving block set, before
     * ordering. Each block is an associative array; this method is content-
     * agnostic — it operates on UIDs.
     *
     * @param array<int, array<string, mixed>> $current        Current blocks
     * @param array<int, array<string, mixed>> $source         Source blocks
     * @param array<int, array{blockUid: string, changeType: string}> $atoms Accepted block atoms for this field
     * @return array<int, array<string, mixed>>                Surviving blocks (order not yet applied)
     */
    public static function buildMatrixBlockList(array $current, array $source, array $atoms): array
    {
        $sourceByUid = [];
        foreach ($source as $block) {
            $sourceByUid[$block['uid']] = $block;
        }

        $working = [];
        foreach ($current as $block) {
            $working[$block['uid']] = $block;
        }

        foreach ($atoms as $atom) {
            $uid = $atom['blockUid'];
            switch ($atom['changeType']) {
                case 'added':
                    if (isset($sourceByUid[$uid])) {
                        $working[$uid] = $sourceByUid[$uid];
                    }
                    break;
                case 'removed':
                    unset($working[$uid]);
                    break;
                case 'modified':
                    if (isset($sourceByUid[$uid])) {
                        $working[$uid] = $sourceByUid[$uid];
                    }
                    break;
            }
        }

        return array_values($working);
    }

    /**
     * Step B of the Matrix merge: order the surviving blocks per spec §6.2.
     *
     * @param array<int, array<string, mixed>> $survivors         Output from buildMatrixBlockList
     * @param array<int, array<string, mixed>> $current           Original current blocks (for spine)
     * @param array<int, array<string, mixed>> $source            Original source blocks (for spine)
     * @param bool $acceptedReorder                               Whether the matrix-reorder atom was accepted
     * @return array<int, array<string, mixed>>
     */
    public static function orderMatrixBlocks(array $survivors, array $current, array $source, bool $acceptedReorder): array
    {
        $survivorsByUid = [];
        foreach ($survivors as $block) {
            $survivorsByUid[$block['uid']] = $block;
        }

        if (!$acceptedReorder) {
            return self::orderByCurrentSpine($survivorsByUid, $current, $source);
        }

        // Reorder branch implemented in Task 7
        return self::orderBySourceSpine($survivorsByUid, $current, $source);
    }

    /**
     * @param array<string, array<string, mixed>> $survivorsByUid
     * @param array<int, array<string, mixed>> $current
     * @param array<int, array<string, mixed>> $source
     * @return array<int, array<string, mixed>>
     */
    private static function orderByCurrentSpine(array $survivorsByUid, array $current, array $source): array
    {
        $result = [];

        foreach ($current as $block) {
            $uid = $block['uid'];
            if (isset($survivorsByUid[$uid])) {
                $result[] = $survivorsByUid[$uid];
                unset($survivorsByUid[$uid]);
            }
        }

        foreach ($source as $block) {
            $uid = $block['uid'];
            if (isset($survivorsByUid[$uid])) {
                $result[] = $survivorsByUid[$uid];
                unset($survivorsByUid[$uid]);
            }
        }

        return $result;
    }

    /**
     * Order the surviving blocks under source's spine. Kept current-only blocks
     * are inserted immediately after the most-recent both-sides anchor in
     * current's order. Current-only blocks before any anchor go at the front.
     *
     * @param array<string, array<string, mixed>> $survivorsByUid
     * @param array<int, array<string, mixed>> $current
     * @param array<int, array<string, mixed>> $source
     * @return array<int, array<string, mixed>>
     */
    private static function orderBySourceSpine(array $survivorsByUid, array $current, array $source): array
    {
        // 1. Identify which surviving UIDs are in source (anchors + source-only adds).
        $sourceUids = [];
        foreach ($source as $block) {
            $sourceUids[$block['uid']] = true;
        }

        // 2. Walk current's order; for each current-only kept survivor, find its
        //    most-recent both-sides anchor (or null if none yet seen).
        $anchorByCurrentOnly = [];
        $currentOnlyOrder = [];
        $lastAnchor = null;

        foreach ($current as $block) {
            $uid = $block['uid'];
            $isSurvivor = isset($survivorsByUid[$uid]);
            $isInSource = isset($sourceUids[$uid]);

            if ($isSurvivor && $isInSource) {
                $lastAnchor = $uid;
            } elseif ($isSurvivor) { // surviving current-only block
                $anchorByCurrentOnly[$uid] = $lastAnchor;
                $currentOnlyOrder[] = $uid;
            }
        }

        // 3. Walk source's order, emitting source survivors. After each anchor,
        //    flush any current-only blocks that anchor to it.
        $result = [];

        // Flush current-only blocks with no anchor (lastAnchor was null) first.
        foreach ($currentOnlyOrder as $uid) {
            if ($anchorByCurrentOnly[$uid] === null) {
                $result[] = $survivorsByUid[$uid];
            }
        }

        foreach ($source as $block) {
            $uid = $block['uid'];
            if (!isset($survivorsByUid[$uid])) {
                continue; // source block didn't survive (e.g. a source-only block whose 'added' atom was rejected)
            }
            $result[] = $survivorsByUid[$uid];

            // Flush current-only blocks anchored to this UID, in their original current-order.
            foreach ($currentOnlyOrder as $coUid) {
                if ($anchorByCurrentOnly[$coUid] === $uid) {
                    $result[] = $survivorsByUid[$coUid];
                }
            }
        }

        return $result;
    }

    /**
     * Build the Craft 5 Matrix setFieldValue payload
     * (['entries' => [<key> => {…}], 'sortOrder' => […]]) from an ordered list
     * of surviving blocks.
     *
     * Existing draft clones are keyed `uid:<draftEntryUid>` so Craft patches
     * that exact entry; brand-new blocks (from accepted `added` atoms) use
     * new1/new2… so Craft assigns fresh element ids + canonical UIDs.
     *
     * IMPORTANT: Craft chooses UID-mode vs ID-mode by inspecting ONLY the FIRST
     * element of `entries`/`sortOrder` (Matrix::normalizeValue → array_key_first()
     * / reset()). If a brand-new block ("newN") is emitted first, detection falls
     * back to ID-mode, the "uid:" prefixes are never stripped, and every existing
     * block is silently DROPPED. So we insert existing ("uid:…") entries into the
     * map BEFORE the new ones — keeping the first key UID-shaped whenever any
     * existing block survives. Display order is unaffected: Craft reads it from
     * `sortOrder`, which we keep in the true ordered sequence.
     *
     * @param array<int, array{uid: string, draftEntryUid?: string, payload: array<string, mixed>}> $ordered
     * @param array<string, array{draftEntryUid: string}> $currentByCanonicalUid
     * @return array{entries: array<string, mixed>, sortOrder: array<int, string>}
     */
    public static function buildMatrixSetValue(array $ordered, array $currentByCanonicalUid): array
    {
        $plan = [];
        $sortOrder = [];
        $newCount = 0;

        foreach ($ordered as $block) {
            $existing = $currentByCanonicalUid[$block['uid']] ?? null;
            if ($existing !== null) {
                $key = 'uid:' . $existing['draftEntryUid'];
                $sortOrderToken = $existing['draftEntryUid'];
                $isExisting = true;
            } else {
                $newCount++;
                $key = 'new' . $newCount;
                $sortOrderToken = $key;
                $isExisting = false;
            }
            $plan[] = ['key' => $key, 'payload' => $block['payload'], 'isExisting' => $isExisting];
            $sortOrder[] = $sortOrderToken;
        }

        $entries = [];
        foreach ($plan as $p) {
            if ($p['isExisting']) {
                $entries[$p['key']] = $p['payload'];
            }
        }
        foreach ($plan as $p) {
            if (!$p['isExisting']) {
                $entries[$p['key']] = $p['payload'];
            }
        }

        return ['entries' => $entries, 'sortOrder' => $sortOrder];
    }

    /**
     * Apply field-kind atoms onto a draft by copying values from source.
     * Handles native attributes (title, slug) separately from field handles.
     *
     * @param string[] $fieldAtoms List of "field:<handle>" atom keys
     */
    private function applyFieldAtoms(Entry $draft, Entry $source, array $fieldAtoms): void
    {
        foreach ($fieldAtoms as $atom) {
            $parsed = self::parseAtomKey($atom);
            $handle = $parsed['handle'];

            // Native attributes (matches DiffService::compareAttributes)
            if ($handle === 'title') {
                $draft->title = $source->title;
                continue;
            }
            if ($handle === 'slug') {
                $draft->slug = $source->slug;
                continue;
            }

            // Custom field — let Craft handle serialization across all field types
            // (CKEditor, Asset, Money, etc. travel cleanly via getFieldValue/setFieldValue).
            $draft->setFieldValue($handle, $source->getFieldValue($handle));
        }
    }

    /**
     * Apply matrix-block and matrix-reorder atoms onto a draft for one Matrix field.
     *
     * @param array<int, array{blockUid: string, changeType: string}> $blockAtoms
     */
    private function applyMatrixAtoms(
        Entry $draft,
        Entry $source,
        string $fieldHandle,
        array $blockAtoms,
        bool $acceptedReorder,
    ): void {
        $current = $this->serializeMatrixBlocks($draft->getFieldValue($fieldHandle));
        $sourceBlocks = $this->serializeMatrixBlocks($source->getFieldValue($fieldHandle));

        $survivors = self::buildMatrixBlockList($current, $sourceBlocks, $blockAtoms);
        $ordered = self::orderMatrixBlocks($survivors, $current, $sourceBlocks, $acceptedReorder);

        // Build a lookup so we can map each surviving block back to whether it
        // already exists on $draft (a clone of canonical) or whether it's new
        // (came from source via an `added` atom).
        $currentByCanonicalUid = [];
        foreach ($current as $b) {
            $currentByCanonicalUid[$b['uid']] = $b;
        }

        $draft->setFieldValue($fieldHandle, self::buildMatrixSetValue($ordered, $currentByCanonicalUid));
    }

    /**
     * Serialize a Craft Matrix field value into [{uid, draftEntryUid, payload}, …]
     * form. `uid` is the canonicalUid (used for matching across canonical / draft
     * / revision via MatrixDiffer's canonical-ID convention). `draftEntryUid`
     * is THIS specific entry's own uid — needed when emitting back into
     * setFieldValue so Craft patches the right draft entry rather than creating
     * a duplicate.
     *
     * @param mixed $matrixValue The result of $entry->getFieldValue($handle) for a Matrix field
     * @return array<int, array{uid: string, draftEntryUid: string, payload: array<string, mixed>}>
     */
    private function serializeMatrixBlocks(mixed $matrixValue): array
    {
        $result = [];
        foreach ($matrixValue as $block) {
            // Mirror Craft's MatrixField::serializeValue() shape so block-level
            // attributes (title, slug, enabled, collapsed) round-trip on apply.
            $result[] = [
                'uid' => $block->canonicalUid,
                'draftEntryUid' => $block->uid,
                'payload' => [
                    'type' => $block->type->handle,
                    'title' => $block->title,
                    'slug' => $block->slug,
                    'enabled' => $block->enabled,
                    'collapsed' => $block->collapsed ?? false,
                    'fields' => $block->getSerializedFieldValues(),
                ],
            ];
        }
        return $result;
    }

    /**
     * Apply the user's accepted atoms onto a new draft of the canonical entry,
     * then publish that draft via Craft's standard lifecycle.
     *
     * @param string[] $acceptedAtoms     List of stable atom keys (see spec §5.1)
     * @param bool     $deleteSourceDraft If true and $source is a draft, delete
     *                                    it after the publish succeeds.
     * @return Entry The published canonical entry (with a fresh revision)
     */
    public function merge(Entry $canonical, Entry $source, array $acceptedAtoms, bool $deleteSourceDraft = false): Entry
    {
        // 1. Re-run a fresh diff and build the available-atoms set.
        $plugin = \zeixcom\craftdelta\Delta::getInstance();
        $freshDiff = $plugin->diff->compare($canonical, $source);
        $availableAtoms = self::collectAvailableAtoms($freshDiff);

        // 2. Validate every accepted atom is still present in the fresh diff.
        self::validateAtoms($availableAtoms, $acceptedAtoms);

        // 3. Group accepted atoms by kind / Matrix field.
        $fieldAtoms = [];
        $matrixBlockAtomsByHandle = [];
        $reorderAcceptedHandles = [];

        foreach ($acceptedAtoms as $atom) {
            $parsed = self::parseAtomKey($atom);
            switch ($parsed['kind']) {
                case 'field':
                    $fieldAtoms[] = $atom;
                    break;
                case 'matrix-block':
                    $h = $parsed['fieldHandle'];
                    $matrixBlockAtomsByHandle[$h] ??= [];
                    $matrixBlockAtomsByHandle[$h][] = [
                        'blockUid' => $parsed['blockUid'],
                        'changeType' => $parsed['changeType'],
                    ];
                    break;
                case 'matrix-reorder':
                    $reorderAcceptedHandles[$parsed['fieldHandle']] = true;
                    break;
            }
        }

        // 4. Create a draft of the canonical entry.
        $user = \Craft::$app->getUser()->getIdentity();
        $draft = \Craft::$app->getDrafts()->createDraft(
            $canonical,
            $user?->id ?? 0,
            \Craft::t('craft-delta', TranslationKeys::REVIEW_OF_REF, ['ref' => $this->humanRefForSource($source)]),
        );

        // 5. Apply field atoms.
        $this->applyFieldAtoms($draft, $source, $fieldAtoms);

        // 6. Apply Matrix atoms — one call per Matrix field.
        //    Include fields with reorder-only atoms (no block atoms).
        $matrixHandles = array_unique(array_merge(
            array_keys($matrixBlockAtomsByHandle),
            array_keys($reorderAcceptedHandles),
        ));
        foreach ($matrixHandles as $handle) {
            $blockAtoms = $matrixBlockAtomsByHandle[$handle] ?? [];
            $acceptedReorder = isset($reorderAcceptedHandles[$handle]);
            $this->applyMatrixAtoms($draft, $source, $handle, $blockAtoms, $acceptedReorder);
        }

        // 7. ONE save on the draft — never per field.
        if (!\Craft::$app->getElements()->saveElement($draft)) {
            $errors = $draft->getErrors();
            throw new \RuntimeException('Draft validation failed: ' . json_encode($errors));
        }

        // 8. Publish the draft to canonical via Craft's normal lifecycle. The
        //    transient draft is discarded; canonical receives a new revision.
        $published = \Craft::$app->getDrafts()->applyDraft($draft);
        if (!$published instanceof Entry) {
            $errors = $draft->getErrors();
            throw new \RuntimeException('Failed to publish: ' . json_encode($errors));
        }

        // 9. Optional: delete the source draft if the caller asked for it. Only
        //    applies when source IS a draft (not canonical, not a revision).
        if ($deleteSourceDraft && $source->getBehavior('draft') !== null) {
            \Craft::$app->getElements()->deleteElement($source, true);
        }

        return $published;
    }

    /**
     * Walk the fresh DiffResult and collect the full set of atom keys it offers.
     * Mirrors the keys the client emits in data-atom-id. Static + pure so the
     * stale-atom contract can be unit-tested without a Craft kernel.
     *
     * @return string[]
     */
    public static function collectAvailableAtoms(DiffResult $diff): array
    {
        $atoms = [];

        foreach ($diff->fieldDiffs as $fd) {
            if (!$fd->hasChanges) {
                continue;
            }

            // Must match the templates' Matrix test (_field-diff.twig and
            // _diff-content.twig both key on the class ending in "\Matrix"). A
            // broader str_contains() here would classify a field as Matrix that
            // the templates render as a plain field, so the client offers a
            // `field:` atom this set lacks and the whole apply fails as stale.
            $isMatrix = str_ends_with($fd->fieldType, '\\Matrix');
            if (!$isMatrix) {
                $atoms[] = 'field:' . $fd->fieldHandle;
                continue;
            }

            // Matrix field — diffHtml is JSON describing block changes.
            $changes = json_decode($fd->diffHtml, true);
            if (!is_array($changes)) {
                continue;
            }

            $hasReorder = false;
            foreach ($changes as $change) {
                $type = $change['type'] ?? null;
                if ($type === 'reordered') {
                    $hasReorder = true;
                    continue;
                }
                if (in_array($type, ['added', 'removed', 'modified'], true)
                    && !empty($change['blockUid'])
                ) {
                    $atoms[] = 'matrix-block:' . $fd->fieldHandle . ':' . $change['blockUid'] . ':' . $type;
                }
            }

            if ($hasReorder) {
                $atoms[] = 'matrix-reorder:' . $fd->fieldHandle;
            }
        }

        return $atoms;
    }

    private function humanRefForSource(Entry $source): string
    {
        // revisionNum lives on RevisionBehavior; access via getBehavior to avoid
        // "Unknown property" when source is canonical or a draft.
        /** @var \craft\behaviors\RevisionBehavior|null $revisionBehavior */
        $revisionBehavior = $source->getBehavior('revision');
        if ($revisionBehavior !== null && $revisionBehavior->revisionNum !== null) {
            return \Craft::t('craft-delta', TranslationKeys::REV_NUM, ['num' => $revisionBehavior->revisionNum]);
        }
        /** @var \craft\behaviors\DraftBehavior|null $draftBehavior */
        $draftBehavior = $source->getBehavior('draft');
        if ($draftBehavior !== null) {
            return $draftBehavior->draftName ?? \Craft::t('craft-delta', TranslationKeys::DRAFT);
        }
        return \Craft::t('craft-delta', TranslationKeys::SOURCE);
    }
}
