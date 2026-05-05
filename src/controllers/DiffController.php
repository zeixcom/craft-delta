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
            throw new ForbiddenHttpException('Not authorized.');
        }

        $section = $entry->getSection();
        if ($section === null) {
            throw new ForbiddenHttpException('Not authorized.');
        }

        if (!$user->can("viewEntries:{$section->uid}")) {
            throw new ForbiddenHttpException('Not authorized.');
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
            return $this->asFailure('Entry not found.');
        }

        $this->requireEntryAccess($canonical);

        $older = $this->resolveVersion($olderRef, $canonical, $siteId);
        $newer = $this->resolveVersion($newerRef, $canonical, $siteId);

        if (!$older || !$newer) {
            return $this->asFailure('Version not found.');
        }

        // Force chronological order so diff colors stay stable across swaps.
        [$older, $newer] = $this->sortChronologically($older, $newer);

        try {
            $result = $plugin->diff->compare($older, $newer);

            $html = Craft::$app->getView()->renderTemplate(
                'craft-delta/_diff-slideout',
                ['result' => $result],
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
                'error' => Craft::t('craft-delta', 'Failed to generate diff.'),
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
            throw new NotFoundHttpException('Entry not found.');
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
            $creator = $behavior?->getCreator()?->friendlyName ?? Craft::t('craft-delta', 'Unknown');

            return [
                'id' => $rev->id,
                'num' => $rev->revisionNum,
                'label' => Craft::t('craft-delta', 'Rev {num} — {creator}', [
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

            $draftName = $behavior?->draftName ?? Craft::t('craft-delta', 'Draft');
            $creator = $behavior?->getCreator()?->friendlyName ?? Craft::t('craft-delta', 'Unknown');
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

        if (!is_array($acceptedAtoms) || count($acceptedAtoms) === 0) {
            return $this->asJson([
                'success' => false,
                'errorCode' => 'no-changes',
                'error' => Craft::t('craft-delta', 'No changes to apply.'),
            ])->setStatusCode(422);
        }

        $plugin = Delta::getInstance();

        $canonical = $plugin->revision->getCanonical($entryId, $siteId);
        if (!$canonical instanceof Entry) {
            return $this->asJson([
                'success' => false,
                'errorCode' => 'source-not-found',
                'error' => Craft::t('craft-delta', 'Entry not found.'),
            ])->setStatusCode(422);
        }

        $this->requireEntryAccess($canonical);

        // Permission: user must be able to create drafts on this section.
        $user = Craft::$app->getUser()->getIdentity();
        $section = $canonical->getSection();
        if (!$user || !$section || !$user->can("createEntryDrafts:{$section->uid}")) {
            throw new ForbiddenHttpException('Insufficient permissions to create a draft on this section.');
        }

        $source = $this->resolveVersion($sourceRef, $canonical, $siteId);
        if (!$source instanceof Entry) {
            return $this->asJson([
                'success' => false,
                'errorCode' => 'source-not-found',
                'error' => Craft::t('craft-delta', 'Source version not found.'),
            ])->setStatusCode(422);
        }

        try {
            $draft = $plugin->merge->merge($canonical, $source, $acceptedAtoms);

            return $this->asJson([
                'success' => true,
                'draftId' => $draft->draftId,
                'draftEditUrl' => $draft->getCpEditUrl(),
            ]);
        } catch (\zeixcom\craftdelta\services\StaleAtomException $e) {
            return $this->asJson([
                'success' => false,
                'errorCode' => 'stale-atoms',
                'error' => Craft::t('craft-delta', 'The entry has changed since you started reviewing. Please reload the diff and restart your review.'),
            ])->setStatusCode(422);
        } catch (\InvalidArgumentException $e) {
            Craft::warning("Apply rejected malformed atom: {$e->getMessage()}", __METHOD__);
            return $this->asJson([
                'success' => false,
                'errorCode' => 'stale-atoms',
                'error' => Craft::t('craft-delta', 'The entry has changed since you started reviewing. Please reload the diff and restart your review.'),
            ])->setStatusCode(422);
        } catch (\Throwable $e) {
            Craft::error("Apply failed: {$e->getMessage()}", __METHOD__);
            return $this->asJson([
                'success' => false,
                'errorCode' => 'validation-failed',
                'error' => $e->getMessage(),
            ])->setStatusCode(422);
        }
    }
}
