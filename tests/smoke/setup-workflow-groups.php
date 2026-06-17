<?php

declare(strict_types=1);

/**
 * Separation-of-duties fixture: two Craft user GROUPS so authors can create &
 * submit drafts but cannot publish, while reviewers review + publish.
 *
 *   Delta Authors   — native: view + draft (peer) rights, NO saveEntries/
 *                     savePeerEntries (can't apply a draft to canonical);
 *                     Delta: craftdelta-submitDraft
 *   Delta Reviewers — native: full save (publish) on the section;
 *                     Delta: craftdelta-reviewDraft + craftdelta-applyReview
 *
 * Assigns the existing fixture users (delta.author / delta.reviewer) to the
 * groups and CLEARS their direct permissions, so the group is the sole source
 * of truth. Idempotent.
 *
 * Invoke via: `ddev craft craft-delta/smoke/setup-workflow-groups`
 */

use Craft;
use craft\elements\User;
use craft\models\UserGroup;

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

$section = Craft::$app->getEntries()->getSectionByHandle(SECTION_HANDLE);
if ($section === null) {
    bail("Section not found: " . SECTION_HANDLE);
}
$uid = $section->uid;
$siteUids = array_map(static fn($s) => $s->uid, Craft::$app->getSites()->getAllSites());
$base = array_merge(['accessCp'], array_map(static fn($u) => "editSite:{$u}", $siteUids));

// Authors: see entries + create/save DRAFTS of peers' entries, but NOT
// saveEntries/savePeerEntries — so they cannot apply a draft to canonical
// (no native "Apply draft", and the Delta publish gate also blocks them).
$authorPerms = array_merge($base, [
    "viewEntries:{$uid}",
    "createEntries:{$uid}",
    "viewPeerEntries:{$uid}",
    "viewPeerEntryDrafts:{$uid}",
    "savePeerEntryDrafts:{$uid}",
    'craftdelta-submitDraft',
]);

// Reviewers: full save (publish) on the section + the Delta review/apply perms.
$reviewerPerms = array_merge($base, [
    "viewEntries:{$uid}",
    "createEntries:{$uid}",
    "saveEntries:{$uid}",
    "viewPeerEntries:{$uid}",
    "savePeerEntries:{$uid}",
    "viewPeerEntryDrafts:{$uid}",
    "savePeerEntryDrafts:{$uid}",
    'craftdelta-reviewDraft',
    'craftdelta-applyReview',
]);

$plan = [
    'deltaAuthors' => ['name' => 'Delta Authors', 'perms' => $authorPerms, 'user' => 'delta.author'],
    'deltaReviewers' => ['name' => 'Delta Reviewers', 'perms' => $reviewerPerms, 'user' => 'delta.reviewer'],
];

$groups = Craft::$app->getUserGroups();
$perms = Craft::$app->getUserPermissions();

foreach ($plan as $handle => $cfg) {
    $group = $groups->getGroupByHandle($handle) ?? new UserGroup();
    $group->handle = $handle;
    $group->name = $cfg['name'];
    if (!$groups->saveGroup($group)) {
        bail("Could not save group {$handle}: " . json_encode($group->getErrors(), JSON_UNESCAPED_SLASHES));
    }
    if (!$perms->saveGroupPermissions((int)$group->id, $cfg['perms'])) {
        bail("Could not save permissions for group {$handle}.");
    }

    $user = User::find()->username($cfg['user'])->status(null)->one();
    if (!$user instanceof User) {
        bail("User not found: {$cfg['user']} — run setup-workflow-users first.");
    }
    Craft::$app->getUsers()->assignUserToGroups((int)$user->id, [(int)$group->id]);
    // Clear direct permissions so the group is the only source of truth.
    $perms->saveUserPermissions((int)$user->id, []);

    out("Group '{$cfg['name']}' (id {$group->id}) → {$cfg['user']} assigned; direct perms cleared.");
}

out('');
out('Done. delta.author = Delta Authors (no publish), delta.reviewer = Delta Reviewers (publish).');
