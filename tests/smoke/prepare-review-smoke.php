<?php

declare(strict_types=1);

/**
 * Dev convenience: prepare a fresh review assigned to a real reviewer — the
 * admin (fabian.haefliger@zeix.com) by default — so it lands in that
 * reviewer's "Assigned to me" queue for a hands-on walkthrough. Creates a
 * draft off the latest deltaTest canonical with a few changed fields
 * (multiple atoms), then submits it for review.
 *
 * Invoke via: `ddev craft craft-delta/smoke/prepare-review`
 *             `ddev craft craft-delta/smoke/prepare-review --reviewer=delta.reviewer`
 */

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use zeixcom\craftdelta\Delta;

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

const SECTION_HANDLE = 'deltaTest';
const AUTHOR_USERNAME = 'delta.author';
const DEFAULT_REVIEWER_EMAIL = 'fabian.haefliger@zeix.com';
const MARKER = 'AX-REVIEW-';

$author = User::find()->username(AUTHOR_USERNAME)->one();
if (!$author instanceof User) {
    bail('Run craft-delta/smoke/setup-workflow-users first (missing ' . AUTHOR_USERNAME . ').');
}

// $this is the SmokeController instance (this script is require()'d from
// inside actionPrepareReview()), so --reviewer=<email|username> lands on
// $this->reviewer via options().
$reviewerRef = $this->reviewer ?? DEFAULT_REVIEWER_EMAIL;
$reviewer = User::find()->email($reviewerRef)->one() ?? User::find()->username($reviewerRef)->one();
if (!$reviewer instanceof User) {
    bail('Reviewer not found (checked email + username): ' . $reviewerRef);
}

$section = Craft::$app->getEntries()->getSectionByHandle(SECTION_HANDLE);
if ($section === null) {
    bail('Section not found: ' . SECTION_HANDLE);
}

$plugin = Delta::getInstance();

// Guard with the same eligibility rule submit() enforces, so a bad reviewer
// gives a clear message instead of a generic "not eligible" from submit().
$eligible = array_map(static fn(User $u) => (int)$u->id, $plugin->workflow->getEligibleAssignees($section->uid, $author->id));
if (!in_array((int)$reviewer->id, $eligible, true)) {
    bail(sprintf(
        '%s (id %d) is not an eligible reviewer for %s. Eligible ids: %s',
        $reviewerRef,
        $reviewer->id,
        SECTION_HANDLE,
        $eligible === [] ? '(none)' : implode(', ', $eligible),
    ));
}

Craft::$app->getUser()->setIdentity($author);

$canonical = Entry::find()
    ->sectionId($section->id)
    ->drafts(false)
    ->status(null)
    ->orderBy(['id' => SORT_DESC])
    ->one();
if (!$canonical instanceof Entry) {
    bail('No canonical entry in ' . SECTION_HANDLE);
}

$draft = Craft::$app->getDrafts()->createDraft($canonical, $author->id, 'AX review walkthrough');
if (!$draft instanceof Entry) {
    bail('createDraft failed');
}

// Timestamped values guarantee a diff (multiple atoms to decide).
$draft->title = MARKER . date('Y-m-d H:i:s');
$draft->setFieldValue('deltaTestPlainText', 'AX walkthrough plain text ' . date('H:i:s'));
$draft->setFieldValue('deltaTestEmail', 'ax-' . date('His') . '@example.com');
if (!Craft::$app->getElements()->saveElement($draft)) {
    bail('Failed to save draft: ' . json_encode($draft->getErrors()));
}

$review = $plugin->workflow->submit($draft, [$reviewer->id], $author);

$base = rtrim(Craft::$app->getConfig()->general->cpTrigger ?? 'admin', '/');
out('── Review prepared ──');
out('Review id: ' . $review->id);
out('Assigned reviewer: ' . ($reviewer->email ?: $reviewer->username) . ' (id ' . $reviewer->id . ')');
out('Draft title: ' . $draft->title);
out('Review page: /' . $base . '/delta-review?reviewId=' . $review->id);
out('Your queue:  /' . $base . '/delta-reviews?bucket=assigned');
