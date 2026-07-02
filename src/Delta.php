<?php

declare(strict_types=1);

namespace zeixcom\craftdelta;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\CreateTwigEvent;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\UserPermissions;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\View;
use Twig\TwigFilter;
use yii\base\Event;
use zeixcom\craftdelta\assets\diff\DiffAsset;
use zeixcom\craftdelta\helpers\DiffHtml;
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
    public string $schemaVersion = '2.1.3';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'fieldDiff' => FieldDiffService::class,
                'diff' => DiffService::class,
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
        Event::on(Entry::class, Element::EVENT_BEFORE_DELETE, function(Event $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            if ($entry->getIsDraft() && $entry->draftId) {
                $this->workflow->cancelForDeletedDraft((int)$entry->draftId);
            }
        });
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
        Event::on(Cp::class, Cp::EVENT_REGISTER_CP_NAV_ITEMS, function(RegisterCpNavItemsEvent $event) {
            if (!$this->getSettings()->enableWorkflow) {
                return;
            }
            /** @var User|null $user */
            $user = Craft::$app->getUser()->getIdentity();
            if ($user === null || (!$user->admin && !$user->can(Permissions::SUBMIT) && !$user->can(Permissions::REVIEW))) {
                return;
            }
            $event->navItems[] = [
                'url' => 'delta-reviews',
                'label' => Craft::t('craft-delta', TranslationKeys::WORKFLOW_REVIEWS_TITLE),
                // Reviewers: reviews awaiting their verdict. Authors: their own
                // approved-but-unpublished submissions ("ready to publish"), so
                // an author gets an in-CP signal, not just the approval email.
                // Admins can do both, so the counts sum (disjoint sets).
                'badgeCount' =>
                    ($user->can(Permissions::REVIEW) ? $this->workflow->countAwaitingVerdict($user) : 0)
                    + ($user->can(Permissions::SUBMIT) ? $this->workflow->countApprovedForAuthor($user) : 0),
                // Mask variant: the CP tints nav icons as monochrome glyphs,
                // so the branded icon.svg (solid background) renders as a
                // black square here.
                'icon' => '@craftdelta/icon-mask.svg',
            ];
        });
    }

    private function registerTwigGlobals(): void
    {
        Event::on(View::class, View::EVENT_AFTER_CREATE_TWIG, function(CreateTwigEvent $event) {
            $event->twig->addGlobal('deltaKeys', (object)TranslationKeys::propertyMap());
            $event->twig->addFilter(new TwigFilter(
                'delta_purify_diff',
                [DiffHtml::class, 'purifyDiffHtml'],
                ['is_safe' => ['html']],
            ));
        });
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'delta-reviews' => 'craft-delta/workflow/index',
                'delta-review' => 'craft-delta/workflow/review',
                'delta-compare' => 'craft-delta/diff/compare-full-page',
                'POST craft-delta/workflow/submit' => 'craft-delta/workflow/submit',
                'POST craft-delta/workflow/approve' => 'craft-delta/workflow/approve',
                'POST craft-delta/workflow/decline' => 'craft-delta/workflow/decline',
                'POST craft-delta/workflow/withdraw' => 'craft-delta/workflow/withdraw',
                'POST craft-delta/workflow/publish' => 'craft-delta/workflow/publish',
                'POST craft-delta/workflow/comment' => 'craft-delta/workflow/comment',
                'POST craft-delta/workflow/resolve-comment' => 'craft-delta/workflow/resolve-comment',
                'craft-delta/workflow/thread' => 'craft-delta/workflow/thread',
                'craft-delta/workflow/assignees' => 'craft-delta/workflow/assignees',
            ];
        });
    }

    /**
     * Register the three general workflow permissions. These are no longer
     * scoped per section: which sections a user may see and edit is governed by
     * Craft's native section permissions (viewEntries, viewPeerEntryDrafts, …),
     * managed via the user's other groups.
     */
    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $t = static fn(string $key) => Craft::t('craft-delta', $key);
            $event->permissions[] = [
                'heading' => $t(TranslationKeys::PLUGIN_NAME),
                'permissions' => [
                    Permissions::SUBMIT => ['label' => $t(TranslationKeys::PERMISSION_SUBMIT_DRAFTS)],
                    Permissions::REVIEW => ['label' => $t(TranslationKeys::PERMISSION_REVIEW_DRAFTS)],
                    Permissions::APPLY => ['label' => $t(TranslationKeys::PERMISSION_APPLY_REVIEW)],
                ],
            ];
        });
    }

    private function registerWorkflowColumn(): void
    {
        Event::on(Entry::class, Element::EVENT_REGISTER_TABLE_ATTRIBUTES, function(RegisterElementTableAttributesEvent $event) {
            $event->tableAttributes['craftDeltaWorkflow'] = [
                'label' => Craft::t('craft-delta', TranslationKeys::WORKFLOW),
            ];
        });

        Event::on(Entry::class, Element::EVENT_DEFINE_ATTRIBUTE_HTML, function(DefineAttributeHtmlEvent $event) {
            if ($event->attribute !== 'craftDeltaWorkflow') {
                return;
            }
            /** @var Entry $entry */
            $entry = $event->sender;
            $wf = $entry->draftId ? $this->workflow->getByDraftId((int)$entry->draftId) : null;
            $event->html = $wf === null
                ? ''
                : '<span class="status ' . htmlspecialchars($wf->statusColor()) . '"></span>' . htmlspecialchars($wf->statusLabel());
        });
    }

    private function registerEditorAssets(): void
    {
        Event::on(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function(DefineHtmlEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            if ($entry->getSection() === null) {
                return;
            }

            $canonicalId = $entry->getCanonicalId();
            $isDraft = $entry->getIsDraft();
            $isPublishedDraft = $isDraft && !$entry->getIsUnpublishedDraft();
            if ($this->revision->getRevisions($canonicalId, 1) === [] && !$isPublishedDraft) {
                return;
            }

            $view = Craft::$app->getView();
            $view->registerAssetBundle(DiffAsset::class);

            /** @var Settings $settings */
            $settings = $this->getSettings();
            $view->registerJs(
                'Craft.Delta.init(' . $canonicalId . ',{showUnchanged:' . ($settings->defaultShowUnchanged ? 'true' : 'false')
                . ',isDraft:' . ($isDraft ? 'true' : 'false')
                . ',draftId:' . ($entry->draftId ?? 'null')
                . ',siteId:' . $entry->siteId . '});'
                . "(function(){var \$btn=$('#delta-submit-btn');if(\$btn.length){\$btn.on('click',function(){Craft.Delta.openSubmitModal(\$btn.data('draft-id'),\$btn.data('section-uid'));});}})();",
            );

            $workflowHtml = '';
            if ($settings->enableWorkflow && $isPublishedDraft) {
                /** @var User|null $user */
                $user = Craft::$app->getUser()->getIdentity();
                $section = $entry->getSection();
                if ($user !== null && $section !== null && $user->can(Permissions::SUBMIT)) {
                    $wf = $this->workflow->getByDraftId((int)$entry->draftId);
                    // A withdrawn OR declined review can be resubmitted
                    // (WorkflowService re-opens it in place), so offer the
                    // button again. For a decline, also link the reviewer's
                    // note above the button so the author sees why before revising.
                    $reopenable = $wf !== null
                        && in_array($wf->state, [Review::STATE_CANCELLED, Review::STATE_DECLINED], true)
                        && $wf->appliedAt === null;
                    if ($wf === null || $reopenable) {
                        $workflowHtml = $wf !== null && $wf->state === Review::STATE_DECLINED
                            ? '<a class="delta-workflow-status delta-workflow-status--declined" href="'
                                . htmlspecialchars(UrlHelper::cpUrl('delta-review', ['reviewId' => $wf->id])) . '">'
                                . htmlspecialchars($wf->statusLabel())
                                . '</a>'
                            : '';
                        $workflowHtml .= '<button id="delta-submit-btn" type="button"'
                            . ' data-draft-id="' . (int)$entry->draftId . '"'
                            . ' data-section-uid="' . htmlspecialchars($section->uid) . '">'
                            . htmlspecialchars(Craft::t('craft-delta', TranslationKeys::SUBMIT_FOR_REVIEW))
                            . '</button>';
                    } else {
                        $workflowHtml = '<p class="delta-workflow-status delta-workflow-status--' . htmlspecialchars($wf->state) . '">'
                            . htmlspecialchars($wf->statusLabel())
                            . '</p>';
                    }
                }
            }

            // Order: primary action (compare) + the hint that describes it,
            // then the workflow affordance (submit button / status) as its own
            // distinct block — the hint previously sat under the submit button,
            // which read as if it described "submit for review".
            $event->html .= '<div class="meta" id="delta-meta">'
                . '<button id="delta-compare-btn" type="button">' . htmlspecialchars(Craft::t('craft-delta', TranslationKeys::COMPARE_REVISIONS)) . '</button>'
                . '<p class="delta-meta-hint">' . htmlspecialchars(Craft::t('craft-delta', TranslationKeys::VIEW_SIDE_BY_SIDE_HINT)) . '</p>'
                . $workflowHtml
                . '</div>';
        });
    }
}
