<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\assets\diff;

use craft\web\AssetBundle;

/**
 * Asset bundle for the diff slideout UI.
 */
class DiffAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->css = [
            'css/delta.css',
        ];

        $this->js = [
            'js/delta.js',
        ];

        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        /** @var \craft\web\View $view */
        $view->registerTranslations('craft-delta', [
            'Loading revisions…',
            'Comparing…',
            'Compare Revisions',
            'At least two revisions are needed to compare.',
            'Failed to load revisions.',
            'Failed to load diff.',
            'Current',
            'Current Draft',
            'Drafts',
            'Revisions',
            'Changed only',
            'Expand',
            'Open full page',
        ]);
    }
}
