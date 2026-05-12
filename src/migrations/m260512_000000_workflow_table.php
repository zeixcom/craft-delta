<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\migrations;

use craft\db\Migration;

class m260512_000000_workflow_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->schema->getTableSchema('{{%craftdelta_draft_workflows}}')) {
            return true;
        }

        $install = new Install();
        $install->db = $this->db;
        return $install->safeUp();
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftdelta_draft_workflows}}');
        return true;
    }
}
