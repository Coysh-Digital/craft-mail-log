<?php

namespace CoyshDigital\MailLog\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Whether to persist the text and HTML bodies of each email.
     * Disable to save disk space on high-volume sites.
     */
    public bool $logBodies = true;

    /**
     * Whether to create a log entry when Mailer::send() returns false.
     */
    public bool $logFailures = true;

    /**
     * Number of days to keep log entries, applied by the
     * `mail-log/logs/prune` console command. Set to 0 to keep forever.
     */
    public int $retentionDays = 0;

    /**
     * Override for the CP nav label. Blank uses the default.
     */
    public string $cpNavLabel = '';

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['logBodies', 'logFailures'], 'boolean'],
            ['retentionDays', 'integer', 'min' => 0],
            ['cpNavLabel', 'string'],
        ];
    }
}
