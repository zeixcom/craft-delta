<?php

declare(strict_types=1);

/**
 * Workflow approve → publish smoke test.
 *
 * End-to-end runtime verification of the simplified Approve/Decline workflow
 * (after the "request changes" / re-request removal): an author submits a draft
 * to a reviewer, the reviewer approves, and publishing applies the draft to
 * canonical. Exercises WorkflowService::submit/approve/publish against a real
 * Craft kernel — the path the skipped PHPUnit integration tests can't cover.
 *
 * Requires the two fixture users (run setup-workflow-users first).
 *
 * Invoke via: `ddev craft craft-delta/smoke/workflow-approve-publish`
 */

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\models\Review;

require __DIR__ . '/_guard.php';

function out(string $message): void
{
    echo $message . PHP_EOL;
}

function bail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

echo "── Workflow approve → publish smoke test ──\n";

$author = User::find()->username('delta.author')->status(null)->one();
$reviewer = User::find()->username('delta.reviewer')->status(null)->one();
if (!$author instanceof User || !$reviewer instanceof User) {
    bail('delta.author / delta.reviewer missing — run craft-delta/smoke/setup-workflow-users first.');
}
out("Author: {$author->username} (id={$author->id})  Reviewer: {$reviewer->username} (id={$reviewer->id})");

$plugin = Delta::getInstance();

// Pick a canonical deltaTest entry to work against (override with env).
$entryQuery = Entry::find()->section('deltaTest')->status(null)->drafts(false)->revisions(false);
$entryId = (int)(getenv('CRAFT_DELTA_SMOKE_ENTRY_ID') ?: 0);
$canonical = $entryId > 0 ? (clone $entryQuery)->id($entryId)->one() : $entryQuery->one();
if (!$canonical instanceof Entry) {
    bail('No canonical deltaTest entry found.');
}
out("Canonical: \"{$canonical->title}\" (id={$canonical->id})");

Craft::$app->getUser()->setIdentity($author);
$draft = Craft::$app->getDrafts()->createDraft($canonical, $author->id, 'Approve/publish smoke');
if (!$draft instanceof Entry) {
    bail('createDraft did not return an Entry.');
}
$newTitle = $canonical->title . ' [APPROVED ' . date('His') . ']';
$draft->title = $newTitle;
if (!Craft::$app->getElements()->saveElement($draft)) {
    bail('Could not save draft: ' . json_encode($draft->getErrors(), JSON_UNESCAPED_SLASHES));
}
out("Step 1: draft id={$draft->id} draftId={$draft->draftId}, title → \"{$newTitle}\"");

$review = $plugin->workflow->submit($draft, [(int)$reviewer->id], $author);
if ($review->state !== Review::STATE_OPEN) {
    bail("Expected OPEN after submit, got {$review->state}.");
}
if (!$review->isActive() || $review->round !== 1) {
    bail("Submitted review should be active, round 1 (got round {$review->round}).");
}
out("Step 2: submitted — review id={$review->id}, state={$review->state}, round={$review->round}");

Craft::$app->getUser()->setIdentity($reviewer);
$review = $plugin->workflow->approve($review, $reviewer);
if (!$review->isApproved()) {
    bail("Expected APPROVED after approve, got {$review->state}.");
}
out("Step 3: approved — state={$review->state}");

$review = $plugin->workflow->publish($review, null, $reviewer);
if ($review->state !== Review::STATE_PUBLISHED || $review->appliedAt === null) {
    bail("Expected PUBLISHED with appliedAt set, got state={$review->state}, appliedAt=" . var_export($review->appliedAt, true));
}
if (!$review->isTerminal()) {
    bail('Published review should be terminal.');
}
out("Step 4: published — state={$review->state}, appliedAt set");

$canonicalAfter = Entry::find()->id($canonical->id)->status(null)->one();
if (!$canonicalAfter instanceof Entry) {
    bail('Could not reload canonical after publish.');
}
if ($canonicalAfter->title !== $newTitle) {
    bail("Canonical title \"{$canonicalAfter->title}\" != approved title \"{$newTitle}\".");
}
out("Step 5: canonical title updated to \"{$canonicalAfter->title}\"");

out("\n══ ALL CHECKS PASSED — submit → approve → publish works end-to-end ══");
exit(0);
