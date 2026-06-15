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

    /** @return FieldDiff[] */
    private function compareAttributes(ElementInterface $older, ElementInterface $newer, FieldDiffService $fieldDiffService): array
    {
        $diffs = [];
        foreach (['title', 'slug'] as $attr) {
            $oldVal = $older->$attr ?? '';
            $newVal = $newer->$attr ?? '';
            if ($oldVal === $newVal) {
                continue;
            }
            $diffs[] = new FieldDiff([
                'fieldHandle' => $attr,
                'fieldLabel' => ucfirst($attr),
                'fieldType' => 'attribute',
                'hasChanges' => true,
                'diffHtml' => $fieldDiffService->getTextDiffer()->diff((string)$oldVal, (string)$newVal),
                'stats' => ['additions' => 1, 'deletions' => 1],
            ]);
        }
        return $diffs;
    }
}
