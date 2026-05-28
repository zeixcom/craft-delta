<?php
declare(strict_types=1);

use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use zeixcom\craftdelta\Delta;

function b2fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function b2ok(string $message): void
{
    echo "✓ {$message}\n";
}

function b2makeDraft(Entry $canonical, User $author, string $label): Entry
{
    Craft::$app->getUser()->setIdentity($author);
    $draft = Craft::$app->getDrafts()->createDraft($canonical, (int)$author->id, $label);
    $suffix = ' | ' . $label . ' ' . date('His');
    $base = substr((string)($canonical->title ?? 'Delta'), 0, max(1, 255 - strlen($suffix)));
    $draft->title = $base . $suffix;
    if (!Craft::$app->getElements()->saveElement($draft)) {
        b2fail('Draft save failed: ' . json_encode($draft->getErrors(), JSON_UNESCAPED_SLASHES));
    }
    $loaded = Entry::find()->draftId($draft->draftId)->status(null)->one();
    if (!$loaded instanceof Entry) {
        b2fail('Could not reload created draft.');
    }
    return $loaded;
}

$plugin = Delta::getInstance();
if ($plugin === null) {
    b2fail('Delta plugin unavailable.');
}

$author = User::find()->username('delta.author')->status(null)->one();
$reviewer = User::find()->username('delta.reviewer')->status(null)->one();
$outsider = User::find()->username('delta.outsider')->status(null)->one();
$admin = User::find()->username('delta.admin')->status(null)->one();
if (!$author || !$reviewer || !$outsider || !$admin) {
    b2fail('Missing one or more smoke users.');
}

$canonical = Entry::find()->section('deltaTest')->status(null)->orderBy(['elements.id' => SORT_DESC])->one();
if (!$canonical instanceof Entry) {
    b2fail('No canonical entry found in deltaTest section.');
}
$sectionUid = (string)$canonical->getSection()->uid;

// 1) Project-config / migration compatibility signal checks
$pluginRow = (new Query())
    ->from('{{%plugins}}')
    ->where(['handle' => 'craft-delta'])
    ->one();
if (!$pluginRow) {
    b2fail('No plugins row found for craft-delta.');
}
$dbSchemaVersion = (string)($pluginRow['schemaVersion'] ?? '');
$codeSchemaVersion = (string)$plugin->schemaVersion;
$schemaMatches = $dbSchemaVersion === $codeSchemaVersion;
b2ok("schema check db={$dbSchemaVersion} code={$codeSchemaVersion}");

// 2) Section permission matrix
$draft = b2makeDraft($canonical, $author, 'block2-perm-matrix');
$canSubmitAuthor = $plugin->workflow->canSubmit($author, $draft);
$canSubmitReviewer = $plugin->workflow->canSubmit($reviewer, $draft);
$canSubmitOutsider = $plugin->workflow->canSubmit($outsider, $draft);
$wf = $plugin->workflow->submit($draft, (int)$reviewer->id, $author);

$canReviewReviewer = $plugin->workflow->canReview($reviewer, $wf);
$canReviewOutsider = $plugin->workflow->canReview($outsider, $wf);
$canReviewAuthor = $plugin->workflow->canReview($author, $wf);
$canReviewAdmin = $plugin->workflow->canReview($admin, $wf);
b2ok('permission matrix checks executed');

// 3) Draft lifecycle oddity: cannot re-submit same draft after terminal transition
$plugin->workflow->reject($wf, 'block2 lifecycle reject', $reviewer);
$resubmitError = null;
try {
    $plugin->workflow->submit($draft, (int)$reviewer->id, $author);
} catch (Throwable $e) {
    $resubmitError = get_class($e) . ': ' . $e->getMessage();
}
$wfAfterReject = $plugin->workflow->getById((int)$wf->id);
b2ok('lifecycle oddity check executed');

echo json_encode([
    'migrationCompatibility' => [
        'dbSchemaVersion' => $dbSchemaVersion,
        'codeSchemaVersion' => $codeSchemaVersion,
        'schemaMatches' => $schemaMatches,
    ],
    'permissionMatrix' => [
        'sectionUid' => $sectionUid,
        'canSubmit' => [
            'author' => $canSubmitAuthor,
            'reviewer' => $canSubmitReviewer,
            'outsider' => $canSubmitOutsider,
        ],
        'canReview' => [
            'reviewer' => $canReviewReviewer,
            'outsider' => $canReviewOutsider,
            'author' => $canReviewAuthor,
            'admin' => $canReviewAdmin,
        ],
    ],
    'draftLifecycleOddity' => [
        'workflowId' => $wf->id,
        'stateAfterReject' => $wfAfterReject?->state,
        'resubmitBlocked' => $resubmitError !== null,
        'resubmitError' => $resubmitError,
    ],
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
