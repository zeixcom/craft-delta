<?php

declare(strict_types=1);

namespace zeixcom\craftdelta;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;
use zeixcom\craftdelta\assets\diff\DiffAsset;
use zeixcom\craftdelta\models\Settings;
use zeixcom\craftdelta\services\DiffService;
use zeixcom\craftdelta\services\FieldDiffService;
use zeixcom\craftdelta\services\RevisionService;

/**
 * Craft Delta — inline revision diffing for Craft CMS.
 *
 * @property-read DiffService $diff
 * @property-read FieldDiffService $fieldDiff
 * @property-read RevisionService $revision
 */
class Delta extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'diff' => DiffService::class,
                'fieldDiff' => FieldDiffService::class,
                'revision' => RevisionService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@craftdelta', $this->getBasePath());

        $this->registerCpRoutes();
        $this->registerEditorAssets();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('craft-delta/settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * Register control panel routes for full-page diff view.
     */
    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['craft-delta/compare'] = 'craft-delta/diff/compare-full-page';
            }
        );
    }

    /**
     * Inject the "Compare Revisions" button into entry editor sidebars.
     */
    private function registerEditorAssets(): void
    {
        Event::on(
            Entry::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function (DefineHtmlEvent $event) {
                $entry = $event->sender;

                if ($entry->getSection() === null) {
                    return;
                }

                $canonicalId = $entry->getCanonicalId();
                $isDraft = $entry->getIsDraft();
                $isPublishedDraft = $isDraft && !$entry->getIsUnpublishedDraft();

                $revisions = $this->revision->getRevisions($canonicalId, 1);
                if (count($revisions) < 1 && !$isPublishedDraft) {
                    return;
                }

                $view = Craft::$app->getView();
                $view->registerAssetBundle(DiffAsset::class);

                /** @var Settings $settings */
                $settings = $this->getSettings();
                $showUnchanged = $settings->defaultShowUnchanged ? 'true' : 'false';
                $isDraftJs = $isDraft ? 'true' : 'false';
                $draftId = $entry->draftId ?? 'null';

                $siteId = $entry->siteId;
                $view->registerJs(
                    "Craft.Delta.init({$canonicalId}, {showUnchanged: {$showUnchanged}, isDraft: {$isDraftJs}, draftId: {$draftId}, siteId: {$siteId}});"
                );

                $label = htmlspecialchars(Craft::t('craft-delta', 'Compare Revisions'));
                $hint = htmlspecialchars(Craft::t('craft-delta', 'View a side-by-side diff of changes between revisions.'));
                $event->html .= '<div class="meta" id="delta-meta">'
                    . '<button id="delta-compare-btn" type="button">' . $label . '</button>'
                    . '<p class="delta-meta-hint">' . $hint . '</p>'
                    . '</div>';
            }
        );
    }
}
