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

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftdelta_review_comments}}');
        return true;
    }
}
