<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\errors\FieldNotFoundException;
use craft\fieldlayoutelements\CustomField;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\helpers\DiffHtml;
use zeixcom\craftdelta\models\DiffResult;
use zeixcom\craftdelta\models\FieldDiff;

class DiffService extends Component
{
    public ?FieldDiffService $fieldDiffService = null;

    public function compare(ElementInterface $older, ElementInterface $newer): DiffResult
    {
        $fieldDiffService = $this->fieldDiffService ?? Delta::getInstance()->fieldDiff;
        $fieldLayout = $newer->getFieldLayout();

        if (!$fieldLayout) {
            return new DiffResult(['fieldDiffs' => []]);
        }

        $fieldDiffs = $this->compareAttributes($older, $newer, $fieldDiffService);

        foreach ($fieldLayout->getTabs() as $tab) {
            $tabName = $tab->name ?? '';

            foreach ($tab->getElements() as $layoutElement) {
                if (!$layoutElement instanceof CustomField) {
                    continue;
                }

                try {
                    $field = $layoutElement->getField();
                } catch (FieldNotFoundException) {
                    continue;
                }

                $handle = $layoutElement->attribute();

                try {
                    $diff = $fieldDiffService->diff($field, $older->getFieldValue($handle), $newer->getFieldValue($handle));
                } catch (\Throwable $e) {
                    Craft::error("Failed to read field '{$handle}': {$e->getMessage()}", __METHOD__);
                    $fieldDiffs[] = FieldDiff::make($field, true, DiffHtml::unableToDiffField(), ['additions' => 0, 'deletions' => 0], $tabName);
                    continue;
                }

                if ($diff !== null) {
                    $diff->tabName = $tabName;
                    $fieldDiffs[] = $diff;
                } else {
                    $fieldDiffs[] = FieldDiff::make($field, false, '', ['additions' => 0, 'deletions' => 0], $tabName);
                }
            }
        }

        return new DiffResult([
            'fieldDiffs' => $fieldDiffs,
        ]);
    }

    /**
     * Site names (excluding $excludeSiteId) where the draft differs from
     * canonical. A review + apply are single-site, so this powers the reviewer
     * warning and the source-draft delete guard that stop another language's
     * edits from being silently discarded (#7).
     *
     * @return array<int, string> siteId => site name
     */
    public function otherSitesWithChanges(int $entryId, int $draftId, int $excludeSiteId): array
    {
        $revision = Delta::getInstance()->revision;
        $anchor = $revision->getCanonical($entryId, $excludeSiteId);
        if (!$anchor instanceof ElementInterface) {
            return [];
        }

        $names = [];
        foreach ($anchor->getSupportedSites() as $info) {
            $siteId = (int)(is_array($info) ? $info['siteId'] : $info);
            if ($siteId === $excludeSiteId) {
                continue;
            }
            $canonical = $revision->getCanonical($entryId, $siteId);
            $draft = $revision->getDraftByDraftId($draftId, $entryId, $siteId);
            if ($canonical instanceof ElementInterface
                && $draft instanceof ElementInterface
                && $this->compare($canonical, $draft)->hasChanges()) {
                $names[$siteId] = Craft::$app->getSites()->getSiteById($siteId)?->name ?? ('#' . $siteId);
            }
        }
        return $names;
    }

    /** @return FieldDiff[] */
    private function compareAttributes(ElementInterface $older, ElementInterface $newer, FieldDiffService $fieldDiffService): array
    {
        $diffs = [];
        foreach (['title', 'slug'] as $attr) {
            $oldVal = (string)($older->$attr ?? '');
            $newVal = (string)($newer->$attr ?? '');
            $changed = $oldVal !== $newVal;
            // emit unchanged attributes too (like custom fields) so the "all" view lists
            // them; skip only when empty on both sides to avoid a blank row
            if (!$changed && $oldVal === '' && $newVal === '') {
                continue;
            }
            $diffs[] = new FieldDiff([
                'fieldHandle' => $attr,
                'fieldLabel' => ucfirst($attr),
                'fieldType' => 'attribute',
                'hasChanges' => $changed,
                'diffHtml' => $changed ? $fieldDiffService->getTextDiffer()->diff($oldVal, $newVal) : '',
                'stats' => $changed ? ['additions' => 1, 'deletions' => 1] : ['additions' => 0, 'deletions' => 0],
            ]);
        }
        return $diffs;
    }
}
