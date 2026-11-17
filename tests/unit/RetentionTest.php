<?php

namespace CoyshDigital\MailLog\tests\unit;

use CoyshDigital\MailLog\records\MailLogRecord;
use CoyshDigital\MailLog\services\LogsService;
use CoyshDigital\MailLog\tests\support\DatabaseTestCase;
use CoyshDigital\MailLog\tests\support\TestLogsService;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeZone;

class RetentionTest extends DatabaseTestCase
{
    private function service(array $settings = []): TestLogsService
    {
        return new TestLogsService($this->settings($settings));
    }

    public function testCutoffIsComputedInUtcRegardlessOfSystemTimeZone(): void
    {
        $service = $this->service();

        // 10:30 in London (BST, UTC+1) on 1 July is 09:30 UTC.
        $now = new DateTime('2026-07-01 10:30:00', new DateTimeZone('Europe/London'));
        self::assertSame('2026-06-01 09:30:00', $service->retentionCutoff(30, $now));

        // The same instant expressed in another zone gives the same cutoff.
        $sameInstant = new DateTime('2026-07-01 04:30:00', new DateTimeZone('America/Chicago'));
        self::assertSame('2026-06-01 09:30:00', $service->retentionCutoff(30, $sameInstant));
    }

    public function testCutoffIsExactElapsedTimeAcrossDaylightSavingChanges(): void
    {
        // Europe/London (the test app's time zone) left BST on 25 Oct 2026. Wall-clock
        // arithmetic would give a cutoff an hour away from 30 x 24 hours.
        $afterClocksWentBack = new DateTime('2026-11-17 15:58:30', new DateTimeZone('UTC'));
        self::assertSame('2026-10-18 15:58:30', $this->service()->retentionCutoff(30, $afterClocksWentBack));

        // ...and the same across the spring change (29 Mar 2026): 12 Apr 14:00 UTC minus 30 days.
        $afterClocksWentForward = new DateTime('2026-04-12 14:00:00', new DateTimeZone('UTC'));
        self::assertSame('2026-03-13 14:00:00', $this->service()->retentionCutoff(30, $afterClocksWentForward));

        // Same result when the reference time is expressed in the local zone.
        $local = new DateTime('2026-11-17 15:58:30', new DateTimeZone('Europe/London'));
        self::assertSame('2026-10-18 15:58:30', $this->service()->retentionCutoff(30, $local));
    }

    public function testCutoffDoesNotModifyTheReferenceTime(): void
    {
        $now = new DateTime('2026-07-01 10:30:00', new DateTimeZone('UTC'));
        $this->service()->retentionCutoff(30, $now);
        self::assertSame('2026-07-01 10:30:00', $now->format('Y-m-d H:i:s'));
    }

    public function testCutoffDefaultsToTheCurrentTime(): void
    {
        DateTimeHelper::pause(new DateTime('2026-07-01 12:00:00', new DateTimeZone('UTC')));
        self::assertSame('2026-06-24 12:00:00', $this->service()->retentionCutoff(7));
    }

    public function testDeletesOnlyEntriesOlderThanTheCutoff(): void
    {
        $old = $this->insertEntry(24 * 40);
        $alsoOld = $this->insertEntry(24 * 31);
        $recent = $this->insertEntry(24 * 29);
        $new = $this->insertEntry(1);

        $deleted = $this->service()->deleteOlderThan(30);

        self::assertSame(2, $deleted);
        self::assertNull(MailLogRecord::findOne($old));
        self::assertNull(MailLogRecord::findOne($alsoOld));
        self::assertNotNull(MailLogRecord::findOne($recent));
        self::assertNotNull(MailLogRecord::findOne($new));
    }

    public function testEntryExactlyAtTheCutoffIsKept(): void
    {
        DateTimeHelper::pause(new DateTime('2026-07-01 12:00:00', new DateTimeZone('UTC')));
        $atCutoff = $this->insertEntryAt('2026-06-01 12:00:00');
        $justBefore = $this->insertEntryAt('2026-06-01 11:59:59');

        $this->service()->deleteOlderThan(30);

        self::assertNotNull(MailLogRecord::findOne($atCutoff));
        self::assertNull(MailLogRecord::findOne($justBefore));
    }

    public function testZeroOrNegativeDaysDeletesNothing(): void
    {
        $this->insertEntry(24 * 400);
        $service = $this->service();

        self::assertSame(0, $service->deleteOlderThan(0));
        self::assertSame(0, $service->deleteOlderThan(-5));
        self::assertSame(1, $service->countAll());
    }

    public function testLargeTableIsDeletedInBatches(): void
    {
        // 2,500 entries older than 30 days and 500 within it.
        $this->insertMany(2500, 24 * 100);
        $this->insertMany(500, 24 * 10);
        $service = $this->service();

        $batches = [];
        $deleted = $service->deleteOlderThan(30, 100, null, function(int $inBatch, int $total) use (&$batches): void {
            $batches[] = [$inBatch, $total];
        });

        self::assertSame(2500, $deleted);
        self::assertCount(25, $batches);
        self::assertSame([100, 100], $batches[0]);
        self::assertSame([100, 2500], $batches[24]);
        self::assertSame(500, $service->countAll());
        self::assertSame(0, $service->countOlderThan(30));
    }

    public function testFinalPartialBatchIsReported(): void
    {
        $this->insertMany(250, 24 * 100);
        $batches = [];

        $this->service()->deleteOlderThan(30, 100, null, function(int $inBatch) use (&$batches): void {
            $batches[] = $inBatch;
        });

        self::assertSame([100, 100, 50], $batches);
    }

    public function testMaxRowsCapsAndALaterRunContinues(): void
    {
        $this->insertMany(1000, 24 * 100);
        $service = $this->service();

        self::assertSame(350, $service->deleteOlderThan(30, 100, 350));
        self::assertSame(650, $service->countOlderThan(30));

        // Resuming picks up what is left.
        self::assertSame(650, $service->deleteOlderThan(30, 100));
        self::assertSame(0, $service->countOlderThan(30));
    }

    public function testBatchSizeIsClamped(): void
    {
        $this->insertMany(5, 24 * 100);
        self::assertSame(5, $this->service()->deleteOlderThan(30, 0));
    }

    public function testCountingAndOldestDate(): void
    {
        $service = $this->service();
        self::assertSame(0, $service->countAll());
        self::assertNull($service->getOldestDate());

        $this->insertEntryAt('2026-01-02 03:04:05');
        $this->insertEntry(1);

        self::assertSame(2, $service->countAll());
        self::assertSame(1, $service->countOlderThan(30));
        self::assertSame('2026-01-02 03:04:05', $service->getOldestDate());
    }

    public function testPruneExpiredUsesTheRetentionSetting(): void
    {
        $this->insertEntry(24 * 40);
        $this->insertEntry(24 * 5);

        self::assertSame(1, $this->service(['retentionDays' => 30])->pruneExpired());
        self::assertSame(1, $this->service()->countAll());
    }

    public function testPruneExpiredDoesNothingWhenRetentionIsUnlimited(): void
    {
        $this->insertEntry(24 * 400);

        self::assertSame(0, $this->service(['retentionDays' => 0])->pruneExpired());
        self::assertSame(1, $this->service()->countAll());
    }

    public function testDefaultSettingsPruneAtThirtyDays(): void
    {
        $this->insertEntry(24 * 31);
        $this->insertEntry(24 * 29);

        // No overrides: a fresh install's defaults.
        self::assertSame(1, $this->service()->pruneExpired());
    }

    public function testGarbageCollectionRemovesExpiredEntries(): void
    {
        $this->insertEntry(24 * 40);
        $this->insertEntry(24 * 2);

        self::assertSame(1, $this->service(['retentionDays' => 30])->pruneForGarbageCollection());
        self::assertSame(1, $this->service()->countAll());
    }

    public function testGarbageCollectionIsCappedPerRun(): void
    {
        $this->insertMany(LogsService::GC_PRUNE_MAX_ROWS + 300, 24 * 100);
        $service = $this->service(['retentionDays' => 30]);

        self::assertSame(LogsService::GC_PRUNE_MAX_ROWS, $service->pruneForGarbageCollection());
        self::assertSame(300, $service->countOlderThan(30));

        // The next run works through the rest of the backlog.
        self::assertSame(300, $service->pruneForGarbageCollection());
    }

    public function testGarbageCollectionNeverThrows(): void
    {
        \Yii::$app->getDb()->createCommand()->dropTable(MailLogRecord::tableName())->execute();

        self::assertSame(0, $this->service(['retentionDays' => 30])->pruneForGarbageCollection());
    }

    public function testDeleteAllEmptiesTheTableAndReportsTheCount(): void
    {
        $this->insertMany(120, 200);
        $service = $this->service();

        self::assertSame(120, $service->deleteAll());
        self::assertSame(0, $service->countAll());

        // The table is still usable afterwards.
        $this->insertEntry(1);
        self::assertSame(1, $service->countAll());
    }
}
