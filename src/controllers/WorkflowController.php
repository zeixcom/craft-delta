<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use DateTime;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use zeixcom\craftdelta\Delta;

/**
 * HTTP endpoints for the submit-for-review workflow. Each action does
 * permission check + delegates to WorkflowService.
 */
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

        $draft = Craft::$app->getEntries()->getEntryById($draftId, '*', ['drafts' => true]);
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
        $mode = $request->getBodyParam('mode', 'wholesale');

        $plugin = Delta::getInstance();
        $wf = $plugin->workflow->getByDraftIdOrId($workflowId);
        if ($wf === null) {
            throw new NotFoundHttpException('Workflow not found.');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$plugin->workflow->canReview($user, $wf)) {
            throw new ForbiddenHttpException('You are not the assigned reviewer for this draft.');
        }

        if ($mode === 'granular') {
            $accepted = $request->getBodyParam('acceptedFieldHandles', []);
            if (!is_array($accepted)) {
                throw new BadRequestHttpException('acceptedFieldHandles must be an array.');
            }
            if (!$user->can("craftdelta-applyReview:{$wf->sectionUid}")) {
                throw new ForbiddenHttpException('Granular review requires the Apply permission.');
            }
            $plugin->workflow->approveGranular($wf, $accepted, $user);
        } else {
            $scheduledFor = $scheduledForRaw ? new DateTime($scheduledForRaw) : null;
            $plugin->workflow->approveWholesale($wf, $scheduledFor, $user);
        }

        return $this->asJson(['success' => true]);
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
        if (!$user || !$user->can("craftdelta-submitDraft:{$sectionUid}")) {
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
