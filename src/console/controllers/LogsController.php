<?php

namespace CoyshDigital\MailLog\console\controllers;

use CoyshDigital\MailLog\MailLog;
use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Manage mail log entries from the command line.
 *
 * php craft mail-log/logs/prune [--days=N]
 * php craft mail-log/logs/flush
 */
class LogsController extends Controller
{
    /**
     * @var int|null Number of days of entries to keep, overriding the `retentionDays` setting.
     */
    public ?int $days = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'prune') {
            $options[] = 'days';
        }
        return $options;
    }

    /**
     * Delete log entries older than the configured retention period.
     *
     * If Mail Log's `retentionDays` setting is 0 (the default) and no
     * `--days` option is given, this is a no-op. Expired entries are also
     * removed during Craft's garbage collection.
     */
    public function actionPrune(): int
    {
        $days = $this->days ?? MailLog::$plugin->getSettings()->retentionDays;
        if ($days <= 0) {
            $this->stdout("Retention is disabled (0 days). Nothing pruned.\n");
            return ExitCode::OK;
        }
        $deleted = MailLog::$plugin->logs->deleteOlderThan($days);
        $this->stdout("Pruned {$deleted} log entries older than {$days} days.\n");
        return ExitCode::OK;
    }

    /**
     * Delete every mail log entry.
     */
    public function actionFlush(): int
    {
        if (!$this->confirm('This will delete ALL mail log entries. Continue?')) {
            return ExitCode::OK;
        }
        $deleted = MailLog::$plugin->logs->deleteAll();
        $this->stdout("Deleted {$deleted} log entries.\n");
        return ExitCode::OK;
    }
}
