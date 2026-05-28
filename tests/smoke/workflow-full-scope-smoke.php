<?php
declare(strict_types=1);

use craft\elements\Entry;
use craft\elements\User;
use yii\db\Query;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\models\DraftWorkflow;

function fail(string $message, int $code = 1): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit($code);
}

function ok(string $message): void
{
    echo "✓ {$message}\n";
}

echo "── Craft Delta v2 workflow full-scope smoke ──\n";

$sectionUid = '6c35f0db-bc23-44e5-9c1c-1cc17c516321';
$plugin = Delta::getInstance();
if ($plugin === null) {
    fail('Craft Delta plugin instance unavailable.');
}

$admin = User::find()->username('delta.admin')->status(null)->one();
$author = User::find()->username('delta.author')->status(null)->one();
$reviewer = User::find()->username('delta.reviewer')->status(null)->one();
$outsider = User::find()->username('delta.outsider')->status(null)->one();

if (!$admin || !$author || !$reviewer || !$outsider) {
    fail('Required smoke users missing (delta.admin/author/reviewer/outsider).');
}
ok('Smoke users exist.');

Craft::$app->getUser()->setIdentity($admin);
$canonical = Entry::find()->section('deltaTest')->status(null)->orderBy(['elements.id' => SORT_DESC])->one();
if (!$canonical instanceof Entry) {
    fail('No canonical entry found in section `deltaTest`.');
}
ok("Canonical entry selected: id={$canonical->id}");

$db = Craft::$app->getDb();
$insertPermission = static function(int $userId, string $permission) use ($db): void {
    $permId = (new Query())->select(['id'])->from('{{%userpermissions}}')->where(['name' => $permission])->scalar($db);
    if ($permId === false || $permId === null) {
        $db->createCommand()->insert('{{%userpermissions}}', ['name' => $permission])->execute();
        $permId = (int)$db->getLastInsertID();
    }
    $exists = (new Query())
        ->from('{{%userpermissions_users}}')
        ->where(['permissionId' => (int)$permId, 'userId' => $userId])
        ->exists($db);
    if (!$exists) {
        $db->createCommand()->insert('{{%userpermissions_users}}', [
            'permissionId' => (int)$permId,
            'userId' => $userId,
        ])->execute();
    }
};

$insertPermission((int)$author->id, "craftdelta-submitdraft:{$sectionUid}");
$insertPermission((int)$reviewer->id, "craftdelta-reviewdraft:{$sectionUid}");
$insertPermission((int)$reviewer->id, "craftdelta-applyreview:{$sectionUid}");
ok('Required Craft Delta permissions assigned.');

$createChangedDraft = static function(Entry $source, User $creator, string $label): Entry {
    Craft::$app->getUser()->setIdentity($creator);
    $draft = Craft::$app->getDrafts()->createDraft($source, (int)$creator->id, $label);
    $baseTitle = (string)($source->title ?? 'Delta Test');
    $suffix = ' | ' . $label . ' ' . date('His');
    $maxBaseLen = max(1, 255 - strlen($suffix));
    $draft->title = substr($baseTitle, 0, $maxBaseLen) . $suffix;
    if (!Craft::$app->getElements()->saveElement($draft)) {
        fail('Unable to save draft: ' . json_encode($draft->getErrors(), JSON_UNESCAPED_SLASHES));
    }
    $loaded = Entry::find()->draftId($draft->draftId)->status(null)->one();
    if (!$loaded instanceof Entry) {
        fail('Saved draft could not be reloaded.');
    }
    return $loaded;
};

// Flow 1: submit -> approve now
$draft1 = $createChangedDraft($canonical, $author, 'smoke-wholesale-now');
$wf1 = $plugin->workflow->submit($draft1, (int)$reviewer->id, $author);
if ($wf1->state !== DraftWorkflow::STATE_PENDING) {
    fail('Workflow #1 not pending after submit.');
}
ok("Workflow #1 submitted (draftId={$wf1->draftId}) and pending.");

if ($plugin->workflow->canReview($outsider, $wf1)) {
    fail('Outsider unexpectedly can review pending workflow.');
}
ok('Outsider cannot review pending workflow.');

Craft::$app->getUser()->setIdentity($reviewer);
$plugin->workflow->approveWholesale($wf1, null, $reviewer);
$wf1After = $plugin->workflow->getById((int)$wf1->id);
$flow1Applied = $wf1After === null
    ? Entry::find()->draftId($wf1->draftId)->status(null)->one() === null
    : ($wf1After->state === DraftWorkflow::STATE_APPROVED && $wf1After->appliedAt !== null);
if (!$flow1Applied) {
    fail('Workflow #1 approve-now did not apply draft as expected.');
}
ok('Workflow #1 approve-now applied successfully.');

// Flow 2: submit -> schedule -> queue run -> applied
$draft2 = $createChangedDraft($canonical, $author, 'smoke-scheduled');
$wf2 = $plugin->workflow->submit($draft2, (int)$reviewer->id, $author);
$scheduledFor = new \DateTime('-1 minute');
$plugin->workflow->approveWholesale($wf2, $scheduledFor, $reviewer);

$wf2AfterApprove = $plugin->workflow->getById((int)$wf2->id);
if ($wf2AfterApprove?->state !== DraftWorkflow::STATE_APPROVED || $wf2AfterApprove->appliedAt !== null) {
    fail('Workflow #2 scheduling state invalid (expected approved + not applied yet).');
}
ok('Workflow #2 scheduled approval recorded.');

// Execute queue within app context.
Craft::$app->getQueue()->run();
$wf2AfterQueue = $plugin->workflow->getById((int)$wf2->id);
$flow2Applied = $wf2AfterQueue === null
    ? Entry::find()->draftId($wf2->draftId)->status(null)->one() === null
    : $wf2AfterQueue->appliedAt !== null;
if (!$flow2Applied) {
    fail('Workflow #2 was not applied after queue run. '
        . json_encode([
            'wf2Id' => $wf2->id,
            'wf2AfterQueue' => $wf2AfterQueue ? [
                'state' => $wf2AfterQueue->state,
                'appliedAt' => $wf2AfterQueue->appliedAt?->format(\DateTime::ATOM),
            ] : null,
        ], JSON_UNESCAPED_SLASHES)
    );
}
ok('Workflow #2 scheduled publish applied via queue.');

// Flow 3: submit -> reject
$draft3 = $createChangedDraft($canonical, $author, 'smoke-reject');
$wf3 = $plugin->workflow->submit($draft3, (int)$reviewer->id, $author);
$plugin->workflow->reject($wf3, 'Smoke rejection note', $reviewer);
$wf3After = $plugin->workflow->getById((int)$wf3->id);
if ($wf3After?->state !== DraftWorkflow::STATE_REJECTED || $wf3After->rejectNote !== 'Smoke rejection note') {
    fail('Workflow #3 reject did not persist expected state/note.');
}
ok('Workflow #3 reject path verified.');

// Permission gate sanity: reviewer must hold apply permission, outsider must not.
$reviewerCanApply = $reviewer->can("craftdelta-applyreview:{$sectionUid}");
$outsiderCanApply = $outsider->can("craftdelta-applyreview:{$sectionUid}");
if (!$reviewerCanApply || $outsiderCanApply) {
    fail('Apply permission gate invalid (reviewer should have it; outsider should not).');
}
ok('Apply permission gate verified (reviewer yes, outsider no).');

// DB summary evidence
$rows = (new Query())
    ->select(['id', 'draftId', 'state', 'submittedBy', 'assigneeId', 'decidedBy', 'scheduledFor', 'appliedAt'])
    ->from('{{%craftdelta_draft_workflows}}')
    ->where(['draftId' => [$wf1->draftId, $wf2->draftId, $wf3->draftId]])
    ->orderBy(['id' => SORT_ASC])
    ->all($db);

echo "\nWorkflow rows:\n";
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

echo "\n══ ALL CHECKS PASSED — workflow full-scope smoke complete ══\n";
exit(0);
