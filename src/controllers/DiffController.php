<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\controllers;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\helpers\EntryMeta;
use zeixcom\craftdelta\i18n\TranslationKeys;
use zeixcom\craftdelta\Permissions;
use zeixcom\craftdelta\services\StaleAtomException;

/**
 * @phpstan-import-type EntryPair from \zeixcom\craftdelta\types\ArrayTypes
 */
class DiffController extends Controller
{
    private function requireEntryAccess(Entry $entry): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        $section = $entry->getSection();
        if (!$user || $section === null || !$user->can("viewEntries:{$section->uid}")) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NOT_AUTHORIZED));
        }
    }

    /** Accepts "current", "draft:<draftId>", or a numeric revision ID for both sides. */
    public function actionCompare(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getRequiredBodyParam('entryId');
        $olderRef = $request->getRequiredBodyParam('older');
        $newerRef = $request->getRequiredBodyParam('newer');
        $siteId = $this->siteIdParam($request->getBodyParam('siteId'));

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

        $olderIsCanonical = $older->id === $canonical->id;
        $sourceRef = $olderIsCanonical ? $newerRef : $olderRef;
        $sourceEntry = $olderIsCanonical ? $newer : $older;

        $settings = $plugin->getSettings();
        $reviewMode = $settings->enableReviewMode && ($olderIsCanonical || $newer->id === $canonical->id);

        try {
            if ($reviewMode) {
                // Review-mode atom-ids MUST be canonical-relative so they match
                // compare(canonical, source) that MergeService::merge() re-runs
                // at apply time. Chronological diffing would invert matrix
                // added/removed when canonical is newer than the source draft.
                $result = $plugin->diff->compare($canonical, $sourceEntry);
            } else {
                // Plain diffing: force chronological order so colors stay stable
                // across swapped selections.
                $result = $plugin->diff->compare(...$this->sortChronologically($older, $newer));
            }

            $user = Craft::$app->getUser()->getIdentity();
            $workflow = null;
            $isReviewer = false;
            if ($settings->enableWorkflow && $sourceEntry->getIsDraft() && $user) {
                $workflow = $plugin->workflow->getByDraftId((int)$sourceEntry->draftId);
                $isReviewer = $workflow !== null && $plugin->workflow->canReview($user, $workflow);
            }

            return $this->asJson([
                'success' => true,
                'html' => Craft::$app->getView()->renderTemplate('craft-delta/_diff-slideout', [
                    'result' => $result,
                    'reviewMode' => $reviewMode,
                    'sourceRef' => $sourceRef,
                    'entryId' => $entryId,
                    'siteId' => $siteId ?? $canonical->siteId,
                    'canonicalUpdatedAt' => $canonical->dateUpdated?->format(\DateTimeInterface::ATOM),
                    'sourceUpdatedAt' => $sourceEntry->dateUpdated?->format(\DateTimeInterface::ATOM),
                    'workflow' => $workflow,
                    'isReviewer' => $isReviewer,
                ]),
                'stats' => $result->getStats(),
            ]);
        } catch (\Throwable $e) {
            Craft::error("Diff comparison failed: {$e}", __METHOD__);
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('craft-delta', TranslationKeys::FAILED_GENERATE_DIFF),
            ]);
        }
    }

    public function actionCompareFullPage(): Response
    {
        $this->requireCpRequest();

        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getRequiredParam('entryId');
        $siteId = $this->siteIdParam($request->getParam('siteId'));

        $plugin = Delta::getInstance();
        $canonical = $plugin->revision->getCanonical($entryId, $siteId);
        if (!$canonical instanceof Entry) {
            throw new NotFoundHttpException(Craft::t('craft-delta', TranslationKeys::ENTRY_NOT_FOUND));
        }

        $this->requireEntryAccess($canonical);
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

    public function actionRevisions(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();

        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getRequiredParam('entryId');
        $siteId = $this->siteIdParam($request->getParam('siteId'));

        $plugin = Delta::getInstance();
        $canonical = $plugin->revision->getCanonical($entryId, $siteId);
        if (!$canonical instanceof Entry) {
            return $this->asJson(['revisions' => [], 'drafts' => [], 'hasCurrent' => false]);
        }

        $this->requireEntryAccess($canonical);
        $user = Craft::$app->getUser()->getIdentity();

        return $this->asJson([
            'revisions' => array_map(fn(Entry $rev) => $this->revisionOption($rev), $plugin->revision->getRevisions($entryId, 20, $siteId)),
            'drafts' => array_values(array_filter(
                array_map(fn(Entry $draft) => $this->userCanViewDraft($draft, $canonical, $user) ? $this->draftOption($draft) : null, $plugin->revision->getDrafts($entryId, $siteId)),
            )),
            'hasCurrent' => true,
        ]);
    }

    public function actionApply(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $acceptedAtoms = $request->getBodyParam('acceptedAtoms');
        if (!is_array($acceptedAtoms) || $acceptedAtoms === []) {
            return $this->apply422('no-changes', TranslationKeys::NO_CHANGES_TO_APPLY);
        }

        $entryId = (int)$request->getRequiredBodyParam('entryId');
        $sourceRef = (string)$request->getRequiredBodyParam('sourceRef');
        $siteId = $this->siteIdParam($request->getBodyParam('siteId'));
        $deleteSourceDraft = (bool)$request->getBodyParam('deleteSourceDraft', false);

        $plugin = Delta::getInstance();
        $canonical = $plugin->revision->getCanonical($entryId, $siteId);
        if (!$canonical instanceof Entry) {
            return $this->apply422('source-not-found', TranslationKeys::ENTRY_NOT_FOUND);
        }

        $this->requireEntryAccess($canonical);

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can(Permissions::APPLY)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NO_PERMISSION_APPLY_REVIEW));
        }

        $source = $this->resolveVersion($sourceRef, $canonical, $siteId);
        if (!$source instanceof Entry) {
            return $this->apply422('source-not-found', TranslationKeys::SOURCE_VERSION_NOT_FOUND);
        }

        $sourceUpdatedAt = $request->getBodyParam('sourceUpdatedAt');
        if (is_string($sourceUpdatedAt) && $sourceUpdatedAt !== '') {
            $live = $source->dateUpdated?->format(\DateTimeInterface::ATOM);
            if ($live !== null && $live !== $sourceUpdatedAt) {
                return $this->staleAtomsResponse();
            }
        }

        if (!$canonical->canSave($user)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NO_PERMISSION_PUBLISH));
        }

        $sourceDraftId = $source->getIsDraft() ? (int)$source->draftId : null;
        $deleteSourceDraft = $deleteSourceDraft && $sourceDraftId !== null;
        if ($deleteSourceDraft && !$source->canDelete($user)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::NO_PERMISSION_DELETE_SOURCE_DRAFT));
        }

        $workflow = $sourceDraftId !== null ? $plugin->workflow->getByDraftId($sourceDraftId) : null;
        if ($workflow !== null && $workflow->isActive() && !$plugin->workflow->canReview($user, $workflow)) {
            throw new ForbiddenHttpException(Craft::t('craft-delta', TranslationKeys::ONLY_ASSIGNED_REVIEWER_MAY_APPLY));
        }

        try {
            // Publish and workflow resolution must commit together; otherwise
            // canonical updates while the workflow stays "pending".
            $published = Craft::$app->getDb()->transaction(function() use ($plugin, $canonical, $source, $acceptedAtoms, $workflow, $user) {
                $published = $plugin->merge->merge($canonical, $source, $acceptedAtoms, false);
                if ($workflow !== null && $workflow->isActive()) {
                    // Resolve BEFORE source-draft delete, or the FK CASCADE
                    // removes the workflow row before we record the decision.
                    $plugin->workflow->resolveByReview($workflow, $user);
                }
                return $published;
            });

            // Best-effort cleanup AFTER publish is committed.
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
        } catch (StaleAtomException) {
            return $this->staleAtomsResponse();
        } catch (\InvalidArgumentException $e) {
            Craft::warning("Apply rejected malformed atom: {$e->getMessage()}", __METHOD__);
            return $this->staleAtomsResponse();
        } catch (\RuntimeException $e) {
            // Merge or publish failed (e.g. draft validation) — a real server-side
            // fault, not user noise. Log the full exception; the client still 422s.
            Craft::error("Apply failed (merge/publish): {$e}", __METHOD__);
            return $this->apply422('validation-failed', TranslationKeys::COULD_NOT_APPLY_CHANGES);
        } catch (\Throwable $e) {
            Craft::error("Apply failed (unexpected): {$e}", __METHOD__);
            return $this->apply422('validation-failed', TranslationKeys::COULD_NOT_APPLY_CHANGES);
        }
    }

    private function siteIdParam(mixed $raw): ?int
    {
        return $raw ? (int)$raw : null;
    }

    private function apply422(string $errorCode, string $messageKey): Response
    {
        return $this->asJson([
            'success' => false,
            'errorCode' => $errorCode,
            'error' => Craft::t('craft-delta', $messageKey),
        ])->setStatusCode(422);
    }

    /** @return array{id: ?int, num: int, label: string, date: string, type: 'revision'} */
    private function revisionOption(Entry $rev): array
    {
        $behavior = EntryMeta::revision($rev);
        $creator = $behavior?->getCreator()?->friendlyName ?? Craft::t('craft-delta', TranslationKeys::UNKNOWN);
        $revisionNum = $behavior?->revisionNum ?? 0;
        return [
            'id' => $rev->id,
            'num' => $revisionNum,
            'label' => Craft::t('craft-delta', TranslationKeys::REV_NUM_CREATOR, ['num' => $revisionNum, 'creator' => $creator]),
            'date' => $rev->dateCreated?->format('M j, Y g:ia') ?? '',
            'type' => 'revision',
        ];
    }

    /** @return array{id: string, label: string, date: string, type: 'draft'} */
    private function draftOption(Entry $draft): array
    {
        $behavior = EntryMeta::draft($draft);
        return [
            'id' => 'draft:' . $draft->draftId,
            'label' => ($behavior?->draftName ?? Craft::t('craft-delta', TranslationKeys::DRAFT))
                . ' — ' . ($behavior?->getCreator()?->friendlyName ?? Craft::t('craft-delta', TranslationKeys::UNKNOWN)),
            'date' => $draft->dateUpdated?->format('M j, Y g:ia') ?? '',
            'type' => 'draft',
        ];
    }

    private function userCanViewDraft(Entry $draft, Entry $canonical, ?User $user): bool
    {
        $behavior = EntryMeta::draft($draft);
        $creatorId = $behavior?->creatorId;
        if ($creatorId && (int)$creatorId !== (int)$user?->id) {
            $section = $canonical->getSection();
            return !($section && !$user?->can("viewPeerEntryDrafts:{$section->uid}"));
        }
        return true;
    }

    /** @return EntryPair */
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

    private function resolveVersion(string $ref, Entry $canonical, ?int $siteId = null): ?Entry
    {
        if ($ref === 'current') {
            return $canonical;
        }

        $plugin = Delta::getInstance();
        if (str_starts_with($ref, 'draft:')) {
            $draft = $plugin->revision->getDraftByDraftId((int)substr($ref, 6), $canonical->id, $siteId);
            return ($draft !== null && $this->userCanViewDraft($draft, $canonical, Craft::$app->getUser()->getIdentity())) ? $draft : null;
        }

        $revision = $plugin->revision->getRevision((int)$ref, $siteId);
        return ($revision !== null && $revision->getCanonicalId() === $canonical->id) ? $revision : null;
    }

    /**
     * The 422 response sent whenever the source changed under the reviewer (the
     * pre-check timestamp guard, a StaleAtomException, or a malformed atom). The
     * client's `handleApplyError` keys on errorCode 'stale-atoms' to clear the
     * saved decisions and reload, so all three paths must return exactly this.
     */
    private function staleAtomsResponse(): Response
    {
        return $this->apply422('stale-atoms', TranslationKeys::ENTRY_CHANGED_SINCE_REVIEW_STARTED);
    }
}
