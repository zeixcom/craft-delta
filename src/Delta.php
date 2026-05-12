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
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use yii\base\Event;
use zeixcom\craftdelta\assets\diff\DiffAsset;
use zeixcom\craftdelta\models\Settings;
use zeixcom\craftdelta\services\DiffService;
use zeixcom\craftdelta\services\FieldDiffService;
use zeixcom\craftdelta\services\RevisionService;
use zeixcom\craftdelta\services\MergeService;

/**
 * Craft Delta — inline revision diffing for Craft CMS.
 *
 * @property-read DiffService $diff
 * @property-read FieldDiffService $fieldDiff
 * @property-read RevisionService $revision
 * @property-read MergeService $merge
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
                'merge' => MergeService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@craftdelta', $this->getBasePath());

        $this->registerCpRoutes();
        $this->registerEditorAssets();
        $this->registerPermissions();
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
            function(RegisterUrlRulesEvent $event) {
                $event->rules['craft-delta/compare'] = 'craft-delta/diff/compare-full-page';
            }
        );
    }

    /**
     * Register per-section permissions for the submit/review workflow and
     * the existing apply-review-mode permission.
     */
    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $sections = Craft::$app->getEntries()->getAllSections();
                if (count($sections) === 0) {
                    return;
                }

                $sectionPermissions = [];
                foreach ($sections as $section) {
                    $sectionPermissions["craftdelta-submitDraft:{$section->uid}"] = [
                        'label' => Craft::t('craft-delta', 'Submit drafts for review in "{section}"', [
                            'section' => $section->name,
                        ]),
                    ];
                    $sectionPermissions["craftdelta-reviewDraft:{$section->uid}"] = [
                        'label' => Craft::t('craft-delta', 'Review submitted drafts in "{section}"', [
                            'section' => $section->name,
                        ]),
                    ];
                    $sectionPermissions["craftdelta-applyReview:{$section->uid}"] = [
                        'label' => Craft::t('craft-delta', 'Apply review-mode changes for "{section}"', [
                            'section' => $section->name,
                        ]),
                    ];
                }

                $event->permissions[] = [
                    'heading' => Craft::t('craft-delta', 'Craft Delta'),
                    'permissions' => $sectionPermissions,
                ];
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
            function(DefineHtmlEvent $event) {
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

                // Register every source string the JS layer passes to Craft.t().
                // Without this, Craft.t() falls back to the source string and
                // non-English users see English UI for the dynamic JS bits.
                $view->registerTranslations('craft-delta', [
                    'Apply {count} accepted',
                    '{decided} of {total} decided',
                    'At least two revisions are needed to compare.',
                    'Changed only',
                    'Compare Revisions',
                    'Comparing…',
                    'Current Draft',
                    'Current',
                    'Drafts',
                    'Expand',
                    'Failed to load diff.',
                    'Failed to load revisions.',
                    'Loading revisions…',
                    'Open full page',
                    'Revisions',
                    // Review-mode dynamic strings
                    'Resume previous review ({decided} of {total} decided)?',
                    'Resume',
                    'Start fresh',
                    'The entry has changed since your last review; starting fresh.',
                    'The entry has changed since you started reviewing. Please reload the diff and restart your review.',
                    'Discard {decided} decisions?',
                    'Publish {count} accepted changes to this entry? This creates a new revision. Rejected changes will not affect the entry.',
                    'Changes published. Open the entry?',
                    'Validation failed.',
                    'Apply failed.',
                    'No changes to apply.',
                    'Your decisions are still saved. Adjust and try again.',
                    'Your decisions are still saved.',
                ]);

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
