<?php
declare(strict_types=1);

use craft\elements\Entry;
use craft\elements\User;
use zeixcom\craftdelta\Delta;

function tdfail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function tdok(string $message): void
{
    echo "✓ {$message}\n";
}

function makeDraft(Entry $canonical, User $author, string $label): Entry
{
    Craft::$app->getUser()->setIdentity($author);
    $draft = Craft::$app->getDrafts()->createDraft($canonical, (int)$author->id, $label);
    $suffix = ' | ' . $label . ' ' . date('His');
    $base = substr((string)($canonical->title ?? 'Delta'), 0, max(1, 255 - strlen($suffix)));
    $draft->title = $base . $suffix;
    if (!Craft::$app->getElements()->saveElement($draft)) {
        tdfail('Draft save failed: ' . json_encode($draft->getErrors(), JSON_UNESCAPED_SLASHES));
    }
    $loaded = Entry::find()->draftId($draft->draftId)->status(null)->one();
    if (!$loaded instanceof Entry) {
        tdfail('Could not reload created draft.');
    }
    return $loaded;
}

$plugin = Delta::getInstance();
if ($plugin === null) {
    tdfail('Delta plugin unavailable.');
}

$author = User::find()->username('delta.author')->status(null)->one();
$reviewer = User::find()->username('delta.reviewer')->status(null)->one();
$outsider = User::find()->username('delta.outsider')->status(null)->one();
if (!$author || !$reviewer || !$outsider) {
    tdfail('Missing smoke users.');
}

Craft::$app->getUser()->setIdentity($author);
$canonical = Entry::find()->section('deltaTest')->status(null)->orderBy(['elements.id' => SORT_DESC])->one();
if (!$canonical instanceof Entry) {
    tdfail('No canonical entry found.');
}

$marker = 'TD' . date('YmdHis');

// A) Seed one pending workflow for endpoint tampering/replay checks
$draftPending = makeDraft($canonical, $author, "topdown-pending-{$marker}");
$wfPending = $plugin->workflow->submit($draftPending, (int)$reviewer->id, $author);
tdok("seeded pending workflow {$wfPending->id}");

// B) Seed submit+approve+reject flows for email checks
$draftApprove = makeDraft($canonical, $author, "topdown-approve-{$marker}");
$wfApprove = $plugin->workflow->submit($draftApprove, (int)$reviewer->id, $author);
$plugin->workflow->approveWholesale($wfApprove, null, $reviewer);

$draftReject = makeDraft($canonical, $author, "topdown-reject-{$marker}");
$wfReject = $plugin->workflow->submit($draftReject, (int)$reviewer->id, $author);
$plugin->workflow->reject($wfReject, "topdown reject {$marker}", $reviewer);

tdok('created approve/reject workflows for mail side-effects');

echo json_encode([
    'marker' => $marker,
    'canonicalId' => $canonical->id,
    'pendingWorkflowId' => $wfPending->id,
    'pendingDraftId' => $wfPending->draftId,
    'approveWorkflowId' => $wfApprove->id,
    'rejectWorkflowId' => $wfReject->id,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
