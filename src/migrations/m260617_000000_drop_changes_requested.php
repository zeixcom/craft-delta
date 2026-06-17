<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\migrations;

use craft\db\Migration;

/**
 * The "request changes" verdict and the changes_requested review state were
 * removed (reviewers now Approve or Decline; granular feedback lives in
 * comments). Existing in-flight rows still carry the dead value, which would
 * strand them — not active, not terminal, and no transition can move them.
 *
 * Reopen them: reset reviewer verdicts changes_requested -> pending and the
 * review state changes_requested -> open, so the review re-derives cleanly and
 * awaits fresh verdicts. String literals (not class consts) on purpose — a
 * migration is a historical snapshot and must not depend on current code.
 */
class m260617_000000_drop_changes_requested extends Migration
{
    public function safeUp(): bool
    {
        $this->update('{{%craftdelta_review_reviewers}}', ['verdict' => 'pending'], ['verdict' => 'changes_requested']);
        $this->update('{{%craftdelta_reviews}}', ['state' => 'open'], ['state' => 'changes_requested']);
        return true;
    }

    public function safeDown(): bool
    {
        // Irreversible: reopened rows are indistinguishable from reviews that
        // were already open, so the changes_requested value cannot be restored.
        echo "m260617_000000_drop_changes_requested cannot be reverted.\n";
        return false;
    }
}
