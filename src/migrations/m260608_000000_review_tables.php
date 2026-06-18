<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\migrations;

use craft\db\Migration;

/**
 * Reshapes the unreleased v2.0.0 single-assignee workflow into the review-request model: `craftdelta_reviews` + `craftdelta_review_reviewers`. The workflow feature never shipped, so there is no data to backfill — the old table is dropped outright.
 */
class m260608_000000_review_tables extends Migration
{
    public function safeUp(): bool
    {
        $this->dropTableIfExists('{{%craftdelta_draft_workflows}}');

        if (!$this->db->schema->getTableSchema('{{%craftdelta_reviews}}')) {
            $install = new Install();
            $install->db = $this->db;
            $install->safeUp();
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftdelta_review_reviewers}}');
        $this->dropTableIfExists('{{%craftdelta_reviews}}');
        return true;
    }
}
