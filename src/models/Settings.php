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
     * Whether to create a log entry when a send fails.
     */
    public bool $logFailures = true;

    /**
     * Whether to withhold the bodies of system emails that contain
     * single-use credentials (see $sensitiveSystemKeys). Those bodies are
     * neither stored nor shown in the control panel, so that CP users with
     * access to the log can't use, say, a password reset link sent to an
     * admin to take over their account.
     */
    public bool $redactSensitiveEmails = true;

    /**
     * Craft system message keys whose bodies are withheld when
     * $redactSensitiveEmails is enabled. Can be extended from
     * `config/mail-log.php` to cover messages registered by other plugins.
     *
     * @var string[]
     */
    public array $sensitiveSystemKeys = [
        'account_activation',
        'forgot_password',
        'verify_new_email',
    ];

    /**
     * Number of days to keep log entries. Older entries are deleted during
     * Craft's garbage collection and by the `mail-log/logs/prune` console
     * command. Set to 0 to keep entries forever.
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
            [['logBodies', 'logFailures', 'redactSensitiveEmails'], 'boolean'],
            ['retentionDays', 'integer', 'min' => 0],
            ['cpNavLabel', 'trim'],
            ['cpNavLabel', 'string', 'max' => 255],
            ['sensitiveSystemKeys', 'each', 'rule' => ['string']],
        ];
    }
}
