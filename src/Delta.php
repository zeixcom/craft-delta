<?php

declare(strict_types=1);

namespace zeixcom\craftdelta;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\CreateTwigEvent;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;
use zeixcom\craftdelta\assets\diff\DiffAsset;
use zeixcom\craftdelta\i18n\TranslationKeys;
use zeixcom\craftdelta\models\Review;
use zeixcom\craftdelta\models\Settings;
use zeixcom\craftdelta\services\DiffService;
use zeixcom\craftdelta\services\EmailService;
use zeixcom\craftdelta\services\FieldDiffService;
use zeixcom\craftdelta\services\MergeService;
use zeixcom\craftdelta\services\ReviewCommentService;
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
 * @property-read ReviewCommentService $reviewComment
 * @property-read EmailService $email
 * @method Settings getSettings()
 */
class Delta extends Plugin
{
    /** Workflow permission handles — single source of truth for every ->can() check. */
    public const PERMISSION_SUBMIT = 'craftdelta-submitDraft';
    public const PERMISSION_REVIEW = 'craftdelta-reviewDraft';
    public const PERMISSION_APPLY = 'craftdelta-applyReview';

    public string $schemaVersion = '2.1.2';
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
                'reviewComment' => ReviewCommentService::class,
                'email' => EmailService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@craftdelta', $this->getBasePath());

        $this->registerTwigGlobals();
        $this->registerCpRoutes();
        $this->registerCpNavItem();
        $this->registerEditorAssets();
        $this->registerPermissions();
        $this->registerWorkflowColumn();
        $this->registerDraftDeleteCleanup();
    }

    /**
     * Cancel active reviews when their draft is deleted outside the workflow.
     * BEFORE delete, because the reviews.draftId FK SET NULLs on deletion and
     * the rows would otherwise be stranded as zombie "open" reviews.
     */
    private function registerDraftDeleteCleanup(): void
    {
        Event::on(
            Entry::class,
            Element::EVENT_BEFORE_DELETE,
            function(Event $event) {
                /** @var Entry $entry */
                $entry = $event->sender;
                if ($entry->getIsDraft() && $entry->draftId) {
                    $this->workflow->cancelForDeletedDraft((int)$entry->draftId);
                }
            }
        );
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
     * Register the "Reviews" CP nav item → the review dashboard. Done via the
     * nav-items event (not hasCpSection) so it does NOT require a separate
     * accessplugin permission: anyone who can submit or review sees it, and the
     * route itself is gated in WorkflowController::actionIndex. Hidden when the
     * workflow is off or the user can neither submit nor review.
     */
    private function registerCpNavItem(): void
    {
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function(RegisterCpNavItemsEvent $event) {
                if (!$this->getSettings()->enableWorkflow) {
                    return;
                }
                /** @var \craft\elements\User|null $user */
                $user = Craft::$app->getUser()->getIdentity();
                if ($user === null) {
                    return;
                }
                if (!$user->admin && !$user->can(self::PERMISSION_SUBMIT) && !$user->can(self::PERMISSION_REVIEW)) {
                    return;
                }
                $event->navItems[] = [
                    'url' => 'delta-reviews',
                    'label' => Craft::t('craft-delta', TranslationKeys::WORKFLOW_REVIEWS_TITLE),
                    // Mask variant: the CP tints nav icons as monochrome glyphs,
                    // so the branded icon.svg (solid background) renders as a
                    // black square here.
                    'icon' => '@craftdelta/icon-mask.svg',
                ];
            },
        );
    }

    /**
     * Expose translation keys to Twig as `deltaKeys.compareRevisions`, etc.
     */
    private function registerTwigGlobals(): void
    {
        Event::on(
            View::class,
            View::EVENT_AFTER_CREATE_TWIG,
            function(CreateTwigEvent $event) {
                $event->twig->addGlobal('deltaKeys', (object)TranslationKeys::propertyMap());
            },
        );
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
                $event->rules['delta-reviews'] = 'craft-delta/workflow/index';
                $event->rules['delta-compare'] = 'craft-delta/diff/compare-full-page';
                // Legacy URL, kept for bookmarks. Avoid linking to it: the
                // handle-prefixed path additionally demands the
                // accessPlugin-craft-delta permission (403 for plain editors).
                $event->rules['craft-delta/compare'] = 'craft-delta/diff/compare-full-page';
                $event->rules['POST craft-delta/workflow/submit'] = 'craft-delta/workflow/submit';
                $event->rules['POST craft-delta/workflow/approve'] = 'craft-delta/workflow/approve';
                $event->rules['POST craft-delta/workflow/request-changes'] = 'craft-delta/workflow/request-changes';
                $event->rules['POST craft-delta/workflow/decline'] = 'craft-delta/workflow/decline';
                $event->rules['POST craft-delta/workflow/re-request'] = 'craft-delta/workflow/re-request';
                $event->rules['POST craft-delta/workflow/withdraw'] = 'craft-delta/workflow/withdraw';
                $event->rules['POST craft-delta/workflow/publish'] = 'craft-delta/workflow/publish';
                $event->rules['POST craft-delta/workflow/comment'] = 'craft-delta/workflow/comment';
                $event->rules['POST craft-delta/workflow/resolve-comment'] = 'craft-delta/workflow/resolve-comment';
                $event->rules['craft-delta/workflow/thread'] = 'craft-delta/workflow/thread';
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
                    'heading' => Craft::t('craft-delta', TranslationKeys::PLUGIN_NAME),
                    'permissions' => [
                        self::PERMISSION_SUBMIT => [
                            'label' => Craft::t('craft-delta', TranslationKeys::PERMISSION_SUBMIT_DRAFTS),
                        ],
                        self::PERMISSION_REVIEW => [
                            'label' => Craft::t('craft-delta', TranslationKeys::PERMISSION_REVIEW_DRAFTS),
                        ],
                        self::PERMISSION_APPLY => [
                            'label' => Craft::t('craft-delta', TranslationKeys::PERMISSION_APPLY_REVIEW),
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
                    'label' => Craft::t('craft-delta', TranslationKeys::WORKFLOW),
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

                $label = htmlspecialchars(Craft::t('craft-delta', TranslationKeys::COMPARE_REVISIONS));
                $hint = htmlspecialchars(Craft::t('craft-delta', TranslationKeys::VIEW_SIDE_BY_SIDE_HINT));

                $workflowHtml = '';
                if ($settings->enableWorkflow && $isPublishedDraft) {
                    /** @var \craft\elements\User|null $user */
                    $user = Craft::$app->getUser()->getIdentity();
                    $section = $entry->getSection();
                    if ($user !== null && $section !== null && $user->can(self::PERMISSION_SUBMIT)) {
                        $wf = $this->workflow->getByDraftId((int)$entry->draftId);
                        // A withdrawn review can be resubmitted (WorkflowService
                        // re-opens it in place), so offer the button again.
                        $canResubmit = $wf !== null
                            && $wf->state === Review::STATE_CANCELLED
                            && $wf->appliedAt === null;
                        if ($wf === null || $canResubmit) {
                            $submitLabel = htmlspecialchars(Craft::t('craft-delta', TranslationKeys::SUBMIT_FOR_REVIEW));
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
