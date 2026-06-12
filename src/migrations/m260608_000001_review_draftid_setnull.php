<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\migrations;

use craft\db\Migration;

/**
 * Make craftdelta_reviews.draftId nullable and switch its FK from CASCADE to
 * SET NULL. Publishing an approved review deletes the draft (applyDraft), and
 * under CASCADE that destroyed the review row — losing the published state and
 * the per-round audit history. SET NULL keeps the row as a completed record.
 */
class m260608_000001_review_draftid_setnull extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%craftdelta_reviews}}';

        // Drop the existing draftId FK (whatever its generated name) before
        // altering the column / re-adding the constraint.
        $schema = $this->db->getSchema()->getTableSchema($table, true);
        foreach ($schema->foreignKeys as $name => $fk) {
            // $fk is [referencedTable, localCol => foreignCol, ...]
            if (array_key_exists('draftId', $fk)) {
                $this->dropForeignKey($name, $table);
            }
        }

        $this->alterColumn($table, 'draftId', $this->integer()->null());
        $this->addForeignKey(null, $table, ['draftId'], '{{%drafts}}', ['id'], 'SET NULL');

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%craftdelta_reviews}}';

        $schema = $this->db->getSchema()->getTableSchema($table, true);
        foreach ($schema->foreignKeys as $name => $fk) {
            if (array_key_exists('draftId', $fk)) {
                $this->dropForeignKey($name, $table);
            }
        }

        // Published reviews have draftId = NULL by design; they cannot exist
        // under the old NOT NULL + CASCADE schema, so drop them before the
        // ALTER (which would otherwise fail on the NULLs).
        $this->delete($table, ['draftId' => null]);

        $this->alterColumn($table, 'draftId', $this->integer()->notNull());
        $this->addForeignKey(null, $table, ['draftId'], '{{%drafts}}', ['id'], 'CASCADE');

        return true;
    }
}
