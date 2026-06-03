<?php

declare(strict_types=1);

/**
 * Fixture setup for manually walking the Craft Delta submit → review → apply
 * flow in the control panel.
 *
 * Creates (or reconfigures) two non-admin users:
 *   - delta.author   — Submitter:  can create/edit drafts and submit for review
 *   - delta.reviewer  — Reviewer:   can review submitted drafts and apply changes
 *
 * Both users are granted the SAME native section access. The only thing that
 * differs between them is the (now section-agnostic) Craft Delta workflow
 * permission — demonstrating that section access is an orthogonal axis managed
 * by ordinary Craft permissions, not by Delta.
 *
 * Invoke via: `ddev craft craft-delta/smoke/setup-workflow-users`
 */

use Craft;
use craft\elements\User;

function out(string $message): void
{
    echo $message . PHP_EOL;
}

function bail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

// ── Config ────────────────────────────────────────────────────────────────
// Sections both users should be able to work in. Add handles here (e.g.
// 'blogposts') to widen the test surface — both users always get identical
// section access, so the workflow permission stays the only differentiator.
const TEST_SECTION_HANDLES = ['deltaTest'];

// Known dev password so you can log in as either user. Dev fixtures only.
const TEST_PASSWORD = 'DeltaTest!2026';

// ── Resolve target sections ─────────────────────────────────────────────────
$entries = Craft::$app->getEntries();
$sectionUids = [];
foreach (TEST_SECTION_HANDLES as $handle) {
    $section = $entries->getSectionByHandle($handle);
    if ($section === null) {
        bail("Section not found by handle: {$handle}");
    }
    $sectionUids[] = $section->uid;
    out("Section: {$section->name} ({$handle}) → {$section->uid}");
}

// editSite for every site, so entry editing is never blocked by site scope.
$siteUids = array_map(static fn($s) => $s->uid, Craft::$app->getSites()->getAllSites());

// Native section access — granted identically to both users.
$sectionAccess = [];
foreach ($sectionUids as $uid) {
    foreach ([
        'viewEntries',
        'createEntries',
        'saveEntries',
        'viewPeerEntries',
        'savePeerEntries',
        'viewPeerEntryDrafts',
        'savePeerEntryDrafts',
    ] as $perm) {
        $sectionAccess[] = "{$perm}:{$uid}";
    }
}

$basePermissions = array_merge(
    ['accessCp'],
    array_map(static fn($uid) => "editSite:{$uid}", $siteUids),
    $sectionAccess,
);

// ── Users: identical base, role-specific workflow permission ────────────────
$plan = [
    'delta.author' => [
        'email' => 'delta.author@example.com',
        'fullName' => 'Delta Author (Submitter)',
        'workflow' => ['craftdelta-submitDraft'],
    ],
    'delta.reviewer' => [
        'email' => 'delta.reviewer@example.com',
        'fullName' => 'Delta Reviewer (Approver)',
        'workflow' => ['craftdelta-reviewDraft', 'craftdelta-applyReview'],
    ],
];

foreach ($plan as $username => $cfg) {
    $user = User::find()->username($username)->status(null)->one();
    $created = false;

    if ($user === null) {
        $user = new User();
        $user->username = $username;
        $user->email = $cfg['email'];
        $created = true;
    }

    $user->admin = false;
    $user->fullName = $cfg['fullName'];
    $user->newPassword = TEST_PASSWORD;

    if (!Craft::$app->getElements()->saveElement($user)) {
        bail("Could not save user {$username}: " . json_encode($user->getErrors(), JSON_UNESCAPED_SLASHES));
    }

    if ($user->getStatus() === User::STATUS_PENDING) {
        Craft::$app->getUsers()->activateUser($user);
    }

    $permissions = array_values(array_unique(array_merge($basePermissions, $cfg['workflow'])));
    if (!Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, $permissions)) {
        bail("Could not save permissions for {$username}.");
    }

    $verb = $created ? 'Created' : 'Reconfigured';
    out("{$verb} {$username} (id {$user->id}) — workflow: " . implode(', ', $cfg['workflow']));
}

out('');
out('Both users share the same section access; password: ' . TEST_PASSWORD);
out('Done.');
