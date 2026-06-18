<?php

declare(strict_types=1);

/**
 * Verifies (and optionally seeds) review comments for the smoke fixture.
 *
 * Invoke via: `ddev craft craft-delta/smoke/review-comments-verify`
 */

use Craft;
use craft\elements\User;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\models\ReviewComment;

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

$reviewId = (int)(getenv('CRAFT_DELTA_SMOKE_REVIEW_ID') ?: 5);
$reviewer = User::find()->username('delta.reviewer')->one();
if (!$reviewer) {
    bail('delta.reviewer missing — run setup-workflow-users first.');
}

$plugin = Delta::getInstance();
$review = $plugin->workflow->getById($reviewId);
if ($review === null) {
    bail("Review {$reviewId} not found.");
}

$comments = $plugin->reviewComment->commentsForReview($reviewId, null);
$hasAtom = false;
$hasGeneral = false;
foreach ($comments as $c) {
    if ($c->body === 'SMOKE-ATOM-COMMENT') {
        $hasAtom = true;
    }
    if ($c->body === 'SMOKE-GENERAL-COMMENT' && $c->anchorType === ReviewComment::ANCHOR_GENERAL) {
        $hasGeneral = true;
    }
}

if (!$hasGeneral) {
    $plugin->reviewComment->addComment($review, $reviewer, 'SMOKE-GENERAL-COMMENT');
    out('Seeded general comment via ReviewCommentService.');
} else {
    out('General comment already present.');
}

$comments = $plugin->reviewComment->commentsForReview($reviewId, null);
$hasAtom = false;
$hasGeneral = false;
foreach ($comments as $c) {
    if ($c->body === 'SMOKE-ATOM-COMMENT') {
        $hasAtom = true;
    }
    if ($c->body === 'SMOKE-GENERAL-COMMENT') {
        $hasGeneral = true;
    }
}

if (!$hasAtom || !$hasGeneral) {
    bail('Expected atom + general comments for review ' . $reviewId);
}

out('PASS: review ' . $reviewId . ' has atom + general smoke comments.');
