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
            } elseif ($isSurvivor && !$isInSource) {
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

        // Convert back into Craft's Matrix value format. Each block is keyed by
        // its UID with the block's payload (type, fields). We rebuild the field's
        // serialized value and let setFieldValue do the rest.
        $serialized = [];
        foreach ($ordered as $block) {
            $serialized[$block['uid']] = $block['payload'];
        }

        $draft->setFieldValue($fieldHandle, $serialized);
    }

    /**
     * Serialize a Craft Matrix field value into [{uid, payload}, ...] form
     * keyed by current order. The payload is whatever Craft expects on
     * setFieldValue — for v1 that's the array shape from getSerializedFieldValues.
     *
     * Uses canonicalUid so blocks line up across canonical/draft/revision sides
     * (mirrors MatrixDiffer's canonical-ID matching).
     *
     * @param mixed $matrixValue The result of $entry->getFieldValue($handle) for a Matrix field
     * @return array<int, array{uid: string, payload: array<string, mixed>}>
     */
    private function serializeMatrixBlocks(mixed $matrixValue): array
    {
        $result = [];
        // $matrixValue is typically a Craft\elements\db\EntryQuery (Matrix blocks
        // are entries in Craft 5). Iterating gives Block entries; each has a
        // canonicalUid and a serializeFieldValues method.
        foreach ($matrixValue as $block) {
            $result[] = [
                'uid' => $block->canonicalUid,
                'payload' => [
                    'type' => $block->type->handle,
                    'fields' => $block->getSerializedFieldValues(),
                ],
            ];
        }
        return $result;
    }

    /**
     * Apply the user's accepted atoms onto a new draft of the canonical entry.
     *
     * @param string[] $acceptedAtoms List of stable atom keys (see spec §5.1)
     * @return Entry The newly saved draft
     */
    public function merge(Entry $canonical, Entry $source, array $acceptedAtoms): Entry
    {
        // 1. Re-run a fresh diff and build the available-atoms set.
        $plugin = \zeixcom\craftdelta\Delta::getInstance();
        $freshDiff = $plugin->diff->compare($canonical, $source);
        $availableAtoms = $this->collectAvailableAtoms($freshDiff);

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
            \Craft::t('craft-delta', 'Review of {ref}', ['ref' => $this->humanRefForSource($source)]),
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

        return $published;
    }

    /**
     * Walk the fresh DiffResult and collect the full set of atom keys it offers.
     * Mirrors the keys the client emits in data-atom-id.
     *
     * @return string[]
     */
    private function collectAvailableAtoms(\zeixcom\craftdelta\models\DiffResult $diff): array
    {
        $atoms = [];

        foreach ($diff->fieldDiffs as $fd) {
            if (!$fd->hasChanges) {
                continue;
            }

            $isMatrix = str_contains($fd->fieldType, '\\Matrix');
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
            return 'Rev ' . $revisionBehavior->revisionNum;
        }
        /** @var \craft\behaviors\DraftBehavior|null $draftBehavior */
        $draftBehavior = $source->getBehavior('draft');
        if ($draftBehavior !== null) {
            return $draftBehavior->draftName ?? 'Draft';
        }
        return 'Source';
    }
}
