<?php

declare(strict_types=1);

/**
 * Fixture setup for the PR-style review comments smoke test.
 *
 * Creates a published draft with a title change, submits it for review, and
 * prints the URLs/IDs the browser test needs.
 *
 * Invoke via: `ddev craft craft-delta/smoke/review-comments-setup`
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
const REVIEWER_USERNAME = 'delta.reviewer';
const MARKER = 'SMOKE-COMMENT-';

$author = User::find()->username(AUTHOR_USERNAME)->one();
$reviewer = User::find()->username(REVIEWER_USERNAME)->one();
if (!$author instanceof User || !$reviewer instanceof User) {
    bail('Run craft-delta/smoke/setup-workflow-users first.');
}

$section = Craft::$app->getEntries()->getSectionByHandle(SECTION_HANDLE);
if ($section === null) {
    bail('Section not found: ' . SECTION_HANDLE);
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

$draft = Craft::$app->getDrafts()->createDraft($canonical, $author->id, 'Review comments smoke');
if (!$draft instanceof Entry) {
    bail('createDraft failed');
}

$draft->title = MARKER . date('Y-m-d H:i:s');
if (!Craft::$app->getElements()->saveElement($draft)) {
    bail('Failed to save draft: ' . json_encode($draft->getErrors()));
}

$plugin = Delta::getInstance();
$review = $plugin->workflow->submit($draft, [$reviewer->id], $author);

$draftUrl = $draft->getCpEditUrl();
if ($draftUrl === null) {
    bail('Draft has no CP edit URL');
}

out('── Review comments smoke setup ──');
out('Canonical entry id: ' . $canonical->id);
out('Draft entry id: ' . $draft->id);
out('Draft table id: ' . $draft->draftId);
out('Review id: ' . $review->id);
out('Draft title: ' . $draft->title);
out('Draft CP URL: ' . $draftUrl . '#delta-compare');
out('Reviewer login: ' . REVIEWER_USERNAME);
out('Author login: ' . AUTHOR_USERNAME);
out('Password: DeltaTest!2026');
out('');
out('Atom comment marker: SMOKE-ATOM-COMMENT');
out('General comment marker: SMOKE-GENERAL-COMMENT');
