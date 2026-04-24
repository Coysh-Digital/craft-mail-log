<?php

namespace CoyshDigital\MailLog\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property bool $success
 * @property string|null $systemKey
 * @property string $subject
 * @property string|null $fromAddress
 * @property string|null $fromName
 * @property array $toAddresses
 * @property array|null $ccAddresses
 * @property array|null $bccAddresses
 * @property array|null $replyToAddresses
 * @property string|null $textBody
 * @property string|null $htmlBody
 * @property array|null $attachmentNames
 * @property string|null $error
 * @property string|null $transportClass
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class MailLogRecord extends ActiveRecord
{
    /**
     * Columns that are stored as JSON in the database but exposed as
     * arrays on the record.
     */
    private const JSON_COLUMNS = [
        'toAddresses',
        'ccAddresses',
        'bccAddresses',
        'replyToAddresses',
        'attachmentNames',
    ];

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%mail_log_entries}}';
    }

    /**
     * @inheritdoc
     */
    public function beforeSave($insert): bool
    {
        foreach (self::JSON_COLUMNS as $column) {
            $value = $this->getAttribute($column);
            if (is_array($value)) {
                $this->setAttribute($column, $value === [] ? null : json_encode($value));
            }
        }
        return parent::beforeSave($insert);
    }

    /**
     * @inheritdoc
     */
    public function afterFind(): void
    {
        foreach (self::JSON_COLUMNS as $column) {
            $value = $this->getAttribute($column);
            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $this->setAttribute($column, $decoded);
                }
            } elseif ($value === null) {
                $this->setAttribute($column, null);
            }
        }
        parent::afterFind();
    }
}
