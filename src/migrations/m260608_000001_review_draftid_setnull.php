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
    private const TABLE = '{{%craftdelta_reviews}}';

    public function safeUp(): bool
    {
        $this->dropDraftIdForeignKey();
        $this->alterColumn(self::TABLE, 'draftId', $this->integer()->null());
        $this->addForeignKey(null, self::TABLE, ['draftId'], '{{%drafts}}', ['id'], 'SET NULL');
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropDraftIdForeignKey();

        // Published reviews have draftId = NULL by design; they cannot exist
        // under the old NOT NULL + CASCADE schema, so drop them before the
        // ALTER (which would otherwise fail on the NULLs).
        $this->delete(self::TABLE, ['draftId' => null]);

        $this->alterColumn(self::TABLE, 'draftId', $this->integer()->notNull());
        $this->addForeignKey(null, self::TABLE, ['draftId'], '{{%drafts}}', ['id'], 'CASCADE');
        return true;
    }

    private function dropDraftIdForeignKey(): void
    {
        foreach ($this->db->getSchema()->getTableSchema(self::TABLE, true)->foreignKeys as $name => $fk) {
            if (array_key_exists('draftId', $fk)) {
                $this->dropForeignKey($name, self::TABLE);
            }
        }
    }
}
