<?php

namespace CoyshDigital\MailLog\migrations;

use craft\db\Migration;

class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%mail_log_entries}}', [
            'id' => $this->primaryKey(),
            'success' => $this->boolean()->notNull()->defaultValue(false),
            'systemKey' => $this->string(255)->null(),
            'subject' => $this->string(500)->null(),
            'fromAddress' => $this->string(255)->null(),
            'fromName' => $this->string(255)->null(),
            'toAddresses' => $this->longText()->null(),
            'ccAddresses' => $this->longText()->null(),
            'bccAddresses' => $this->longText()->null(),
            'replyToAddresses' => $this->longText()->null(),
            'textBody' => $this->longText()->null(),
            'htmlBody' => $this->longText()->null(),
            'attachmentNames' => $this->longText()->null(),
            'error' => $this->text()->null(),
            'transportClass' => $this->string(255)->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%mail_log_entries}}', 'dateCreated');
        $this->createIndex(null, '{{%mail_log_entries}}', 'success');
        $this->createIndex(null, '{{%mail_log_entries}}', 'systemKey');

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%mail_log_entries}}');
        return true;
    }
}
