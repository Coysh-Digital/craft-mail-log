<?php

namespace CoyshDigital\MailLog\tests\support;

use CoyshDigital\MailLog\migrations\Install;
use CoyshDigital\MailLog\models\Settings;
use CoyshDigital\MailLog\records\MailLogRecord;
use craft\db\Connection;
use craft\helpers\DateTimeHelper;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\base\Application;
use yii\console\Application as ConsoleApplication;
use yii\i18n\PhpMessageSource;

/**
 * Base class for tests that need a database.
 *
 * Boots a minimal Yii application with an in-memory SQLite database and
 * creates the plugin's table from its real Install migration, so the
 * service, record and console command run against genuine queries without
 * needing a Craft site or MySQL.
 */
abstract class DatabaseTestCase extends TestCase
{
    private ?Application $previousApp = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousApp = Yii::$app;
        $app = new ConsoleApplication([
            'id' => 'mail-log-tests',
            'basePath' => dirname(__DIR__),
            'timeZone' => 'Europe/London',
            'components' => [
                'db' => [
                    'class' => Connection::class,
                    // SQLite by default. Set MAIL_LOG_TEST_DSN (plus _USER and
                    // _PASSWORD) to run against a throwaway MySQL or PostgreSQL
                    // database instead; its mail_log_entries table is dropped
                    // and recreated for every test.
                    'dsn' => getenv('MAIL_LOG_TEST_DSN') ?: 'sqlite::memory:',
                    'username' => getenv('MAIL_LOG_TEST_USER') ?: null,
                    'password' => getenv('MAIL_LOG_TEST_PASSWORD') ?: null,
                ],
                'i18n' => [
                    'translations' => [
                        'mail-log' => ['class' => PhpMessageSource::class, 'basePath' => dirname(__DIR__, 2) . '/src/translations', 'sourceLanguage' => 'en'],
                    ],
                ],
            ],
        ]);

        // The Install migration is MySQL/PostgreSQL oriented; create the same
        // table (and indexes) with the real migration where the driver allows
        // it, which SQLite does.
        $db = $app->getDb();
        assert($db instanceof Connection);
        $db->createCommand('DROP TABLE IF EXISTS ' . $db->quoteTableName(MailLogRecord::tableName()))->execute();
        $migration = new Install();
        $migration->db = $db;
        ob_start();
        try {
            $migration->up();
        } finally {
            ob_end_clean();
        }
    }

    protected function tearDown(): void
    {
        DateTimeHelper::resume();
        // Restoring whatever was there before (normally nothing). Craft's stubs
        // type Yii::$app as a Craft application, which a test app isn't.
        /** @phpstan-ignore assign.propertyType */
        Yii::$app = $this->previousApp;
        parent::tearDown();
    }

    /**
     * Insert an entry created the given number of hours ago.
     *
     * @param array<string, mixed> $attributes Extra attributes
     */
    protected function insertEntry(int $hoursAgo, array $attributes = []): int
    {
        $created = (new \DateTime('now', new \DateTimeZone('UTC')))->modify("-{$hoursAgo} hours")->format('Y-m-d H:i:s');
        return $this->insertEntryAt($created, $attributes);
    }

    /**
     * Insert an entry with an explicit UTC `dateCreated`.
     *
     * @param array<string, mixed> $attributes Extra attributes
     */
    protected function insertEntryAt(string $dateCreated, array $attributes = []): int
    {
        $record = new MailLogRecord();
        $record->success = true;
        $record->subject = 'Test';
        $record->dateCreated = $dateCreated;
        $record->dateUpdated = $dateCreated;
        foreach ($attributes as $name => $value) {
            $record->$name = $value;
        }
        $record->save(false);
        return (int) $record->id;
    }

    /**
     * Insert many entries at once, for tests that need a large table. They
     * are created `$hoursAgo` hours ago (plus one second per entry, oldest
     * first), so they all fall on the same side of any whole-day cutoff.
     */
    protected function insertMany(int $count, int $hoursAgo): void
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $created = (clone $now)->modify("-{$hoursAgo} hours +{$i} seconds")->format('Y-m-d H:i:s');
            $rows[] = [1, 'Bulk ' . $i, $created, $created, \craft\helpers\StringHelper::UUID()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            Yii::$app->getDb()->createCommand()->batchInsert(
                MailLogRecord::tableName(),
                ['success', 'subject', 'dateCreated', 'dateUpdated', 'uid'],
                $chunk,
            )->execute();
        }
    }

    protected function settings(array $overrides = []): Settings
    {
        $settings = new Settings();
        foreach ($overrides as $name => $value) {
            $settings->$name = $value;
        }
        return $settings;
    }
}
