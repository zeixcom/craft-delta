<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\ElementCollection;
use zeixcom\craftdelta\enums\DiffChangeType;
use zeixcom\craftdelta\helpers\DiffHtml;

/**
 * @phpstan-import-type DiffStats from \zeixcom\craftdelta\types\ArrayTypes
 */
class RelationDiffer implements DifferInterface
{
    private const ASSET_THUMB_SIZE = 64;

    public function diff(mixed $oldValue, mixed $newValue): ?string
    {
        $oldElements = $this->resolveElements($oldValue);
        $newElements = $this->resolveElements($newValue);

        $oldById = $this->indexById($oldElements);
        $newById = $this->indexById($newElements);

        $added = array_diff_key($newById, $oldById);
        $removed = array_diff_key($oldById, $newById);

        if (empty($added) && empty($removed)) {
            return null;
        }

        return implode("\n", [
            ...array_map(fn($e) => $this->renderLine($e, DiffChangeType::Removed->value), $removed),
            ...array_map(fn($e) => $this->renderLine($e, DiffChangeType::Added->value), $added),
        ]);
    }

    private function renderLine(ElementInterface $element, string $changeType): string
    {
        $added = $changeType === DiffChangeType::Added->value;

        if ($element instanceof Asset) {
            return $this->renderAssetLine($element, $added ? 'delta-relation-added' : 'delta-relation-removed', $added ? '+' : '-');
        }

        return DiffHtml::relationLine((string)$element, $added);
    }

    private function renderAssetLine(Asset $asset, string $cssClass, string $sign): string
    {
        $thumbUrl = $this->lookupAssetThumbUrl($asset);
        $filename = htmlspecialchars($asset->filename ?? (string)$asset, ENT_QUOTES, 'UTF-8');
        $alt = htmlspecialchars($asset->alt ?? '', ENT_QUOTES, 'UTF-8');

        $metaParts = [];
        if ($asset->kind === Asset::KIND_IMAGE && $asset->width && $asset->height) {
            $metaParts[] = sprintf('%d×%d', $asset->width, $asset->height);
        }
        if ($asset->size) {
            $metaParts[] = htmlspecialchars($asset->getFormattedSize(0), ENT_QUOTES, 'UTF-8');
        }
        $meta = $metaParts
            ? '<span class="delta-asset-meta">' . implode(' &middot; ', $metaParts) . '</span>'
            : '';

        $thumbHtml = $thumbUrl
            ? sprintf(
                '<img class="delta-asset-thumb" src="%s" alt="%s" width="%d" height="%d" loading="lazy">',
                htmlspecialchars($thumbUrl, ENT_QUOTES, 'UTF-8'),
                $alt,
                self::ASSET_THUMB_SIZE,
                self::ASSET_THUMB_SIZE,
            )
            : '<span class="delta-asset-thumb delta-asset-thumb-empty"></span>';

        return sprintf(
            '<div class="%s delta-relation-asset"><span class="delta-relation-sign">%s</span>%s<span class="delta-asset-info"><span class="delta-asset-filename">%s</span>%s</span></div>',
            $cssClass,
            $sign,
            $thumbHtml,
            $filename,
            $meta,
        );
    }

    /** @return DiffStats */
    public function getStats(mixed $oldValue, mixed $newValue): array
    {
        $oldElements = $this->resolveElements($oldValue);
        $newElements = $this->resolveElements($newValue);

        $oldIds = array_values(array_filter(array_map(fn($e) => $e->id, $oldElements)));
        $newIds = array_values(array_filter(array_map(fn($e) => $e->id, $newElements)));

        return [
            'additions' => count(array_diff($newIds, $oldIds)),
            'deletions' => count(array_diff($oldIds, $newIds)),
        ];
    }

    /**
     * @param ElementQuery|ElementCollection|list<ElementInterface|int|string>|null $value
     * @return list<ElementInterface>
     */
    private function resolveElements(ElementQuery|ElementCollection|array|null $value): array
    {
        if ($value === null) {
            return [];
        }

        if ($value instanceof ElementQuery) {
            return $value->status(null)->all();
        }

        if ($value instanceof ElementCollection) {
            return $value->all();
        }

        $resolved = [];
        $idsToHydrate = [];

        foreach ($value as $item) {
            if ($item instanceof ElementInterface) {
                $resolved[] = $item;
                continue;
            }

            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                // Raw IDs come back from unsaved Matrix sub-fields where Craft
                // hasn't hydrated to Element objects yet.
                $idsToHydrate[] = (int)$item;
            }
        }

        foreach ($idsToHydrate as $id) {
            $element = $this->lookupElementById($id);
            if ($element !== null) {
                $resolved[] = $element;
            }
        }

        return $resolved;
    }

    // Wrapped so unit tests can stub without a Craft kernel.
    protected function lookupElementById(int $id): ?ElementInterface
    {
        return Craft::$app->getElements()->getElementById($id);
    }

    protected function lookupAssetThumbUrl(Asset $asset): ?string
    {
        return Craft::$app->getAssets()->getThumbUrl($asset, self::ASSET_THUMB_SIZE);
    }

    /**
     * @param list<ElementInterface> $elements
     * @return array<int, ElementInterface>
     */
    private function indexById(array $elements): array
    {
        $map = [];
        foreach ($elements as $element) {
            if ($element->id !== null) {
                $map[$element->id] = $element;
            }
        }

        return $map;
    }
}
