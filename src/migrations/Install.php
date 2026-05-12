<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%craftdelta_draft_workflows}}', [
            'id' => $this->primaryKey(),
            'draftId' => $this->integer()->notNull(),
            'canonicalEntryId' => $this->integer()->notNull(),
            'sectionUid' => $this->char(36)->notNull(),
            'state' => $this->string(16)->notNull(),
            'submittedBy' => $this->integer()->notNull(),
            'assigneeId' => $this->integer()->null(),
            'decidedBy' => $this->integer()->null(),
            'rejectNote' => $this->text()->null(),
            'scheduledFor' => $this->dateTime()->null(),
            'appliedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%craftdelta_draft_workflows}}', ['assigneeId', 'state']);
        $this->createIndex(null, '{{%craftdelta_draft_workflows}}', ['state', 'scheduledFor']);
        $this->createIndex(null, '{{%craftdelta_draft_workflows}}', ['draftId'], true);
        $this->createIndex(null, '{{%craftdelta_draft_workflows}}', ['sectionUid']);

        $this->addForeignKey(null, '{{%craftdelta_draft_workflows}}', ['draftId'], '{{%drafts}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%craftdelta_draft_workflows}}', ['canonicalEntryId'], '{{%entries}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%craftdelta_draft_workflows}}', ['submittedBy'], '{{%users}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%craftdelta_draft_workflows}}', ['assigneeId'], '{{%users}}', ['id'], 'SET NULL');
        $this->addForeignKey(null, '{{%craftdelta_draft_workflows}}', ['decidedBy'], '{{%users}}', ['id'], 'SET NULL');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftdelta_draft_workflows}}');
        return true;
    }
}
