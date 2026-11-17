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
     * Whether to deflate-compress stored bodies. HTML emails typically
     * shrink to a quarter of their size or less. Compressed bodies are
     * decoded transparently on read, and entries stored without compression
     * remain readable, so this can be toggled at any time.
     */
    public bool $compressBodies = true;

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
     * command. Set to 0 to keep entries forever (not recommended while
     * $logBodies is on).
     *
     * Installs that have explicitly saved a value (including 0) keep it.
     */
    public int $retentionDays = 30;

    /**
     * Override for the CP nav label. Blank uses the default.
     */
    public string $cpNavLabel = '';

    /**
     * Whether entries are kept forever while full email bodies are stored,
     * which makes the log grow without bound (typically ~50 KB per email
     * uncompressed).
     */
    public function hasUnboundedBodyStorage(): bool
    {
        return $this->retentionDays <= 0 && $this->logBodies;
    }

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['logBodies', 'compressBodies', 'logFailures', 'redactSensitiveEmails'], 'boolean'],
            ['retentionDays', 'integer', 'min' => 0],
            ['cpNavLabel', 'trim'],
            ['cpNavLabel', 'string', 'max' => 255],
            ['sensitiveSystemKeys', 'each', 'rule' => ['string']],
        ];
    }
}
