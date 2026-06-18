<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use craft\base\Component;
use craft\elements\db\EntryQuery;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\enums\AtomKind;
use zeixcom\craftdelta\enums\DiffChangeType;
use zeixcom\craftdelta\helpers\EntryMeta;
use zeixcom\craftdelta\helpers\Limits;
use zeixcom\craftdelta\helpers\MatrixValue;
use zeixcom\craftdelta\i18n\TranslationKeys;
use zeixcom\craftdelta\models\DiffResult;
use zeixcom\craftdelta\util\AtomKey;

/**
 * Owns the write side of review mode: validates accepted atoms against a
 * fresh diff, copies field/Matrix values from source to a new draft of the
 * canonical entry, saves once.
 *
 * @phpstan-import-type MatrixBlockAtom from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type MatrixCanonicalDraftMap from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type MatrixSetValue from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type OrderedMatrixBlock from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type SerializedMatrixBlock from \zeixcom\craftdelta\types\ArrayTypes
 */
class MergeService extends Component
{
    public ?DiffService $diffService = null;

    /**
     * @param string[] $availableAtoms All atom keys present in the fresh diff
     * @param string[] $acceptedAtoms  The user's accepted atoms
     * @throws \InvalidArgumentException
     * @throws StaleAtomException
     */
    public static function validateAtoms(array $availableAtoms, array $acceptedAtoms): void
    {
        if (count($acceptedAtoms) > Limits::ACCEPTED_ATOMS_MAX) {
            throw new \InvalidArgumentException('Too many accepted atoms.');
        }

        $available = array_flip($availableAtoms);
        foreach ($acceptedAtoms as $atom) {
            AtomKey::parse($atom);
            if (!isset($available[$atom])) {
                throw new StaleAtomException("Atom '$atom' is not present in the fresh diff");
            }
        }
    }

    /** @param list<SerializedMatrixBlock> $current @param list<SerializedMatrixBlock> $source @param array<int, MatrixBlockAtom> $atoms @return list<SerializedMatrixBlock> */
    public static function buildMatrixBlockList(array $current, array $source, array $atoms): array
    {
        $sourceByUid = array_column($source, null, 'uid');
        $working = array_column($current, null, 'uid');

        foreach ($atoms as $atom) {
            $uid = $atom['blockUid'];
            switch ($atom['changeType']) {
                case DiffChangeType::Added->value:
                case DiffChangeType::Modified->value:
                    if (isset($sourceByUid[$uid])) {
                        $working[$uid] = $sourceByUid[$uid];
                    }
                    break;
                case DiffChangeType::Removed->value:
                    unset($working[$uid]);
                    break;
            }
        }

        return array_values($working);
    }

    /** @param list<SerializedMatrixBlock> $survivors @param list<SerializedMatrixBlock> $current @param list<SerializedMatrixBlock> $source @return list<SerializedMatrixBlock> */
    public static function orderMatrixBlocks(array $survivors, array $current, array $source, bool $acceptedReorder): array
    {
        $survivorsByUid = array_column($survivors, null, 'uid');
        return $acceptedReorder
            ? self::orderBySourceSpine($survivorsByUid, $current, $source)
            : self::orderByCurrentSpine($survivorsByUid, $current, $source);
    }

    /** @param array<string, SerializedMatrixBlock> $survivorsByUid @param list<SerializedMatrixBlock> $current @param list<SerializedMatrixBlock> $source @return list<SerializedMatrixBlock> */
    private static function orderByCurrentSpine(array $survivorsByUid, array $current, array $source): array
    {
        $result = [];
        foreach ([$current, $source] as $spine) {
            foreach ($spine as $block) {
                $uid = $block['uid'];
                if (isset($survivorsByUid[$uid])) {
                    $result[] = $survivorsByUid[$uid];
                    unset($survivorsByUid[$uid]);
                }
            }
        }
        return $result;
    }

    /**
     * Order the surviving blocks under source's spine. Kept current-only blocks
     * are inserted immediately after the most-recent both-sides anchor in
     * current's order. Current-only blocks before any anchor go at the front.
     *
     * @param array<string, SerializedMatrixBlock> $survivorsByUid
     * @param list<SerializedMatrixBlock> $current
     * @param list<SerializedMatrixBlock> $source
     * @return list<SerializedMatrixBlock>
     */
    private static function orderBySourceSpine(array $survivorsByUid, array $current, array $source): array
    {
        $sourceUids = array_fill_keys(array_column($source, 'uid'), true);
        $anchorByCurrentOnly = [];
        $currentOnlyOrder = [];
        $lastAnchor = null;

        foreach ($current as $block) {
            $uid = $block['uid'];
            $isSurvivor = isset($survivorsByUid[$uid]);
            if ($isSurvivor && isset($sourceUids[$uid])) {
                $lastAnchor = $uid;
            } elseif ($isSurvivor) {
                $anchorByCurrentOnly[$uid] = $lastAnchor;
                $currentOnlyOrder[] = $uid;
            }
        }

        $result = [];
        foreach ($currentOnlyOrder as $uid) {
            if ($anchorByCurrentOnly[$uid] === null) {
                $result[] = $survivorsByUid[$uid];
            }
        }

        foreach ($source as $block) {
            $uid = $block['uid'];
            if (!isset($survivorsByUid[$uid])) {
                continue;
            }
            $result[] = $survivorsByUid[$uid];
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
     * (['entries' => ['uid:<uid>' => {…}], 'sortOrder' => [<uid>, …]]) from an
     * ordered list of surviving blocks.
     *
     * EVERY block is keyed `uid:<uid>` — existing draft clones reuse their draft
     * entry UID so Craft patches that exact entry; brand-new blocks (from accepted
     * `added` atoms) get a freshly generated UUID, exactly as the CP posts a newly
     * added block.
     *
     * Why uniform UID keying matters: Craft picks UID-mode vs ID-mode by inspecting
     * only the FIRST key (Matrix::_createEntriesFromSerializedData → array_key_first
     * starts_with 'uid:'). In UID-mode it assigns each new entry the UID from its
     * key. The old approach keyed new blocks `newN`; whenever an existing `uid:`
     * block was also present the payload was UID-mode, so Craft set the new entry's
     * uid to the literal string "newN" (not a valid UUID) and the block was silently
     * DROPPED — accepting an `added` block never landed it on canonical. Generating
     * a real UUID per new block removes that footgun entirely; display order still
     * comes from `sortOrder`.
     *
     * @param list<OrderedMatrixBlock> $ordered
     * @param MatrixCanonicalDraftMap $currentByCanonicalUid
     * @return MatrixSetValue
     */
    public static function buildMatrixSetValue(array $ordered, array $currentByCanonicalUid): array
    {
        $entries = [];
        $sortOrder = [];

        foreach ($ordered as $block) {
            $existing = $currentByCanonicalUid[$block['uid']] ?? null;
            $uid = $existing !== null ? $existing['draftEntryUid'] : StringHelper::UUID();
            $sortOrder[] = $uid;
            $entries['uid:' . $uid] = $block['payload'];
        }

        return ['entries' => $entries, 'sortOrder' => $sortOrder];
    }

    /** @param string[] $fieldAtoms "field:<handle>" atom keys */
    private function applyFieldAtoms(Entry $draft, Entry $source, array $fieldAtoms): void
    {
        foreach ($fieldAtoms as $atom) {
            $handle = AtomKey::parse($atom)['handle'];
            if ($handle === 'title') {
                $draft->title = $source->title;
            } elseif ($handle === 'slug') {
                $draft->slug = $source->slug;
            } else {
                $draft->setFieldValue($handle, $source->getFieldValue($handle));
            }
        }
    }

    /** @param array<int, MatrixBlockAtom> $blockAtoms */
    private function applyMatrixAtoms(Entry $draft, Entry $source, string $fieldHandle, array $blockAtoms, bool $acceptedReorder): void
    {
        $current = $this->serializeMatrixBlocks($draft->getFieldValue($fieldHandle));
        $sourceBlocks = $this->serializeMatrixBlocks($source->getFieldValue($fieldHandle));
        $ordered = self::orderMatrixBlocks(
            self::buildMatrixBlockList($current, $sourceBlocks, $blockAtoms),
            $current,
            $sourceBlocks,
            $acceptedReorder,
        );
        $draft->setFieldValue($fieldHandle, self::buildMatrixSetValue($ordered, array_column($current, null, 'uid')));
    }

    /** @param EntryQuery|ElementCollection|list<Entry>|null $matrixValue @return list<SerializedMatrixBlock> */
    private function serializeMatrixBlocks(EntryQuery|ElementCollection|array|null $matrixValue): array
    {
        $result = [];
        foreach (MatrixValue::toEntries($matrixValue) as $block) {
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

    /** @param string[] $acceptedAtoms @return Entry */
    public function merge(Entry $canonical, Entry $source, array $acceptedAtoms, bool $deleteSourceDraft = false): Entry
    {
        $diffService = $this->diffService ?? Delta::getInstance()->diff;
        self::validateAtoms(self::collectAvailableAtoms($diffService->compare($canonical, $source)), $acceptedAtoms);

        $fieldAtoms = [];
        $matrixBlockAtomsByHandle = [];
        $reorderAcceptedHandles = [];

        foreach ($acceptedAtoms as $atom) {
            $parsed = AtomKey::parse($atom);
            match ($parsed['kind']) {
                AtomKind::Field->value => $fieldAtoms[] = $atom,
                AtomKind::MatrixBlock->value => $matrixBlockAtomsByHandle[$parsed['fieldHandle']][] = [
                    'blockUid' => $parsed['blockUid'],
                    'changeType' => $parsed['changeType'],
                ],
                AtomKind::MatrixReorder->value => $reorderAcceptedHandles[$parsed['fieldHandle']] = true,
            };
        }

        $user = \Craft::$app->getUser()->getIdentity();
        $draft = \Craft::$app->getDrafts()->createDraft(
            $canonical,
            $user?->id ?? 0,
            \Craft::t('craft-delta', TranslationKeys::REVIEW_OF_REF, ['ref' => $this->humanRefForSource($source)]),
        );

        $this->applyFieldAtoms($draft, $source, $fieldAtoms);

        foreach (array_unique([...array_keys($matrixBlockAtomsByHandle), ...array_keys($reorderAcceptedHandles)]) as $handle) {
            $this->applyMatrixAtoms(
                $draft,
                $source,
                $handle,
                $matrixBlockAtomsByHandle[$handle] ?? [],
                isset($reorderAcceptedHandles[$handle]),
            );
        }

        if (!\Craft::$app->getElements()->saveElement($draft)) {
            throw new \RuntimeException('Draft validation failed: ' . json_encode($draft->getErrors()));
        }

        $published = \Craft::$app->getDrafts()->applyDraft($draft);
        if (!$published instanceof Entry) {
            throw new \RuntimeException('Failed to publish: ' . json_encode($draft->getErrors()));
        }

        if ($deleteSourceDraft && EntryMeta::draft($source) !== null) {
            \Craft::$app->getElements()->deleteElement($source, true);
        }

        return $published;
    }

    /** @return string[] */
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
            if (!str_ends_with($fd->fieldType, '\\Matrix')) {
                $atoms[] = AtomKind::Field->value . ':' . $fd->fieldHandle;
                continue;
            }

            $changes = json_decode($fd->diffHtml, true);
            if (!is_array($changes)) {
                continue;
            }

            $hasReorder = false;
            foreach ($changes as $change) {
                $type = $change['type'] ?? null;
                if ($type === DiffChangeType::Reordered->value) {
                    $hasReorder = true;
                } elseif (in_array($type, DiffChangeType::atomValues(), true) && !empty($change['blockUid'])) {
                    $atoms[] = AtomKind::MatrixBlock->value . ':' . $fd->fieldHandle . ':' . $change['blockUid'] . ':' . $type;
                }
            }

            if ($hasReorder) {
                $atoms[] = AtomKind::MatrixReorder->value . ':' . $fd->fieldHandle;
            }
        }

        return $atoms;
    }

    private function humanRefForSource(Entry $source): string
    {
        $revisionBehavior = EntryMeta::revision($source);
        if ($revisionBehavior?->revisionNum !== null) {
            return \Craft::t('craft-delta', TranslationKeys::REV_NUM, ['num' => $revisionBehavior->revisionNum]);
        }
        $draftBehavior = EntryMeta::draft($source);
        if ($draftBehavior !== null) {
            return $draftBehavior->draftName ?? \Craft::t('craft-delta', TranslationKeys::DRAFT);
        }
        return \Craft::t('craft-delta', TranslationKeys::SOURCE);
    }
}
