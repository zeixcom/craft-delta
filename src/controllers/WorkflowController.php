<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use DateTime;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use zeixcom\craftdelta\Delta;

class WorkflowController extends Controller
{
    public function actionSubmit(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $draftId = (int)$request->getRequiredBodyParam('draftId');
        $assigneeId = (int)$request->getRequiredBodyParam('assigneeId');

        // `draftId` is the drafts-table id (matching $entry->draftId everywhere
        // else in this plugin), not an element id — resolve it the same way the
        // service does, not via getEntryById() which expects an element id.
        $draft = Entry::find()->draftId($draftId)->status(null)->one();
        if (!$draft instanceof Entry || !$draft->getIsDraft()) {
            throw new NotFoundHttpException('Draft not found.');
        }

        $user = Craft::$app->getUser()->getIdentity();
        $plugin = Delta::getInstance();

        if (!$user || !$plugin->workflow->canSubmit($user, $draft)) {
            throw new ForbiddenHttpException('You do not have permission to submit drafts for this section.');
        }

        $wf = $plugin->workflow->submit($draft, $assigneeId, $user);

        return $this->asJson([
            'success' => true,
            'workflow' => [
                'id' => $wf->id,
                'state' => $wf->state,
                'assigneeId' => $wf->assigneeId,
            ],
        ]);
    }

    public function actionApprove(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $workflowId = (int)$request->getRequiredBodyParam('workflowId');
        $scheduledForRaw = $request->getBodyParam('scheduledFor');

        $plugin = Delta::getInstance();
        $wf = $plugin->workflow->getByDraftIdOrId($workflowId);
        if ($wf === null) {
            throw new NotFoundHttpException('Workflow not found.');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$plugin->workflow->canReview($user, $wf)) {
            throw new ForbiddenHttpException('You are not the assigned reviewer for this draft.');
        }

        // Approve publishes the draft wholesale (optionally scheduled). Granular
        // (partial) approval is handled entirely by Review Mode — see
        // DiffController::actionApply, which publishes the accepted atoms and
        // then closes this workflow via WorkflowService::resolveByReview().
        if ($scheduledForRaw !== null && $scheduledForRaw !== '') {
            try {
                $scheduledFor = new DateTime((string)$scheduledForRaw);
            } catch (\Throwable) {
                return $this->asJson([
                    'success' => false,
                    'error' => Craft::t('craft-delta', 'Schedule failed.'),
                ])->setStatusCode(422);
            }
        } else {
            $scheduledFor = null;
        }
        $plugin->workflow->approveWholesale($wf, $scheduledFor, $user);

        $canonical = Craft::$app->getEntries()->getEntryById($wf->canonicalEntryId);
        $redirectUrl = $canonical?->getCpEditUrl() ?? UrlHelper::cpUrl("entries/{$wf->canonicalEntryId}");

        return $this->asJson([
            'success' => true,
            'redirectUrl' => $redirectUrl,
        ]);
    }

    public function actionReject(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $workflowId = (int)$request->getRequiredBodyParam('workflowId');
        $note = $request->getBodyParam('note');

        $plugin = Delta::getInstance();
        $wf = $plugin->workflow->getByDraftIdOrId($workflowId);
        if ($wf === null) {
            throw new NotFoundHttpException('Workflow not found.');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$plugin->workflow->canReview($user, $wf)) {
            throw new ForbiddenHttpException('You are not the assigned reviewer for this draft.');
        }

        $plugin->workflow->reject($wf, $note, $user);

        return $this->asJson(['success' => true]);
    }

    public function actionAssignees(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();

        $request = Craft::$app->getRequest();
        $sectionUid = $request->getRequiredParam('sectionUid');

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can('craftdelta-submitDraft')) {
            throw new ForbiddenHttpException('Not authorized.');
        }

        $assignees = Delta::getInstance()->workflow->getEligibleAssignees($sectionUid, $user->id);

        return $this->asJson([
            'success' => true,
            'assignees' => array_map(fn($u) => [
                'id' => $u->id,
                'name' => $u->fullName ?: $u->username,
            ], $assignees),
        ]);
    }
}
