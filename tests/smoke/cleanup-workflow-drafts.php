<?php

declare(strict_types=1);

/**
 * Cleanup fixture: hard-delete the leftover DRAFT entries still referenced by
 * review rows (craftdelta_reviews.draftId), so the demo isn't littered with
 * in-progress review drafts.
 *
 * Deletes through Craft's Elements service (not raw SQL) so the plugin's
 * BEFORE_DELETE listener fires: a still-active review is cancelled as its draft
 * goes, and the reviews.draftId FK is SET NULL. Published/terminal reviews keep
 * their audit row (only the orphaned draft is removed). Review records
 * themselves are left intact.
 *
 * Invoke via: `ddev craft craft-delta/smoke/cleanup-workflow-drafts`
 */

use Craft;
use craft\elements\Entry;
use zeixcom\craftdelta\records\ReviewRecord;

require __DIR__ . '/_guard.php';

function out(string $message): void
{
    echo $message . PHP_EOL;
}

$rows = ReviewRecord::find()->where(['not', ['draftId' => null]])->all();
$deleted = 0;
$missing = 0;

/** @var ReviewRecord $r */
foreach ($rows as $r) {
    $draftId = (int)$r->draftId;
    $draft = Entry::find()->draftId($draftId)->status(null)->one();
    if (!$draft instanceof Entry) {
        $missing++;
        continue;
    }
    if (Craft::$app->getElements()->deleteElement($draft, true)) {
        out("Deleted draft {$draftId} (review {$r->id}, was '{$r->state}').");
        $deleted++;
    } else {
        out("FAILED to delete draft {$draftId} (review {$r->id}).");
    }
}

out('');
out("Done. Deleted {$deleted} leftover review draft(s); {$missing} already gone.");
