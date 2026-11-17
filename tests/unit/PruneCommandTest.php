<?php

namespace CoyshDigital\MailLog\tests\unit;

use CoyshDigital\MailLog\console\controllers\LogsController;
use CoyshDigital\MailLog\models\Settings;
use CoyshDigital\MailLog\services\LogsService;
use CoyshDigital\MailLog\tests\support\DatabaseTestCase;
use CoyshDigital\MailLog\tests\support\TestLogsService;
use Yii;
use yii\console\ExitCode;

class PruneCommandTest extends DatabaseTestCase
{
    private function command(array $settings = [], array $options = [], bool $confirm = true): object
    {
        $settingsModel = $this->settings($settings);
        $service = new TestLogsService($settingsModel);

        $controller = new class($service, $settingsModel, $confirm) extends LogsController {
            public string $out = '';
            public string $err = '';
            /** @var string[] */
            public array $prompts = [];

            public function __construct(private LogsService $service, private Settings $pluginSettings, private bool $answer)
            {
                parent::__construct('logs', Yii::$app);
                $this->interactive = false;
            }

            protected function logs(): LogsService
            {
                return $this->service;
            }

            protected function settings(): Settings
            {
                return $this->pluginSettings;
            }

            /** @return int */
            public function stdout($string)
            {
                $this->out .= $string;
                return strlen($string);
            }

            /** @return int */
            public function stderr($string)
            {
                $this->err .= $string;
                return strlen($string);
            }

            public function confirm($message, $default = false)
            {
                $this->prompts[] = $message;
                return $this->answer;
            }
        };

        foreach ($options as $name => $value) {
            $controller->$name = $value;
        }
        return $controller;
    }

    private function rowCount(): int
    {
        return (new TestLogsService($this->settings()))->countAll();
    }

    public function testDryRunReportsButDeletesNothing(): void
    {
        $this->insertMany(300, 24 * 100);
        $this->insertMany(40, 24 * 3);
        $command = $this->command(options: ['days' => 30, 'dryRun' => true]);

        self::assertSame(ExitCode::OK, $command->actionPrune());

        self::assertSame(340, $this->rowCount());
        self::assertStringContainsString('340 total, 300 older than the cutoff', $command->out);
        self::assertStringContainsString('Dry run: 300 entries would be deleted', $command->out);
        self::assertStringContainsString('Cutoff:', $command->out);
        self::assertStringContainsString('UTC', $command->out);
        self::assertStringContainsString('Oldest:', $command->out);
    }

    public function testPruneDeletesExpiredEntriesAndReportsProgress(): void
    {
        $this->insertMany(250, 24 * 100);
        $this->insertMany(40, 24 * 3);
        $command = $this->command(options: ['days' => 30, 'batchSize' => 100]);

        self::assertSame(ExitCode::OK, $command->actionPrune());

        self::assertSame(40, $this->rowCount());
        self::assertStringContainsString('Deleted 100 / 250', $command->out);
        self::assertStringContainsString('Deleted 200 / 250', $command->out);
        self::assertStringContainsString('Deleted 250 / 250', $command->out);
        self::assertMatchesRegularExpression('/Pruned 250 log entries older than 30 days in [\d.]+s/', $command->out);
        self::assertStringContainsString('--days option', $command->out);
    }

    public function testRetentionSettingIsUsedWhenNoDaysOptionIsGiven(): void
    {
        $this->insertMany(10, 24 * 100);
        $this->insertMany(5, 24 * 10);
        $command = $this->command(settings: ['retentionDays' => 14]);

        self::assertSame(ExitCode::OK, $command->actionPrune());

        self::assertSame(5, $this->rowCount());
        self::assertStringContainsString('Retention: 14 days (retentionDays setting)', $command->out);
        self::assertSame([], $command->prompts, 'no confirmation needed when running from the setting');
    }

    public function testDefaultRetentionAppliesWhenNothingIsConfigured(): void
    {
        $this->insertMany(10, 24 * 100);
        $command = $this->command();

        $command->actionPrune();

        self::assertSame(0, $this->rowCount());
        self::assertStringContainsString('Retention: 30 days', $command->out);
    }

    public function testUnlimitedRetentionIsANoOp(): void
    {
        $this->insertMany(10, 24 * 400);
        $command = $this->command(settings: ['retentionDays' => 0]);

        self::assertSame(ExitCode::OK, $command->actionPrune());

        self::assertSame(10, $this->rowCount());
        self::assertStringContainsString('Retention is disabled', $command->out);
    }

    public function testNegativeDaysIsRejected(): void
    {
        $this->insertMany(10, 24 * 400);
        $command = $this->command(options: ['days' => -3]);

        self::assertSame(ExitCode::USAGE, $command->actionPrune());

        self::assertSame(10, $this->rowCount());
        self::assertStringContainsString('--days must be 0 or greater', $command->err);
    }

    public function testInvalidBatchSizeIsRejected(): void
    {
        $this->insertMany(10, 24 * 400);

        foreach ([0, -1, LogsService::MAX_PRUNE_BATCH_SIZE + 1] as $size) {
            $command = $this->command(options: ['days' => 30, 'batchSize' => $size]);
            self::assertSame(ExitCode::USAGE, $command->actionPrune(), "batch size $size");
        }
        self::assertSame(10, $this->rowCount());
    }

    public function testVeryShortExplicitRetentionNeedsConfirmation(): void
    {
        $this->insertMany(20, 24 * 3);
        $declined = $this->command(options: ['days' => 1], confirm: false);

        self::assertSame(ExitCode::OK, $declined->actionPrune());

        self::assertCount(1, $declined->prompts);
        self::assertSame(20, $this->rowCount(), 'declining deletes nothing');
        self::assertStringContainsString('Cancelled', $declined->out);

        $accepted = $this->command(options: ['days' => 1], confirm: true);
        $accepted->actionPrune();
        self::assertSame(0, $this->rowCount());
    }

    public function testDryRunNeverPromptsEvenForShortRetention(): void
    {
        $this->insertMany(20, 24 * 3);
        $command = $this->command(options: ['days' => 1, 'dryRun' => true], confirm: false);

        $command->actionPrune();

        self::assertSame([], $command->prompts);
        self::assertSame(20, $this->rowCount());
    }

    public function testRecentEntriesAreNeverDeletedAtOrAboveTheThreshold(): void
    {
        $this->insertMany(20, 24 * 8);
        $command = $this->command(options: ['days' => 7], confirm: false);

        $command->actionPrune();

        self::assertSame([], $command->prompts);
        self::assertSame(0, $this->rowCount());
    }

    public function testNothingToPruneIsReported(): void
    {
        $this->insertMany(5, 24);
        $command = $this->command(options: ['days' => 30]);

        self::assertSame(ExitCode::OK, $command->actionPrune());
        self::assertStringContainsString('Nothing to prune', $command->out);
        self::assertSame(5, $this->rowCount());
    }

    public function testRunningAgainAfterAnInterruptionContinues(): void
    {
        $this->insertMany(300, 24 * 100);

        // Simulate an interrupted run: only part of the work happened.
        (new TestLogsService($this->settings()))->deleteOlderThan(30, 100, 100);
        self::assertSame(200, $this->rowCount());

        $command = $this->command(options: ['days' => 30]);
        self::assertSame(ExitCode::OK, $command->actionPrune());

        self::assertSame(0, $this->rowCount());
        self::assertStringContainsString('200 total, 200 older', $command->out);
    }

    public function testDatabaseErrorsAreReportedWithAResumeHint(): void
    {
        $this->insertMany(10, 24 * 100);
        $settings = $this->settings();
        $failing = new class($settings) extends TestLogsService {
            public function deleteOlderThan(int $days, int $batchSize = 500, ?int $maxRows = null, ?callable $onBatch = null): int
            {
                throw new \RuntimeException('Lock wait timeout exceeded');
            }
        };

        $controller = new class($failing, $settings) extends LogsController {
            public string $out = '';
            public string $err = '';

            public function __construct(private LogsService $service, private Settings $pluginSettings)
            {
                parent::__construct('logs', Yii::$app);
            }

            protected function logs(): LogsService
            {
                return $this->service;
            }

            protected function settings(): Settings
            {
                return $this->pluginSettings;
            }

            /** @return int */
            public function stdout($string)
            {
                $this->out .= $string;
                return strlen($string);
            }

            /** @return int */
            public function stderr($string)
            {
                $this->err .= $string;
                return strlen($string);
            }
        };
        $controller->days = 30;

        self::assertSame(ExitCode::UNSPECIFIED_ERROR, $controller->actionPrune());
        self::assertStringContainsString('Lock wait timeout exceeded', $controller->err);
        self::assertStringContainsString('safe to run the command again', $controller->err);
    }

    public function testFlushDeletesEverythingAfterConfirmation(): void
    {
        $this->insertMany(30, 24 * 2);

        $declined = $this->command(confirm: false);
        $declined->actionFlush();
        self::assertSame(30, $this->rowCount());

        $accepted = $this->command(confirm: true);
        $accepted->actionFlush();
        self::assertSame(0, $this->rowCount());
        self::assertStringContainsString('Deleted 30 log entries', $accepted->out);
    }

    public function testOptionsAreExposedOnlyOnPrune(): void
    {
        $command = $this->command();
        self::assertContains('dryRun', $command->options('prune'));
        self::assertContains('batchSize', $command->options('prune'));
        self::assertContains('days', $command->options('prune'));
        self::assertNotContains('dryRun', $command->options('flush'));
    }
}
