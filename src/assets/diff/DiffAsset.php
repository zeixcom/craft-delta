<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\assets\diff;

use craft\helpers\Json;
use craft\web\AssetBundle;
use craft\web\View;
use zeixcom\craftdelta\i18n\TranslationKeys;

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
            'js/workflow.js',
        ];

        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        /** @var View $view */
        // Inject at POS_END — after Craft's framework defines window.Craft and
        // after delta.js/workflow.js define Craft.Delta. POS_BEGIN is too early:
        // the framework later replaces any window.Craft stub wholesale, silently
        // dropping _keys. The guard keeps it safe regardless of asset order.
        $view->registerJs(
            'window.Craft=window.Craft||{};Craft.Delta=Craft.Delta||{};Craft.Delta._keys=' . Json::encode(TranslationKeys::jsPropertyMap()) . ';',
            View::POS_END,
        );
        $view->registerTranslations('craft-delta', TranslationKeys::jsMessageKeys());
    }
}
