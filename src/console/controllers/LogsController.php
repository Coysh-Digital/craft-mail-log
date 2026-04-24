<?php

namespace CoyshDigital\MailLog\console\controllers;

use CoyshDigital\MailLog\MailLog;
use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Manage mail log entries from the command line.
 *
 * php craft mail-log/logs/prune
 * php craft mail-log/logs/flush
 */
class LogsController extends Controller
{
    /**
     * Delete log entries older than the configured retention period.
     *
     * If Mail Log's `retentionDays` setting is 0 (the default) this is a no-op.
     * Schedule this from cron to automatically keep the log table trimmed.
     */
    public function actionPrune(): int
    {
        $days = MailLog::$plugin->getSettings()->retentionDays;
        if ($days <= 0) {
            $this->stdout("Retention is disabled (retentionDays = 0). Nothing pruned.\n");
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
