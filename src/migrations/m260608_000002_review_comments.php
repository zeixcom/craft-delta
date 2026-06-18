<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\migrations;

use craft\db\Migration;

/**
 * Review comments: anchored (field/atom) or general feedback per round.
 * One level of replies via parentId. "Outdated" is derived at render time.
 */
class m260608_000002_review_comments extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->schema->getTableSchema('{{%craftdelta_review_comments}}')) {
            return true;
        }

        $install = new Install();
        $install->db = $this->db;
        $install->createReviewCommentsTable();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftdelta_review_comments}}');
        return true;
    }
}
