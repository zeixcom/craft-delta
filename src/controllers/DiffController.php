<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\i18n\TranslationKeys;

/**
 * Handles diff comparison requests from the control panel.
 */
class DiffController extends Controller
{
    /**
     * Verify the current user can view the given entry's section.
     */
    private function requireEntryAccess(Entry $entry): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        }

        $section = $entry->getSection();
        if ($section === null) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        }

        if (!$user->can("viewEntries:{$section->uid}")) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        }
    }

    /**
     * Returns the diff slideout HTML for two versions.
     *
     * Accepts "current", "draft:<draftId>", or a numeric revision ID
     * for both the `older` and `newer` params.
     */
    public function actionCompare(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getRequiredBodyParam('entryId');
        $olderRef = $request->getRequiredBodyParam('older');
        $newerRef = $request->getRequiredBodyParam('newer');
        $siteId = $request->getBodyParam('siteId') ? (int)$request->getBodyParam('siteId') : null;

        $plugin = Delta::getInstance();

        $canonical = $plugin->revision->getCanonical($entryId, $siteId);
        if (!$canonical instanceof Entry) {
            return $this->asFailure(Craft::t('craft-delta', TranslationKeys::ENTRY_NOT_FOUND));
        }

        $this->requireEntryAccess($canonical);

        $older = $this->resolveVersion($olderRef, $canonical, $siteId);
        $newer = $this->resolveVersion($newerRef, $canonical, $siteId);

        if (!$older || !$newer) {
            return $this->asFailure(Craft::t('craft-delta', TranslationKeys::VERSION_NOT_FOUND));
        }

        // "Source" is whichever side is NOT the canonical entity.
        $olderIsCanonical = $older->id === $canonical->id;
        $newerIsCanonical = $newer->id === $canonical->id;
        $sourceRef = $olderIsCanonical ? $newerRef : $olderRef;
        $sourceEntry = $olderIsCanonical ? $newer : $older;

        // Review mode is available when one side is canonical AND the setting is on.
        $settings = $plugin->getSettings();
        $reviewMode = $settings->enableReviewMode
            && ($olderIsCanonical || $newerIsCanonical);

        try {
            if ($reviewMode) {
                // Review-mode atom-ids (and the added/removed colors derived
                // from them) MUST be canonical-relative so they match the fixed
                // compare(canonical, source) that MergeService::merge() re-runs
                // at apply time. Diffing chronologically here would invert
                // matrix added/removed whenever canonical is newer than the
                // source draft (e.g. after a partial apply leaves a leftover
                // queue), making every accepted block change fail validation as
                // "stale". Canonical is the fixed baseline, so this orientation
                // is also inherently stable across swapped selections.
                $result = $plugin->diff->compare($canonical, $sourceEntry);
            } else {
                // Plain diffing: force chronological order so colors stay stable
                // across swapped selections.
                [$older, $newer] = $this->sortChronologically($older, $newer);
                $result = $plugin->diff->compare($older, $newer);
            }

            $user = Craft::$app->getUser()->getIdentity();
            $workflow = null;
            $isReviewer = false;
            // The workflow toolbar attaches to a submitted draft. Only surface it
            // when the workflow feature is enabled and the source is a draft.
            if ($settings->enableWorkflow && $sourceEntry->getIsDraft() && $user) {
                $workflow = $plugin->workflow->getByDraftId((int)$sourceEntry->draftId);
                if ($workflow !== null) {
                    $isReviewer = $plugin->workflow->canReview($user, $workflow);
                }
            }

            $html = Craft::$app->getView()->renderTemplate(
                'craft-delta/_diff-slideout',
                [
                    'result' => $result,
                    'reviewMode' => $reviewMode,
                    'sourceRef' => $sourceRef,
                    'entryId' => $entryId,
                    'siteId' => $siteId ?? $canonical->siteId,
                    'canonicalUpdatedAt' => $canonical->dateUpdated?->format(\DateTimeInterface::ATOM),
                    'sourceUpdatedAt' => $sourceEntry->dateUpdated?->format(\DateTimeInterface::ATOM),
                    'workflow' => $workflow,
                    'isReviewer' => $isReviewer,
                ],
            );

            return $this->asJson([
                'success' => true,
                'html' => $html,
                'stats' => $result->getStats(),
            ]);
        } catch (\Throwable $e) {
            Craft::error("Diff comparison failed: {$e->getMessage()}", __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('craft-delta', TranslationKeys::FAILED_GENERATE_DIFF),
            ]);
        }
    }

    /**
     * Renders the full-page comparison view.
     */
    public function actionCompareFullPage(): Response
    {
        $this->requireCpRequest();

        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getRequiredParam('entryId');
        $siteId = $request->getParam('siteId') ? (int)$request->getParam('siteId') : null;

        $plugin = Delta::getInstance();
        $canonical = $plugin->revision->getCanonical($entryId, $siteId);
        if (!$canonical instanceof Entry) {
            throw new NotFoundHttpException(Craft::t('craft-delta', TranslationKeys::ENTRY_NOT_FOUND));
        }

        $this->requireEntryAccess($canonical);

        /** @var \zeixcom\craftdelta\models\Settings $settings */
        $settings = $plugin->getSettings();

        return $this->renderTemplate('craft-delta/compare', [
            'entryId' => $entryId,
            'entry' => $canonical,
            'isDraft' => false,
            'draftId' => null,
            'showUnchanged' => $settings->defaultShowUnchanged,
            'siteId' => $canonical->siteId,
        ]);
    }

    /**
     * Returns the revision list for the selector dropdowns.
     */
    public function actionRevisions(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();

        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getRequiredParam('entryId');
        $siteId = $request->getParam('siteId') ? (int)$request->getParam('siteId') : null;

        $plugin = Delta::getInstance();
        $canonical = $plugin->revision->getCanonical($entryId, $siteId);

        if (!$canonical instanceof Entry) {
            return $this->asJson(['revisions' => [], 'drafts' => [], 'hasCurrent' => false]);
        }

        $this->requireEntryAccess($canonical);

        $revisions = $plugin->revision->getRevisions($entryId, 20, $siteId);
        $drafts = $plugin->revision->getDrafts($entryId, $siteId);

        $revisionOptions = array_map(function($rev) {
            $behavior = $rev->getBehavior('revision');
            $creator = $behavior?->getCreator()?->friendlyName ?? Craft::t('craft-delta', TranslationKeys::UNKNOWN);

            return [
                'id' => $rev->id,
                'num' => $rev->revisionNum,
                'label' => Craft::t('craft-delta', TranslationKeys::REV_NUM_CREATOR, [
                    'num' => $rev->revisionNum,
                    'creator' => $creator,
                ]),
                'date' => $rev->dateCreated?->format('M j, Y g:ia') ?? '',
                'type' => 'revision',
            ];
        }, $revisions);

        $draftOptions = [];
        $user = Craft::$app->getUser()->getIdentity();
        $section = $canonical->getSection();
        $canViewPeerDrafts = $user && $section && $user->can("viewPeerEntryDrafts:{$section->uid}");

        foreach ($drafts as $draft) {
            /** @var \craft\behaviors\DraftBehavior|null $behavior */
            $behavior = $draft->getBehavior('draft');

            $creatorId = $behavior?->creatorId;
            if ($creatorId && (int)$creatorId !== (int)$user?->id && !$canViewPeerDrafts) {
                continue;
            }

            $draftName = $behavior?->draftName ?? Craft::t('craft-delta', TranslationKeys::DRAFT);
            $creator = $behavior?->getCreator()?->friendlyName ?? Craft::t('craft-delta', TranslationKeys::UNKNOWN);
            $draftOptions[] = [
                'id' => 'draft:' . $draft->draftId,
                'label' => $draftName . ' — ' . $creator,
                'date' => $draft->dateUpdated?->format('M j, Y g:ia') ?? '',
                'type' => 'draft',
            ];
        }

        return $this->asJson([
            'revisions' => $revisionOptions,
            'drafts' => $draftOptions,
            'hasCurrent' => $canonical !== null,
        ]);
    }

    /** @return array{0: Entry, 1: Entry} */
    private function sortChronologically(Entry $a, Entry $b): array
    {
        $aTime = $a->dateUpdated?->getTimestamp() ?? 0;
        $bTime = $b->dateUpdated?->getTimestamp() ?? 0;

        if ($aTime !== $bTime) {
            return $aTime < $bTime ? [$a, $b] : [$b, $a];
        }

        $aNum = $a->revisionNum ?? PHP_INT_MAX;
        $bNum = $b->revisionNum ?? PHP_INT_MAX;

        return $aNum <= $bNum ? [$a, $b] : [$b, $a];
    }

    /**
     * Resolve a version reference to an Entry instance.
     *
     * Supports:
     * - "current" → canonical entry
     * - "draft:<draftId>" → specific draft
     * - numeric string → revision ID
     */
    private function resolveVersion(string $ref, Entry $canonical, ?int $siteId = null): ?Entry
    {
        $plugin = Delta::getInstance();

        if ($ref === 'current') {
            return $canonical;
        }

        if (str_starts_with($ref, 'draft:')) {
            $draftId = (int)substr($ref, 6);
            $query = Entry::find()
                ->draftId($draftId)
                ->draftOf($canonical->id)
                ->status(null);

            if ($siteId !== null) {
                $query->siteId($siteId);
            }

            $draft = $query->one();

            if ($draft !== null) {
                $user = Craft::$app->getUser()->getIdentity();
                /** @var \craft\behaviors\DraftBehavior|null $draftBehavior */
                $draftBehavior = $draft->getBehavior('draft');
                $creatorId = $draftBehavior?->creatorId;
                if ($creatorId && (int)$creatorId !== (int)$user?->id) {
                    $section = $canonical->getSection();
                    if ($section && !$user?->can("viewPeerEntryDrafts:{$section->uid}")) {
                        return null;
                    }
                }
            }

            return $draft;
        }

        $revision = $plugin->revision->getRevision((int)$ref, $siteId);

        if ($revision !== null && $revision->getCanonicalId() !== $canonical->id) {
            return null;
        }

        return $revision;
    }

    /**
     * Apply accepted review-mode atoms to a new draft of the canonical entry.
     */
    public function actionApply(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getRequiredBodyParam('entryId');
        $sourceRef = (string)$request->getRequiredBodyParam('sourceRef');
        $siteId = $request->getBodyParam('siteId') ? (int)$request->getBodyParam('siteId') : null;
        $acceptedAtoms = $request->getBodyParam('acceptedAtoms');
        $deleteSourceDraft = (bool)$request->getBodyParam('deleteSourceDraft', false);

        if (!is_array($acceptedAtoms) || count($acceptedAtoms) === 0) {
            return $this->asJson([
                'success' => false,
                'errorCode' => 'no-changes',
                'error' => Craft::t('craft-delta', TranslationKeys::NO_CHANGES_TO_APPLY),
            ])->setStatusCode(422);
        }

        $plugin = Delta::getInstance();

        $canonical = $plugin->revision->getCanonical($entryId, $siteId);
        if (!$canonical instanceof Entry) {
            return $this->asJson([
                'success' => false,
                'errorCode' => 'source-not-found',
                'error' => Craft::t('craft-delta', TranslationKeys::ENTRY_NOT_FOUND),
            ])->setStatusCode(422);
        }

        $this->requireEntryAccess($canonical);

        // Permission: user must hold the dedicated review-mode apply permission.
        // Granted via Settings → Users → User group permissions. Which sections
        // they may reach is governed by Craft's native section permissions
        // (already enforced by requireEntryAccess() above).
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can(Delta::PERMISSION_APPLY)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NO_PERMISSION_APPLY_REVIEW));
        }

        $source = $this->resolveVersion($sourceRef, $canonical, $siteId);
        if (!$source instanceof Entry) {
            return $this->asJson([
                'success' => false,
                'errorCode' => 'source-not-found',
                'error' => Craft::t('craft-delta', TranslationKeys::SOURCE_VERSION_NOT_FOUND),
            ])->setStatusCode(422);
        }

        // Guard against the author editing the draft between diff-load and apply.
        // The accepted atoms describe the values the reviewer SAW, but merge()
        // copies the source's CURRENT values — so a change since load means we
        // would publish content nobody reviewed. Bail down the same stale path
        // the client already handles.
        $sourceUpdatedAt = $request->getBodyParam('sourceUpdatedAt');
        if ($sourceUpdatedAt !== null && $sourceUpdatedAt !== '') {
            $liveSourceUpdatedAt = $source->dateUpdated?->format(\DateTimeInterface::ATOM);
            if ($liveSourceUpdatedAt !== null && $liveSourceUpdatedAt !== (string)$sourceUpdatedAt) {
                return $this->staleAtomsResponse();
            }
        }

        // Native Craft gating: applying publishes a new canonical revision and
        // (optionally) hard-deletes the source draft. craftdelta-applyReview
        // alone is not enough — require the same save/delete permissions Craft's
        // own UI would, so a read-only user can neither publish nor destroy a
        // peer's draft.
        if (!$canonical->canSave($user)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NO_PERMISSION_PUBLISH));
        }

        $sourceDraftId = $source->getIsDraft() ? (int)$source->draftId : null;
        $deleteSourceDraft = $deleteSourceDraft && $sourceDraftId !== null;
        if ($deleteSourceDraft && !$source->canDelete($user)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NO_PERMISSION_DELETE_SOURCE_DRAFT));
        }

        // A granular apply of a SUBMITTED draft IS the review decision, so only
        // the assigned reviewer (or an admin) may perform it. This prevents a
        // non-reviewer publishing a curated merge while leaving the workflow
        // "pending" for a later wholesale double-apply.
        $workflow = $sourceDraftId !== null ? $plugin->workflow->getByDraftId($sourceDraftId) : null;
        if ($workflow !== null && $workflow->isPending() && !$plugin->workflow->canReview($user, $workflow)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::ONLY_ASSIGNED_REVIEWER_MAY_APPLY));
        }

        try {
            // The publish and the workflow resolution must commit together: if
            // resolveByReview() fails, the transaction rolls the publish back too,
            // so canonical is never left updated while the workflow stays
            // "pending" (which would be open to a later wholesale double-apply).
            $published = Craft::$app->getDb()->transaction(function() use ($plugin, $canonical, $source, $acceptedAtoms, $workflow, $user) {
                $published = $plugin->merge->merge($canonical, $source, $acceptedAtoms, false);

                if ($workflow !== null && $workflow->isPending()) {
                    // Reviewer rights were asserted above. Resolve BEFORE the
                    // source-draft delete below, or the FK ON DELETE CASCADE
                    // removes the workflow row before we can record the decision.
                    $plugin->workflow->resolveByReview($workflow, $user);
                }

                return $published;
            });

            // Best-effort cleanup AFTER the publish is committed: deleting the now
            // redundant source draft must never report the apply as failed (the
            // change is already live) or roll the publish back.
            if ($deleteSourceDraft) {
                try {
                    Craft::$app->getElements()->deleteElement($source, true);
                } catch (\Throwable $e) {
                    Craft::warning("Applied review but failed to delete source draft {$source->id}: {$e->getMessage()}", __METHOD__);
                }
            }

            return $this->asJson([
                'success' => true,
                'entryId' => $published->id,
                'entryEditUrl' => $published->getCpEditUrl(),
            ]);
        } catch (\zeixcom\craftdelta\services\StaleAtomException $e) {
            return $this->staleAtomsResponse();
        } catch (\InvalidArgumentException $e) {
            Craft::warning("Apply rejected malformed atom: {$e->getMessage()}", __METHOD__);
            return $this->staleAtomsResponse();
        } catch (\Throwable $e) {
            // Log the detail, but never leak internal exception text to the client.
            Craft::error("Apply failed: {$e->getMessage()}", __METHOD__);
            return $this->asJson([
                'success' => false,
                'errorCode' => 'validation-failed',
                'error' => Craft::t('craft-delta', TranslationKeys::COULD_NOT_APPLY_CHANGES),
            ])->setStatusCode(422);
        }
    }

    /**
     * The 422 response sent whenever the source changed under the reviewer (the
     * pre-check timestamp guard, a StaleAtomException, or a malformed atom). The
     * client's `handleApplyError` keys on errorCode 'stale-atoms' to clear the
     * saved decisions and reload, so all three paths must return exactly this.
     */
    private function staleAtomsResponse(): Response
    {
        return $this->asJson([
            'success' => false,
            'errorCode' => 'stale-atoms',
            'error' => Craft::t('craft-delta', TranslationKeys::ENTRY_CHANGED_SINCE_REVIEW_STARTED),
        ])->setStatusCode(422);
    }
}
