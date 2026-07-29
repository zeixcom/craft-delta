<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\console\controllers;

use Craft;
use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Smoke-test runner for craft-delta. Not part of the user-facing plugin —
 * a developer convenience for running end-to-end checks against a real
 * Craft install when PHPUnit kernel boot isn't wired up.
 *
 * Invoke via: `ddev craft craft-delta/smoke/matrix-apply`
 */
class SmokeController extends Controller
{
    private const SMOKE_DIR = __DIR__ . '/../../../tests/smoke';

    /** Reviewer override for `prepare-review` (email or username); see actionPrepareReview(). */
    public ?string $reviewer = null;

    public function options($actionID): array
    {
        return match ($actionID) {
            'prepare-review' => [...parent::options($actionID), 'reviewer'],
            default => parent::options($actionID),
        };
    }

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        if (!Craft::$app->getConfig()->general->devMode) {
            $this->stderr("Smoke commands are only available when devMode is enabled.\n");
            return false;
        }
        return true;
    }

    public function actionMatrixApply(): int
    {
        return $this->runScript('matrix-apply-smoke.php');
    }

    /**
     * Integration check for the review-feedback diff fixes: link changes inside
     * CKEditor content surface even when the visible text is unchanged (#4), and
     * an absent-vs-off lightswitch is not treated as a change (#9). Read-only.
     */
    public function actionDiffFixes(): int
    {
        return $this->runScript('diff-fixes-smoke.php');
    }

    /**
     * Regression check for #15: a Matrix block whose entry type shows the Title
     * field, where only that title changed, must diff, expose a
     * `matrix-field:…:title` atom, and apply.
     */
    public function actionNestedTitle(): int
    {
        return $this->runScript('nested-title-smoke.php');
    }

    /**
     * Leaves a draft behind that changes a Matrix block's Title *and* one of
     * its sub-fields, for eyeballing the #15 fix in the CP. Merges nothing.
     */
    public function actionNestedTitleDemo(): int
    {
        return $this->runScript('nested-title-demo.php');
    }

    /**
     * Runs the Matrix `added` + `removed` end-to-end smoke test. Seeds
     * canonical with 3 known blocks, then drafts a state where one is
     * removed and one new one is added, and verifies the apply.
     */
    public function actionMatrixAddRemove(): int
    {
        return $this->runScript('matrix-add-remove-smoke.php');
    }

    /**
     * Creates/reconfigures the two workflow fixture users (delta.author as
     * submitter, delta.reviewer as reviewer) with identical section access and
     * the general workflow permissions, ready for a manual submit/review/apply
     * walkthrough in the control panel.
     */
    public function actionSetupWorkflowUsers(): int
    {
        return $this->runScript('setup-workflow-users.php');
    }

    /**
     * Creates a submitted draft review fixture for the PR-style comments smoke
     * test. Prints draft URL + review id for the browser walkthrough.
     */
    public function actionReviewCommentsSetup(): int
    {
        return $this->runScript('review-comments-setup-smoke.php');
    }

    /**
     * Prepares a fresh review (draft with a few changed fields) assigned to a
     * real reviewer — the admin by default — so it lands in that reviewer's
     * "Assigned to me" queue for a hands-on walkthrough. Override the reviewer
     * with `--reviewer=<email|username>`.
     */
    public function actionPrepareReview(): int
    {
        return $this->runScript('prepare-review-smoke.php');
    }

    public function actionReviewCommentsVerify(): int
    {
        return $this->runScript('review-comments-verify-smoke.php');
    }

    /**
     * Runs the submit → approve → publish workflow end-to-end against a real
     * Craft kernel, verifying the simplified Approve/Decline flow applies an
     * approved draft to canonical. Requires the workflow fixture users
     * (run setup-workflow-users first).
     */
    public function actionWorkflowApprovePublish(): int
    {
        return $this->runScript('workflow-approve-publish-smoke.php');
    }

    /**
     * Creates two user groups (Delta Authors / Delta Reviewers) modelling
     * separation of duties — authors create + submit drafts but cannot publish;
     * reviewers review + publish — and assigns the fixture users to them.
     */
    public function actionSetupWorkflowGroups(): int
    {
        return $this->runScript('setup-workflow-groups.php');
    }

    /**
     * Hard-deletes leftover review draft entries (craftdelta_reviews.draftId)
     * through Craft so the before-delete listener cancels any still-open review;
     * tidies the demo without touching the review audit rows.
     */
    public function actionCleanupWorkflowDrafts(): int
    {
        return $this->runScript('cleanup-workflow-drafts.php');
    }

    private function runScript(string $name): int
    {
        $script = self::SMOKE_DIR . '/' . $name;
        if (!is_file($script)) {
            $this->stderr("Script not found: $script\n");
            return ExitCode::IOERR;
        }

        require $script;

        // Smoke scripts call exit() on failure; if we get here it passed.
        return ExitCode::OK;
    }
}
