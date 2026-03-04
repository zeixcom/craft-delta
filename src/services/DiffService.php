<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use zeixcom\craftdelta\models\DiffResult;
use zeixcom\craftdelta\models\FieldDiff;

/**
 * Core diff orchestration — compares two element revisions field by field.
 */
class DiffService extends Component
{
    /**
     * Compare two element revisions and return a structured diff.
     */
    public function compare(ElementInterface $older, ElementInterface $newer): DiffResult
    {
        $fieldDiffService = \zeixcom\craftdelta\Delta::getInstance()->fieldDiff;

        $fieldDiffs = [];
        $fieldLayout = $newer->getFieldLayout();

        if (!$fieldLayout) {
            return new DiffResult(['fieldDiffs' => []]);
        }

        $fieldDiffs = array_merge(
            $fieldDiffs,
            $this->compareAttributes($older, $newer, $fieldDiffService),
        );

        foreach ($fieldLayout->getTabs() as $tab) {
            $tabName = $tab->name ?? '';

            foreach ($tab->getElements() as $layoutElement) {
                if (!$layoutElement instanceof \craft\fieldlayoutelements\CustomField) {
                    continue;
                }

                try {
                    $field = $layoutElement->getField();
                } catch (\craft\errors\FieldNotFoundException) {
                    continue;
                }

                $handle = $layoutElement->attribute();

                try {
                    $oldValue = $older->getFieldValue($handle);
                    $newValue = $newer->getFieldValue($handle);

                    $diff = $fieldDiffService->diff($field, $oldValue, $newValue);

                    if ($diff !== null) {
                        $diff->tabName = $tabName;
                        $fieldDiffs[] = $diff;
                    } else {
                        $fieldDiffs[] = new FieldDiff([
                            'fieldHandle' => $handle,
                            'fieldLabel' => $field->name,
                            'fieldType' => get_class($field),
                            'tabName' => $tabName,
                            'hasChanges' => false,
                            'diffHtml' => '',
                            'stats' => ['additions' => 0, 'deletions' => 0],
                        ]);
                    }
                } catch (\Exception $e) {
                    Craft::warning("Failed to diff field '{$handle}': {$e->getMessage()}", __METHOD__);
                    $fieldDiffs[] = new FieldDiff([
                        'fieldHandle' => $handle,
                        'fieldLabel' => $field->name,
                        'fieldType' => get_class($field),
                        'tabName' => $tabName,
                        'hasChanges' => true,
                        'diffHtml' => '<em class="delta-error">' . htmlspecialchars(Craft::t('craft-delta', 'Unable to diff this field.')) . '</em>',
                        'stats' => ['additions' => 0, 'deletions' => 0],
                    ]);
                }
            }
        }

        return new DiffResult([
            'fieldDiffs' => $fieldDiffs,
            'olderRevisionId' => $older->id,
            'newerRevisionId' => $newer->id,
            'olderRevisionNum' => $older->revisionNum ?? null,
            'newerRevisionNum' => $newer->revisionNum ?? null,
        ]);
    }

    /**
     * Compare native element attributes like title and slug.
     *
     * @return FieldDiff[]
     */
    private function compareAttributes(
        ElementInterface $older,
        ElementInterface $newer,
        FieldDiffService $fieldDiffService,
    ): array {
        $diffs = [];
        $attributes = ['title', 'slug'];

        foreach ($attributes as $attr) {
            $oldVal = $older->$attr ?? '';
            $newVal = $newer->$attr ?? '';

            if ($oldVal !== $newVal) {
                $diffs[] = new FieldDiff([
                    'fieldHandle' => $attr,
                    'fieldLabel' => ucfirst($attr),
                    'fieldType' => 'attribute',
                    'hasChanges' => true,
                    'diffHtml' => $fieldDiffService->getTextDiffer()
                        ->diff((string)$oldVal, (string)$newVal),
                    'stats' => [
                        'additions' => 1,
                        'deletions' => 1,
                    ],
                ]);
            }
        }

        return $diffs;
    }
}
