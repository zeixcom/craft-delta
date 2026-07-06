<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->schema->getTableSchema('{{%craftdelta_reviews}}')) {
            $this->createReviewsTable();
        }

        if (!$this->db->schema->getTableSchema('{{%craftdelta_review_reviewers}}')) {
            $this->createReviewersTable();
        }

        if (!$this->db->schema->getTableSchema('{{%craftdelta_review_comments}}')) {
            $this->createReviewCommentsTable();
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftdelta_review_comments}}');
        $this->dropTableIfExists('{{%craftdelta_review_reviewers}}');
        $this->dropTableIfExists('{{%craftdelta_reviews}}');
        return true;
    }

    /** Shared by {@see m260608_000002_review_comments} for upgrades that predate comments. */
    public function createReviewCommentsTable(): void
    {
        $this->createTable('{{%craftdelta_review_comments}}', [
            'id' => $this->primaryKey(),
            'reviewId' => $this->integer()->notNull(),
            'round' => $this->smallInteger()->notNull()->defaultValue(1),
            'authorId' => $this->integer()->notNull(),
            'body' => $this->text()->notNull(),
            'anchorType' => $this->string(16)->notNull()->defaultValue('general'),
            'fieldHandle' => $this->string()->null(),
            'blockUid' => $this->char(36)->null(),
            'atomId' => $this->string()->null(),
            'resolved' => $this->boolean()->notNull()->defaultValue(false),
            'parentId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%craftdelta_review_comments}}', ['reviewId', 'round']);
        $this->createIndex(null, '{{%craftdelta_review_comments}}', ['parentId']);

        $this->addForeignKey(null, '{{%craftdelta_review_comments}}', ['reviewId'], '{{%craftdelta_reviews}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%craftdelta_review_comments}}', ['authorId'], '{{%users}}', ['id'], 'CASCADE');
        // Self-FK: deleting a parent comment nulls its replies' parentId (they
        // become top-level rather than vanishing).
        $this->addForeignKey(null, '{{%craftdelta_review_comments}}', ['parentId'], '{{%craftdelta_review_comments}}', ['id'], 'SET NULL');
    }

    private function createReviewsTable(): void
    {
        $this->createTable('{{%craftdelta_reviews}}', [
            'id' => $this->primaryKey(),
            // Nullable: when an approved draft is published, applyDraft() deletes
            // the draft and the FK SET NULL preserves this row as an audit record.
            'draftId' => $this->integer()->null(),
            'canonicalEntryId' => $this->integer()->notNull(),
            'sectionUid' => $this->char(36)->notNull(),
            'state' => $this->string(20)->notNull(),
            'round' => $this->smallInteger()->notNull()->defaultValue(1),
            'submittedBy' => $this->integer()->notNull(),
            'decidedBy' => $this->integer()->null(),
            'decisionNote' => $this->text()->null(),
            'scheduledFor' => $this->dateTime()->null(),
            'appliedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%craftdelta_reviews}}', ['draftId'], true);
        $this->createIndex(null, '{{%craftdelta_reviews}}', ['state']);
        $this->createIndex(null, '{{%craftdelta_reviews}}', ['state', 'scheduledFor']);
        $this->createIndex(null, '{{%craftdelta_reviews}}', ['sectionUid']);

        // SET NULL (not CASCADE): publishing an approved review deletes the draft,
        // and the review row must survive as a completed/published audit record.
        $this->addForeignKey(null, '{{%craftdelta_reviews}}', ['draftId'], '{{%drafts}}', ['id'], 'SET NULL');
        $this->addForeignKey(null, '{{%craftdelta_reviews}}', ['canonicalEntryId'], '{{%entries}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%craftdelta_reviews}}', ['submittedBy'], '{{%users}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%craftdelta_reviews}}', ['decidedBy'], '{{%users}}', ['id'], 'SET NULL');
    }

    private function createReviewersTable(): void
    {
        $this->createTable('{{%craftdelta_review_reviewers}}', [
            'id' => $this->primaryKey(),
            'reviewId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'round' => $this->smallInteger()->notNull()->defaultValue(1),
            'verdict' => $this->string(20)->notNull()->defaultValue('pending'),
            'note' => $this->text()->null(),
            'decidedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%craftdelta_review_reviewers}}', ['reviewId', 'userId', 'round'], true);
        $this->createIndex(null, '{{%craftdelta_review_reviewers}}', ['reviewId', 'round']);
        $this->createIndex(null, '{{%craftdelta_review_reviewers}}', ['userId', 'verdict']);

        $this->addForeignKey(null, '{{%craftdelta_review_reviewers}}', ['reviewId'], '{{%craftdelta_reviews}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%craftdelta_review_reviewers}}', ['userId'], '{{%users}}', ['id'], 'CASCADE');
    }
}
