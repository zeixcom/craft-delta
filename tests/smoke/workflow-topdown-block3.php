<?php
declare(strict_types=1);

use craft\elements\Entry;
use craft\elements\User;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\models\DraftWorkflow;

function b3fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function b3ok(string $message): void
{
    echo "✓ {$message}\n";
}

function b3makeDraft(Entry $canonical, User $author, string $label): Entry
{
    Craft::$app->getUser()->setIdentity($author);
    $draft = Craft::$app->getDrafts()->createDraft($canonical, (int)$author->id, $label);
    $suffix = ' | ' . $label . ' ' . date('His');
    $base = substr((string)($canonical->title ?? 'Delta'), 0, max(1, 255 - strlen($suffix)));
    $draft->title = $base . $suffix;
    if (!Craft::$app->getElements()->saveElement($draft)) {
        b3fail('Draft save failed: ' . json_encode($draft->getErrors(), JSON_UNESCAPED_SLASHES));
    }
    $loaded = Entry::find()->draftId($draft->draftId)->status(null)->one();
    if (!$loaded instanceof Entry) {
        b3fail('Could not reload created draft.');
    }
    return $loaded;
}

$plugin = Delta::getInstance();
if ($plugin === null) {
    b3fail('Delta plugin unavailable.');
}

$author = User::find()->username('delta.author')->status(null)->one();
$reviewer = User::find()->username('delta.reviewer')->status(null)->one();
$outsider = User::find()->username('delta.outsider')->status(null)->one();
if (!$author || !$reviewer || !$outsider) {
    b3fail('Missing one or more smoke users.');
}

$canonical = Entry::find()->section('deltaTest')->status(null)->orderBy(['elements.id' => SORT_DESC])->one();
if (!$canonical instanceof Entry) {
    b3fail('No canonical entry found in deltaTest section.');
}

$marker = 'B3' . date('YmdHis');
$scheduledIds = [];
$rejectedIds = [];
$submitFailures = 0;
$approveFailures = 0;
$rejectFailures = 0;

// 1) High-volume mixed load: create many pending workflows, then mix transitions.
for ($i = 0; $i < 12; $i++) {
    $draft = b3makeDraft($canonical, $author, "block3-mixed-{$marker}-{$i}");
    try {
        $wf = $plugin->workflow->submit($draft, (int)$reviewer->id, $author);
    } catch (\Throwable) {
        $submitFailures++;
        continue;
    }
    try {
        if ($i % 3 === 0) {
            $plugin->workflow->approveWholesale($wf, new \DateTime('-1 minute'), $reviewer);
            $scheduledIds[] = (int)$wf->id;
        } elseif ($i % 3 === 1) {
            $plugin->workflow->reject($wf, "block3 reject {$marker}", $reviewer);
            $rejectedIds[] = (int)$wf->id;
        } else {
            // Keep pending intentionally for mixed-state pressure.
        }
    } catch (\Throwable $e) {
        if ($i % 3 === 0) {
            $approveFailures++;
        } elseif ($i % 3 === 1) {
            $rejectFailures++;
        }
    }
}

Craft::$app->getQueue()->run();

$scheduledApplied = 0;
foreach ($scheduledIds as $wid) {
    $wf = $plugin->workflow->getById($wid);
    if ($wf === null || $wf->appliedAt !== null || $wf->state === DraftWorkflow::STATE_APPROVED) {
        $scheduledApplied++;
    }
}

$rejectedPersisted = 0;
foreach ($rejectedIds as $wid) {
    $wf = $plugin->workflow->getById($wid);
    if ($wf !== null && $wf->state === DraftWorkflow::STATE_REJECTED) {
        $rejectedPersisted++;
    }
}
b3ok('high-volume mixed load executed');

// 2) Security-focused negative service checks.
$outsiderApproveDenied = false;
$outsiderRejectDenied = false;
$invalidTransitionBlocked = false;
$securityDiag = [];

$draftSec = b3makeDraft($canonical, $author, "block3-sec-{$marker}");
$wfSec = $plugin->workflow->submit($draftSec, (int)$reviewer->id, $author);

try {
    $plugin->workflow->approveWholesale($wfSec, null, $outsider);
    $securityDiag[] = 'outsider approve unexpectedly succeeded';
} catch (\Throwable $e) {
    $outsiderApproveDenied = $e instanceof \yii\web\ForbiddenHttpException;
    $securityDiag[] = 'outsider approve denied: ' . $e::class;
}

$wfSecReload = $plugin->workflow->getById((int)$wfSec->id);
if ($wfSecReload === null) {
    b3fail('Security regression: workflow disappeared after denied outsider approve attempt.');
}
if ($wfSecReload->state !== DraftWorkflow::STATE_PENDING) {
    b3fail('Security regression: workflow state mutated after denied outsider approve attempt.');
}

try {
    $plugin->workflow->reject($wfSecReload, 'outsider reject attempt', $outsider);
    $securityDiag[] = 'outsider reject unexpectedly succeeded';
} catch (\Throwable $e) {
    $outsiderRejectDenied = $e instanceof \yii\web\ForbiddenHttpException;
    $securityDiag[] = 'outsider reject denied: ' . $e::class;
}

$wfSecReload = $plugin->workflow->getById((int)$wfSec->id);
if ($wfSecReload === null || $wfSecReload->state !== DraftWorkflow::STATE_PENDING) {
    b3fail('Security regression: workflow state mutated after denied outsider reject attempt.');
}

try {
    $plugin->workflow->reject($wfSecReload, 'reviewer terminal reject', $reviewer);
    $wfSecReload = $plugin->workflow->getById((int)$wfSec->id);
    if ($wfSecReload === null || $wfSecReload->state !== DraftWorkflow::STATE_REJECTED) {
        b3fail('Security setup failed: reviewer reject did not reach terminal rejected state.');
    }
    $plugin->workflow->approveWholesale($wfSecReload, null, $reviewer);
    $securityDiag[] = 'invalid transition unexpectedly succeeded';
} catch (\Throwable $e) {
    $invalidTransitionBlocked = $e instanceof \yii\web\ForbiddenHttpException;
    $securityDiag[] = 'invalid transition blocked: ' . $e::class;
}

if (!$outsiderApproveDenied || !$outsiderRejectDenied || !$invalidTransitionBlocked) {
    b3fail('Security regression: one or more negative checks failed. ' . json_encode($securityDiag));
}
b3ok('security negative checks executed');

echo json_encode([
    'marker' => $marker,
    'highVolumeMixedLoad' => [
        'created' => 12,
        'submitFailures' => $submitFailures,
        'approveFailures' => $approveFailures,
        'rejectFailures' => $rejectFailures,
        'scheduledTotal' => count($scheduledIds),
        'scheduledAppliedOrTerminal' => $scheduledApplied,
        'rejectedTotal' => count($rejectedIds),
        'rejectedPersisted' => $rejectedPersisted,
    ],
    'securityNegativeChecks' => [
        'outsiderApproveDenied' => $outsiderApproveDenied,
        'outsiderRejectDenied' => $outsiderRejectDenied,
        'invalidTransitionBlocked' => $invalidTransitionBlocked,
        'diagnostics' => $securityDiag,
    ],
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
