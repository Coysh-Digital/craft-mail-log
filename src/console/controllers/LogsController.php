<?php

namespace CoyshDigital\MailLog\console\controllers;

use CoyshDigital\MailLog\MailLog;
use CoyshDigital\MailLog\models\Settings;
use CoyshDigital\MailLog\services\LogsService;
use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use Throwable;
use yii\console\ExitCode;

/**
 * Manage mail log entries from the command line.
 *
 * php craft mail-log/logs/prune [--days=N] [--dry-run] [--batch-size=N]
 * php craft mail-log/logs/flush
 */
class LogsController extends Controller
{
    /**
     * An explicit `--days` below this needs confirmation, to guard against
     * typos that would wipe recent history.
     */
    public const MIN_UNCONFIRMED_DAYS = 7;

    /**
     * @var int|null Number of days of entries to keep, overriding the `retentionDays` setting.
     */
    public ?int $days = null;

    /**
     * @var bool Report what would be deleted without deleting anything.
     */
    public bool $dryRun = false;

    /**
     * @var int Number of entries to delete per batch.
     */
    public int $batchSize = LogsService::DEFAULT_PRUNE_BATCH_SIZE;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'prune') {
            $options[] = 'days';
            $options[] = 'dryRun';
            $options[] = 'batchSize';
        }
        return $options;
    }

    /**
     * @inheritdoc
     */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'n' => 'dryRun',
        ]);
    }

    /**
     * Delete log entries older than the configured retention period.
     *
     * Entries are removed in small batches, each in its own transaction, so
     * the command is safe to interrupt (Ctrl+C) and to run again: it simply
     * carries on with what is left. Use --dry-run first on a large log to
     * see what would be deleted.
     *
     * If the `retentionDays` setting is 0 (keep forever) and no `--days`
     * option is given, nothing is deleted.
     */
    public function actionPrune(): int
    {
        $days = $this->days ?? $this->settings()->retentionDays;
        $explicit = $this->days !== null;

        if ($days < 0) {
            $this->stderr("--days must be 0 or greater.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        if ($this->batchSize < 1 || $this->batchSize > LogsService::MAX_PRUNE_BATCH_SIZE) {
            $this->stderr('--batch-size must be between 1 and ' . LogsService::MAX_PRUNE_BATCH_SIZE . ".\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        if ($days === 0) {
            $this->stdout("Retention is disabled (0 days). Nothing pruned.\n");
            return ExitCode::OK;
        }

        $logs = $this->logs();
        $cutoff = $logs->retentionCutoff($days);
        $source = $explicit ? '--days option' : 'retentionDays setting';
        $total = $logs->countAll();
        $eligible = $logs->countOlderThan($days);

        $this->stdout("Retention: {$days} days ({$source})\n");
        $this->stdout("Cutoff:    {$cutoff} UTC (" . $this->localTime($cutoff) . ")\n");
        $this->stdout('Entries:   ' . number_format($total) . ' total, ' . number_format($eligible) . " older than the cutoff\n");
        if ($eligible > 0) {
            $this->stdout('Oldest:     ' . $logs->getOldestDate() . " UTC\n");
        }

        if ($eligible === 0) {
            $this->stdout("Nothing to prune.\n");
            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $this->stdout('Dry run: ' . number_format($eligible) . ' entries would be deleted. Nothing was changed.' . "\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if ($explicit && $days < self::MIN_UNCONFIRMED_DAYS && !$this->confirm("This keeps only the last {$days} day(s) of entries and deletes " . number_format($eligible) . ' others. Continue?')) {
            $this->stdout("Cancelled. Nothing was deleted.\n");
            return ExitCode::OK;
        }

        $start = microtime(true);
        $deleted = 0;
        try {
            $deleted = $logs->deleteOlderThan(
                $days,
                $this->batchSize,
                null,
                function(int $inBatch, int $soFar) use ($eligible): void {
                    $this->stdout('  Deleted ' . number_format($soFar) . ' / ' . number_format($eligible) . "\n");
                },
            );
        } catch (Throwable $e) {
            $this->stderr('Prune failed: ' . $e->getMessage() . "\n", Console::FG_RED);
            $this->stderr('Entries already deleted stay deleted. It is safe to run the command again to continue.' . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $elapsed = round(microtime(true) - $start, 1);
        $this->stdout("Pruned {$deleted} log entries older than {$days} days in {$elapsed}s.\n", Console::FG_GREEN);
        $this->stdout("Note: this frees space inside the database, but MySQL/InnoDB doesn't return it to the operating system. See the README before rebuilding the table.\n");
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
        $deleted = $this->logs()->deleteAll();
        $this->stdout("Deleted {$deleted} log entries.\n");
        return ExitCode::OK;
    }

    protected function logs(): LogsService
    {
        return MailLog::$plugin->logs;
    }

    protected function settings(): Settings
    {
        return MailLog::$plugin->getSettings();
    }

    /**
     * Format a UTC database timestamp in the system time zone.
     */
    private function localTime(string $utc): string
    {
        $date = DateTimeHelper::toDateTime($utc, true);
        if ($date === false) {
            return $utc;
        }
        return $date->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()))->format('Y-m-d H:i:s T');
    }
}
