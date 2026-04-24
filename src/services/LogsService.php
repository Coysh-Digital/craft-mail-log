<?php

namespace CoyshDigital\MailLog\services;

use CoyshDigital\MailLog\MailLog;
use CoyshDigital\MailLog\records\MailLogRecord;
use craft\base\Component;
use craft\mail\Message as CraftMessage;
use yii\data\Pagination;
use yii\mail\MessageInterface;

class LogsService extends Component
{
    /**
     * Persist a log entry for a single send.
     *
     * Returns true if the record was saved, false if the send was
     * filtered out by settings (e.g. failure logging disabled).
     */
    public function log(MessageInterface $message, bool $isSuccessful): bool
    {
        $settings = MailLog::$plugin->getSettings();

        if (!$isSuccessful && !$settings->logFailures) {
            return false;
        }

        $record = new MailLogRecord();
        $record->success = $isSuccessful;
        $record->subject = (string) $message->getSubject();

        $from = $this->addressesToArray($message->getFrom());
        $record->fromAddress = $from !== [] ? (array_keys($from)[0] ?? null) : null;
        $record->fromName = $from !== [] ? (array_values($from)[0] ?? null) : null;

        $record->toAddresses = $this->extractAddresses($message, 'to');
        $record->ccAddresses = $this->extractAddresses($message, 'cc');
        $record->bccAddresses = $this->extractAddresses($message, 'bcc');
        $record->replyToAddresses = $this->extractAddresses($message, 'replyTo');

        // Craft system-message key (password reset, verify account, etc.)
        if ($message instanceof CraftMessage && !empty($message->key)) {
            $record->systemKey = $message->key;
        }

        // Bodies and attachments via the underlying Symfony email, if available.
        if ($settings->logBodies) {
            $this->capturePayload($record, $message);
        } else {
            // Still capture attachment filenames even when bodies are off.
            $this->captureAttachments($record, $message);
        }

        if (!$isSuccessful) {
            $record->error = $this->extractError($message);
        }

        $record->transportClass = $this->captureTransport();

        return $record->save(false);
    }

    /**
     * Fetch log entries for the CP list.
     *
     * @return array{0: MailLogRecord[], 1: Pagination}
     */
    public function getLogs(int $pageSize = 50): array
    {
        $query = MailLogRecord::find()->orderBy(['dateCreated' => SORT_DESC]);
        $countQuery = clone $query;
        $pagination = new Pagination([
            'totalCount' => (int) $countQuery->count(),
            'defaultPageSize' => $pageSize,
            'pageSizeLimit' => [10, 500],
        ]);
        $records = $query
            ->offset($pagination->offset)
            ->limit($pagination->limit)
            ->all();
        return [$records, $pagination];
    }

    public function getLogById(int $id): ?MailLogRecord
    {
        return MailLogRecord::findOne(['id' => $id]);
    }

    public function delete(MailLogRecord $log): bool
    {
        return (bool) $log->delete();
    }

    public function deleteAll(): int
    {
        return (int) MailLogRecord::deleteAll();
    }

    public function deleteOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = (new \DateTimeImmutable())->sub(new \DateInterval("P{$days}D"));
        return (int) MailLogRecord::deleteAll([
            '<', 'dateCreated', $cutoff->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Normalise an address value to [email => name|null] form. Yii mailers
     * accept strings, sequential arrays, and assoc arrays interchangeably.
     */
    protected function addressesToArray(mixed $addresses): array
    {
        if (empty($addresses)) {
            return [];
        }
        if (is_string($addresses)) {
            return [$addresses => null];
        }
        if (!is_array($addresses)) {
            return [];
        }
        $normalised = [];
        foreach ($addresses as $key => $value) {
            if (is_int($key)) {
                // Sequential: value is the email
                $normalised[(string) $value] = null;
            } else {
                // Assoc: key is email, value is name
                $normalised[(string) $key] = is_string($value) ? $value : null;
            }
        }
        return $normalised;
    }

    /**
     * Capture bodies and attachment filenames from the underlying
     * Symfony email, if accessible.
     */
    protected function capturePayload(MailLogRecord $record, MessageInterface $message): void
    {
        try {
            if (!method_exists($message, 'getSymfonyEmail')) {
                return;
            }
            $email = $message->getSymfonyEmail();
            $text = $email->getTextBody();
            $html = $email->getHtmlBody();
            $record->textBody = $text !== null && $text !== '' ? $text : null;
            $record->htmlBody = $html !== null && $html !== '' ? $html : null;

            $attachments = [];
            foreach ($email->getAttachments() as $attachment) {
                if (method_exists($attachment, 'getFilename')) {
                    $name = $attachment->getFilename();
                    $attachments[] = $name ?: 'unnamed';
                }
            }
            $record->attachmentNames = $attachments;
        } catch (\Throwable $e) {
            // Non-fatal: keep the envelope data we already have.
        }
    }

    /**
     * Capture just the attachment filenames (used when body logging is off).
     */
    protected function captureAttachments(MailLogRecord $record, MessageInterface $message): void
    {
        try {
            if (!method_exists($message, 'getSymfonyEmail')) {
                return;
            }
            $attachments = [];
            foreach ($message->getSymfonyEmail()->getAttachments() as $attachment) {
                if (method_exists($attachment, 'getFilename')) {
                    $name = $attachment->getFilename();
                    $attachments[] = $name ?: 'unnamed';
                }
            }
            $record->attachmentNames = $attachments;
        } catch (\Throwable $e) {
            // Non-fatal.
        }
    }

    /**
     * Look for a Throwable or error string left on the message by the mailer.
     */
    protected function extractError(MessageInterface $message): ?string
    {
        if (!($message instanceof CraftMessage)) {
            return null;
        }
        if (!isset($message->error) || $message->error === null) {
            return null;
        }
        if ($message->error instanceof \Throwable) {
            return $message->error->getMessage();
        }
        return (string) $message->error;
    }

    /**
     * Best-effort capture of the transport class in effect at send time.
     */
    protected function captureTransport(): ?string
    {
        try {
            $mailer = \Craft::$app->getMailer();
            // Symfony mailer-based Craft mailers expose ->transport
            if (isset($mailer->transport) && is_object($mailer->transport)) {
                return get_class($mailer->transport);
            }
            return get_class($mailer);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Read addresses from the underlying Symfony email where possible,
     * falling back to the Yii wrapper. Yii's getTo()/getCc()/etc. can
     * return data in formats my normaliser doesn't always catch, whereas
     * Symfony's Email always returns Address[] with clean accessors.
     *
     * @param string $type 'to' | 'cc' | 'bcc' | 'replyTo'
     */
    protected function extractAddresses(MessageInterface $message, string $type): array
    {
        // Preferred: go straight to the Symfony email.
        if (method_exists($message, 'getSymfonyEmail')) {
            try {
                $email = $message->getSymfonyEmail();
                $method = match ($type) {
                    'to' => 'getTo',
                    'cc' => 'getCc',
                    'bcc' => 'getBcc',
                    'replyTo' => 'getReplyTo',
                };
                $addresses = $email->{$method}();
                $out = [];
                foreach ($addresses as $addr) {
                    if (is_object($addr) && method_exists($addr, 'getAddress')) {
                        $email = $addr->getAddress();
                        $name = method_exists($addr, 'getName') ? $addr->getName() : null;
                        $out[$email] = $name !== '' ? $name : null;
                    } elseif (is_string($addr)) {
                        $out[$addr] = null;
                    }
                }
                return $out;
            } catch (\Throwable $e) {
                // Fall through to the Yii wrapper.
            }
        }

        // Fallback: Yii wrapper.
        $getter = match ($type) {
            'to' => 'getTo',
            'cc' => 'getCc',
            'bcc' => 'getBcc',
            'replyTo' => 'getReplyTo',
        };
        return $this->addressesToArray($message->{$getter}());
    }
}
