<?php

namespace CoyshDigital\MailLog\services;

use CoyshDigital\MailLog\mail\FailureReportingTransport;
use CoyshDigital\MailLog\MailLog;
use CoyshDigital\MailLog\records\MailLogRecord;
use Craft;
use craft\base\Component;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\mail\Message as CraftMessage;
use ReflectionProperty;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Throwable;
use yii\data\Pagination;
use yii\mail\BaseMailer;
use yii\mail\MessageInterface;
use yii\symfonymailer\Mailer as SymfonyMailer;
use yii\symfonymailer\MessageWrapperInterface;

class LogsService extends Component
{
    /**
     * Maximum lengths (in characters) of the string columns created by the
     * Install migration. Longer values are truncated rather than allowed to
     * make the insert fail, which would lose the entry entirely.
     */
    private const COLUMN_LENGTHS = [
        'systemKey' => 255,
        'subject' => 500,
        'fromAddress' => 255,
        'fromName' => 255,
        'transportClass' => 255,
    ];

    /**
     * Maximum size (in bytes) of the `error` column. It's a TEXT column,
     * which MySQL caps at 64KB.
     */
    private const ERROR_MAX_BYTES = 65535;

    /**
     * Columns needed to render the CP list view. The (potentially large)
     * body columns are deliberately excluded.
     */
    private const LIST_COLUMNS = ['id', 'dateCreated', 'subject', 'toAddresses', 'fromAddress', 'success'];

    /**
     * Columns matched by the CP list search.
     */
    private const SEARCH_COLUMNS = [
        'subject',
        'fromAddress',
        'fromName',
        'toAddresses',
        'ccAddresses',
        'bccAddresses',
        'systemKey',
    ];

    /**
     * Number of rows removed per DELETE when pruning, to avoid long table
     * locks and huge transactions on high-volume sites.
     */
    private const PRUNE_BATCH_SIZE = 1000;

    /**
     * The message currently being handed to a transport, and the mailer
     * sending it. Set before each send so that a transport failure can be
     * attributed to the right message.
     */
    private ?MessageInterface $pendingMessage = null;
    private ?BaseMailer $pendingMailer = null;

    private static ?ReflectionProperty $transportProperty = null;

    /**
     * Handle BaseMailer::EVENT_BEFORE_SEND.
     *
     * Remembers the message about to be sent and makes sure the mailer's
     * transport is wrapped so that failures (which never reach
     * EVENT_AFTER_SEND) can be logged.
     */
    public function handleBeforeSend(BaseMailer $mailer, MessageInterface $message): void
    {
        $this->pendingMessage = $message;
        $this->pendingMailer = $mailer;

        if (MailLog::$plugin->getSettings()->logFailures) {
            $this->wrapTransport($mailer);
        }
    }

    /**
     * Handle BaseMailer::EVENT_AFTER_SEND.
     */
    public function handleAfterSend(BaseMailer $mailer, MessageInterface $message, bool $isSuccessful): void
    {
        if ($this->pendingMessage === $message) {
            $this->clearPending();
        }

        $this->logSafely($message, $isSuccessful, null, $mailer);
    }

    /**
     * Handle a send that failed because the transport threw. Called by
     * FailureReportingTransport just before the exception propagates.
     */
    public function handleTransportFailure(Throwable $error): void
    {
        $message = $this->pendingMessage;
        $mailer = $this->pendingMailer;
        $this->clearPending();

        if ($message !== null) {
            $this->logSafely($message, false, $error, $mailer);
        }
    }

    /**
     * Persist a log entry for a single send.
     *
     * Returns true if the record was saved, false if the send was
     * filtered out by settings (e.g. failure logging disabled).
     *
     * @param MessageInterface $message The message that was sent
     * @param bool $isSuccessful Whether the send succeeded
     * @param Throwable|null $error The exception that caused the failure, if known
     * @param BaseMailer|null $mailer The mailer that sent the message. Defaults to the app mailer.
     */
    public function log(
        MessageInterface $message,
        bool $isSuccessful,
        ?Throwable $error = null,
        ?BaseMailer $mailer = null,
    ): bool {
        $settings = MailLog::$plugin->getSettings();

        if (!$isSuccessful && !$settings->logFailures) {
            return false;
        }

        $email = $this->getSymfonyEmail($message);

        $record = new MailLogRecord();
        $record->success = $isSuccessful;
        $record->subject = $this->truncate('subject', (string) $message->getSubject());

        $from = $this->extractAddresses($message, $email, 'from');
        $fromAddress = array_key_first($from);
        $record->fromAddress = $this->truncate('fromAddress', $fromAddress);
        $record->fromName = $this->truncate('fromName', $fromAddress !== null ? $from[$fromAddress] : null);

        $record->toAddresses = $this->extractAddresses($message, $email, 'to') ?: null;
        $record->ccAddresses = $this->extractAddresses($message, $email, 'cc') ?: null;
        $record->bccAddresses = $this->extractAddresses($message, $email, 'bcc') ?: null;
        $record->replyToAddresses = $this->extractAddresses($message, $email, 'replyTo') ?: null;

        // Craft system-message key (password reset, verify account, etc.)
        if ($message instanceof CraftMessage && !empty($message->key)) {
            $record->systemKey = $this->truncate('systemKey', $message->key);
        }

        if ($email !== null) {
            // Attachment filenames are always captured, even when bodies are off.
            $record->attachmentNames = $this->extractAttachmentNames($email) ?: null;

            if ($settings->logBodies && !$this->isRedacted($record->systemKey)) {
                $record->textBody = $this->bodyToString($email->getTextBody());
                $record->htmlBody = $this->bodyToString($email->getHtmlBody());
            }
        }

        if (!$isSuccessful) {
            $record->error = $this->formatError($error ?? $this->extractError($message));
        }

        $record->transportClass = $this->truncate('transportClass', $this->captureTransport($mailer));

        return $record->save(false);
    }

    /**
     * Whether bodies of messages with the given system key are withheld
     * because they carry single-use credentials (password reset links,
     * account activation links, etc.). Anyone able to read those bodies in
     * the CP could otherwise use them to take over the recipient's account.
     */
    public function isRedacted(?string $systemKey): bool
    {
        if ($systemKey === null || $systemKey === '') {
            return false;
        }
        $settings = MailLog::$plugin->getSettings();
        return $settings->redactSensitiveEmails
            && in_array($systemKey, $settings->sensitiveSystemKeys, true);
    }

    /**
     * Fetch log entries for the CP list.
     *
     * Only the columns needed by the list are selected; use getLogById()
     * for a fully populated record.
     *
     * @param int $pageSize Default number of entries per page
     * @param string|null $search Text to match against subject, sender, recipients and system key
     * @param bool|null $success Restrict to successful (true) or failed (false) sends
     * @return array{0: MailLogRecord[], 1: Pagination}
     */
    public function getLogs(int $pageSize = 50, ?string $search = null, ?bool $success = null): array
    {
        $query = MailLogRecord::find()
            ->select(self::LIST_COLUMNS)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);

        if ($success !== null) {
            $query->andWhere(['success' => $success]);
        }

        $search = $search !== null ? trim($search) : '';
        if ($search !== '') {
            // LIKE is case-sensitive on PostgreSQL.
            $operator = Craft::$app->getDb()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $condition = ['or'];
            foreach (self::SEARCH_COLUMNS as $column) {
                $condition[] = [$operator, $column, $search];
            }
            $query->andWhere($condition);
        }

        $pagination = new Pagination([
            'totalCount' => (int) (clone $query)->count(),
            'defaultPageSize' => $pageSize,
            'pageSizeLimit' => [10, 500],
        ]);
        /** @var MailLogRecord[] $records */
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

    /**
     * Delete entries older than the configured `retentionDays` setting.
     * Does nothing when retention is disabled (0).
     */
    public function pruneExpired(): int
    {
        return $this->deleteOlderThan(MailLog::$plugin->getSettings()->retentionDays);
    }

    /**
     * Delete entries created more than the given number of days ago.
     */
    public function deleteOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        // dateCreated is stored in UTC; prepareDateForDb() converts from the
        // system time zone accordingly.
        $cutoff = Db::prepareDateForDb(DateTimeHelper::now()->modify("-{$days} days"));

        $deleted = 0;
        do {
            $ids = MailLogRecord::find()
                ->select(['id'])
                ->where(['<', 'dateCreated', $cutoff])
                ->limit(self::PRUNE_BATCH_SIZE)
                ->column();
            if ($ids === []) {
                break;
            }
            $deleted += (int) MailLogRecord::deleteAll(['id' => $ids]);
        } while (count($ids) === self::PRUNE_BATCH_SIZE);

        return $deleted;
    }

    /**
     * Normalise an address value to [email => name|null] form. Yii mailers
     * accept strings, sequential arrays, and assoc arrays interchangeably.
     *
     * @return array<string, string|null>
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
                $normalised[$key] = is_string($value) && $value !== '' ? $value : null;
            }
        }
        return $normalised;
    }

    /**
     * Read addresses from the underlying Symfony email where possible,
     * falling back to the Yii wrapper. Symfony's Email always returns
     * Address[] with clean accessors, whereas the Yii getters return data in
     * a variety of shapes.
     *
     * @param 'from'|'to'|'cc'|'bcc'|'replyTo' $type
     * @return array<string, string|null>
     */
    protected function extractAddresses(MessageInterface $message, ?Email $email, string $type): array
    {
        if ($email !== null) {
            $addresses = match ($type) {
                'from' => $email->getFrom(),
                'to' => $email->getTo(),
                'cc' => $email->getCc(),
                'bcc' => $email->getBcc(),
                'replyTo' => $email->getReplyTo(),
            };
            $out = [];
            foreach ($addresses as $address) {
                $name = $address->getName();
                $out[$address->getAddress()] = $name !== '' ? $name : null;
            }
            return $out;
        }

        return $this->addressesToArray(match ($type) {
            'from' => $message->getFrom(),
            'to' => $message->getTo(),
            'cc' => $message->getCc(),
            'bcc' => $message->getBcc(),
            'replyTo' => $message->getReplyTo(),
        });
    }

    /**
     * @return string[]
     */
    protected function extractAttachmentNames(Email $email): array
    {
        $names = [];
        foreach ($email->getAttachments() as $attachment) {
            $names[] = $attachment->getFilename() ?: 'unnamed';
        }
        return $names;
    }

    /**
     * Look for an error left on the message by the mailer.
     */
    protected function extractError(MessageInterface $message): ?Throwable
    {
        return $message instanceof CraftMessage ? $message->error : null;
    }

    /**
     * Best-effort capture of the transport class used by the given mailer.
     */
    protected function captureTransport(?BaseMailer $mailer): ?string
    {
        try {
            $mailer ??= Craft::$app->getMailer();
            $transport = $this->getTransport($mailer);
            if ($transport instanceof FailureReportingTransport) {
                $transport = $transport->getInnerTransport();
            }
            return get_class($transport ?? $mailer);
        } catch (Throwable) {
            return null;
        }
    }

    private function clearPending(): void
    {
        $this->pendingMessage = null;
        $this->pendingMailer = null;
    }

    /**
     * Log a send, making sure that nothing thrown while doing so can
     * interfere with the mailer.
     */
    private function logSafely(MessageInterface $message, bool $isSuccessful, ?Throwable $error, ?BaseMailer $mailer): void
    {
        try {
            $this->log($message, $isSuccessful, $error, $mailer);
        } catch (Throwable $e) {
            Craft::error('Mail Log failed to persist an entry: ' . $e->getMessage(), 'mail-log');
        }
    }

    private function getSymfonyEmail(MessageInterface $message): ?Email
    {
        if (!$message instanceof MessageWrapperInterface) {
            return null;
        }
        try {
            return $message->getSymfonyEmail();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Read the transport off a Symfony-based Yii mailer. The mailer keeps it
     * in a private property with no public accessor.
     */
    private function getTransport(BaseMailer $mailer): ?TransportInterface
    {
        if (!$mailer instanceof SymfonyMailer) {
            return null;
        }
        self::$transportProperty ??= new ReflectionProperty(SymfonyMailer::class, '_transport');
        $transport = self::$transportProperty->getValue($mailer);
        return $transport instanceof TransportInterface ? $transport : null;
    }

    /**
     * Wrap the mailer's transport in a FailureReportingTransport, once.
     */
    private function wrapTransport(BaseMailer $mailer): void
    {
        try {
            $transport = $this->getTransport($mailer);
            if ($transport !== null && !$transport instanceof FailureReportingTransport) {
                /** @var SymfonyMailer $mailer */
                $mailer->setTransport(new FailureReportingTransport($transport, $this->handleTransportFailure(...)));
            }
        } catch (Throwable $e) {
            // Successful sends are still logged; only transport failures are missed.
            Craft::warning('Mail Log could not monitor the mail transport for failures: ' . $e->getMessage(), 'mail-log');
        }
    }

    /**
     * Normalise a Symfony body (string, stream resource or null) to a
     * non-empty string or null.
     */
    private function bodyToString(mixed $body): ?string
    {
        if (is_resource($body)) {
            if (stream_get_meta_data($body)['seekable']) {
                rewind($body);
            }
            $body = stream_get_contents($body);
        }
        return is_string($body) && $body !== '' ? $body : null;
    }

    private function formatError(?Throwable $error): ?string
    {
        if ($error === null) {
            return null;
        }
        $message = $error->getMessage() !== '' ? $error->getMessage() : get_class($error);
        return mb_strcut($message, 0, self::ERROR_MAX_BYTES);
    }

    private function truncate(string $column, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $length = self::COLUMN_LENGTHS[$column];
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
