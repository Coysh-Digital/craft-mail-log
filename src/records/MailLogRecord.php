<?php

namespace CoyshDigital\MailLog\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property bool $success
 * @property string|null $systemKey
 * @property string|null $subject
 * @property string|null $fromAddress
 * @property string|null $fromName
 * @property array<string, string|null>|null $toAddresses
 * @property array<string, string|null>|null $ccAddresses
 * @property array<string, string|null>|null $bccAddresses
 * @property array<string, string|null>|null $replyToAddresses
 * @property string|null $textBody
 * @property string|null $htmlBody
 * @property string[]|null $attachmentNames
 * @property string|null $error
 * @property string|null $transportClass
 * @property string $dateCreated UTC, as stored in the database
 * @property string $dateUpdated UTC, as stored in the database
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
     *
     * Decode JSON columns on read. We can't materialise the decoded array back
     * onto the attribute (Craft's ActiveRecord::setAttribute() re-serialises
     * arrays for the DB via Db::prepareValueForDb(), so any decode is
     * immediately undone). Instead, intercept reads and decode on the fly.
     */
    public function __get($name)
    {
        if (in_array($name, self::JSON_COLUMNS, true)) {
            $value = $this->getAttribute($name);
            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            if (is_array($value)) {
                return $value;
            }
            return null;
        }
        return parent::__get($name);
    }
}
