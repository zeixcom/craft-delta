<?php

declare(strict_types=1);

namespace zeixcom\craftdelta;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use yii\base\Event;
use zeixcom\craftdelta\assets\diff\DiffAsset;
use zeixcom\craftdelta\models\DraftWorkflow;
use zeixcom\craftdelta\models\Settings;
use zeixcom\craftdelta\services\DiffService;
use zeixcom\craftdelta\services\EmailService;
use zeixcom\craftdelta\services\FieldDiffService;
use zeixcom\craftdelta\services\MergeService;
use zeixcom\craftdelta\services\RevisionService;
use zeixcom\craftdelta\services\WorkflowService;

/**
 * Craft Delta — inline revision diffing for Craft CMS.
 *
 * @property-read DiffService $diff
 * @property-read FieldDiffService $fieldDiff
 * @property-read RevisionService $revision
 * @property-read MergeService $merge
 * @property-read WorkflowService $workflow
 * @property-read EmailService $email
 */
class Delta extends Plugin
{
    public string $schemaVersion = '2.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'diff' => DiffService::class,
                'fieldDiff' => FieldDiffService::class,
                'revision' => RevisionService::class,
                'merge' => MergeService::class,
                'workflow' => WorkflowService::class,
                'email' => EmailService::class,
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
        $this->registerWorkflowColumn();
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
                $event->rules['POST craft-delta/workflow/submit'] = 'craft-delta/workflow/submit';
                $event->rules['POST craft-delta/workflow/approve'] = 'craft-delta/workflow/approve';
                $event->rules['POST craft-delta/workflow/reject'] = 'craft-delta/workflow/reject';
                $event->rules['craft-delta/workflow/assignees'] = 'craft-delta/workflow/assignees';
            }
        );
    }

    /**
     * Register the three general workflow permissions. These are no longer
     * scoped per section: which sections a user may see and edit is governed by
     * Craft's native section permissions (viewEntries, viewPeerEntryDrafts, …),
     * managed via the user's other groups.
     */
    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('craft-delta', 'Craft Delta'),
                    'permissions' => [
                        'craftdelta-submitDraft' => [
                            'label' => Craft::t('craft-delta', 'Submit drafts for review'),
                        ],
                        'craftdelta-reviewDraft' => [
                            'label' => Craft::t('craft-delta', 'Review submitted drafts'),
                        ],
                        'craftdelta-applyReview' => [
                            'label' => Craft::t('craft-delta', 'Apply review-mode changes'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Register a "Workflow" column on entry index pages showing a status pill
     * for entries that have an active workflow row.
     */
    private function registerWorkflowColumn(): void
    {
        Event::on(
            Entry::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function(RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes['craftDeltaWorkflow'] = [
                    'label' => Craft::t('craft-delta', 'Workflow'),
                ];
            }
        );

        Event::on(
            Entry::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            function(DefineAttributeHtmlEvent $event) {
                if ($event->attribute !== 'craftDeltaWorkflow') {
                    return;
                }
                /** @var Entry $entry */
                $entry = $event->sender;
                $wf = null;
                $draftId = $entry->draftId;
                if ($draftId) {
                    $wf = $this->workflow->getByDraftId((int)$draftId);
                }
                if ($wf === null) {
                    $event->html = '';
                    return;
                }
                $event->html = '<span class="status ' . htmlspecialchars($wf->state) . '"></span>' . htmlspecialchars($wf->statusLabel());
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
                    // Workflow submit-for-review strings
                    'Submit for review',
                    'Reviewer',
                    'Submit',
                    'Cancel',
                    'Loading…',
                    'No eligible reviewers',
                    'Failed to load reviewers.',
                    'Failed to submit for review.',
                    'Pending review',
                    'Approved',
                    'Approved — scheduled',
                    'Rejected',
                    // Workflow reviewer toolbar strings (v2.0)
                    'Approve all',
                    'Schedule for…',
                    'Granular review',
                    'Reject',
                    'Approve and publish this draft now?',
                    'Publish at (YYYY-MM-DD HH:MM):',
                    'Optional note for the author:',
                    'Reject this draft? Rejection is final.',
                    'Draft approved.',
                    'Draft scheduled.',
                    'Draft rejected.',
                    'Approve failed.',
                    'Schedule failed.',
                    'Reject failed.',
                ]);

                /** @var Settings $settings */
                $settings = $this->getSettings();
                $showUnchanged = $settings->defaultShowUnchanged ? 'true' : 'false';
                $isDraftJs = $isDraft ? 'true' : 'false';
                $draftId = $entry->draftId ?? 'null';

                $siteId = $entry->siteId;
                $view->registerJs(
                    "Craft.Delta.init({$canonicalId}, {showUnchanged: {$showUnchanged}, isDraft: {$isDraftJs}, draftId: {$draftId}, siteId: {$siteId}});" .
                    "(function(){var \$btn=$('#delta-submit-btn');if(\$btn.length){\$btn.on('click',function(){Craft.Delta.openSubmitModal(\$btn.data('draft-id'),\$btn.data('section-uid'),function(){location.reload();});});}})();"
                );

                $label = htmlspecialchars(Craft::t('craft-delta', 'Compare Revisions'));
                $hint = htmlspecialchars(Craft::t('craft-delta', 'View a side-by-side diff of changes between revisions.'));

                $workflowHtml = '';
                if ($settings->enableWorkflow && $isPublishedDraft) {
                    $user = Craft::$app->getUser()->getIdentity();
                    $section = $entry->getSection();
                    if ($user !== null && $section !== null && $user->can('craftdelta-submitDraft')) {
                        $wf = $this->workflow->getByDraftId((int)$entry->draftId);
                        if ($wf === null) {
                            $submitLabel = htmlspecialchars(Craft::t('craft-delta', 'Submit for review'));
                            $workflowHtml = '<button id="delta-submit-btn" type="button"'
                                . ' data-draft-id="' . (int)$entry->draftId . '"'
                                . ' data-section-uid="' . htmlspecialchars($section->uid) . '">'
                                . $submitLabel
                                . '</button>';
                        } else {
                            $workflowHtml = '<p class="delta-workflow-status delta-workflow-status--' . htmlspecialchars($wf->state) . '">'
                                . htmlspecialchars($wf->statusLabel())
                                . '</p>';
                        }
                    }
                }

                $event->html .= '<div class="meta" id="delta-meta">'
                    . '<button id="delta-compare-btn" type="button">' . $label . '</button>'
                    . $workflowHtml
                    . '<p class="delta-meta-hint">' . $hint . '</p>'
                    . '</div>';
            }
        );
    }
}
