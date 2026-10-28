<?php

declare(strict_types=1);

/**
 * Regression check for #16: with `reviewerGroups` set, admins outside those
 * groups drop out of the eligible reviewers; empty or only-deleted groups keep
 * the old list. Read-only: changes settings in memory only.
 *
 * Needs the workflow fixtures (setup-workflow-users, setup-workflow-groups).
 *
 * Invoke via: `ddev craft craft-delta/smoke/reviewer-groups`
 */

use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use zeixcom\craftdelta\Delta;

require __DIR__ . '/_guard.php';

$delta = Delta::getInstance();
$section = Craft::$app->getEntries()->getSectionByHandle('deltaTest');
$group = Craft::$app->getUserGroups()->getGroupByHandle('deltaReviewers');
$reviewer = User::find()->username('delta.reviewer')->one();
$admin = $group === null ? null : User::find()
    ->admin()
    ->status(User::STATUS_ACTIVE)
    ->andWhere(['not in', 'elements.id', (new Query())->select('userId')->from(Table::USERGROUPS_USERS)->where(['groupId' => $group->id])])
    ->one();
if ($delta === null || $section === null || $group === null || $reviewer === null || $admin === null) {
    fwrite(STDERR, "FAIL: missing fixtures (run setup-workflow-users + setup-workflow-groups; needs an active admin outside deltaReviewers)\n");
    exit(1);
}

$settings = $delta->getSettings();
$original = $settings->reviewerGroups;
$fail = 0;

$check = function(string $label, array $groups, bool $expectAdmin) use ($delta, $settings, $section, $reviewer, $admin, &$fail): void {
    $settings->reviewerGroups = $groups;
    $ids = array_map(static fn(User $u) => $u->id, $delta->workflow->getEligibleAssignees($section->uid));
    $ok = in_array($reviewer->id, $ids, true) && in_array($admin->id, $ids, true) === $expectAdmin;
    echo ($ok ? 'PASS' : 'FAIL') . ": {$label}\n";
    $fail += $ok ? 0 : 1;
};

try {
    $check("empty setting lists admin {$admin->username}", [], true);
    $check("deltaReviewers excludes admin {$admin->username}", [$group->uid], false);
    $check('only deleted groups fall back to all reviewers', ['00000000-0000-0000-0000-000000000000'], true);
} finally {
    $settings->reviewerGroups = $original;
}
exit($fail === 0 ? 0 : 1);
