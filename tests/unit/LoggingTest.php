<?php

namespace CoyshDigital\MailLog\tests\unit;

use CoyshDigital\MailLog\helpers\BodyCodec;
use CoyshDigital\MailLog\records\MailLogRecord;
use CoyshDigital\MailLog\tests\support\DatabaseTestCase;
use CoyshDigital\MailLog\tests\support\TestLogsService;
use craft\mail\Message;
use RuntimeException;

class LoggingTest extends DatabaseTestCase
{
    private function service(array $settings = []): TestLogsService
    {
        return new TestLogsService($this->settings($settings));
    }

    private function html(): string
    {
        return '<html><body>' . str_repeat('<p style="color:#333">Thanks for your order.</p>', 300) . '</body></html>';
    }

    private function message(?string $key = null, ?string $html = null): Message
    {
        $message = new Message();
        $message->setSubject('Your order');
        $message->setFrom(['shop@example.com' => 'The Shop']);
        $message->setTo(['customer@example.com' => 'A Customer']);
        $message->setTextBody('Thanks for your order.');
        $message->setHtmlBody($html ?? $this->html());
        $message->key = $key;
        return $message;
    }

    private function lastRecord(): MailLogRecord
    {
        $record = MailLogRecord::find()->orderBy(['id' => SORT_DESC])->one();
        self::assertInstanceOf(MailLogRecord::class, $record);
        return $record;
    }

    public function testSuccessfulSendIsLogged(): void
    {
        self::assertTrue($this->service()->log($this->message(), true));

        $record = $this->lastRecord();
        self::assertTrue((bool) $record->success);
        self::assertSame('Your order', $record->subject);
        self::assertSame('shop@example.com', $record->fromAddress);
        self::assertSame('The Shop', $record->fromName);
        self::assertSame(['customer@example.com' => 'A Customer'], $record->toAddresses);
        self::assertNull($record->ccAddresses);
        self::assertNull($record->error);
    }

    public function testBodiesAreStoredCompressedAndReadBackIntact(): void
    {
        $html = $this->html();
        $this->service()->log($this->message(null, $html), true);

        $record = $this->lastRecord();
        $rawHtml = (string) $record->getAttribute('htmlBody');

        self::assertStringStartsWith(BodyCodec::PREFIX, $rawHtml, 'stored compressed');
        self::assertLessThan(strlen($html) / 4, strlen($rawHtml));
        self::assertSame($html, $record->htmlBody, 'decoded on read');
        self::assertSame('Thanks for your order.', $record->textBody, 'short bodies are left alone');
    }

    public function testCompressionCanBeDisabled(): void
    {
        $html = $this->html();
        $this->service(['compressBodies' => false])->log($this->message(null, $html), true);

        $record = $this->lastRecord();
        self::assertSame($html, $record->getAttribute('htmlBody'));
        self::assertSame($html, $record->htmlBody);
    }

    public function testBodiesAreNotStoredWhenBodyLoggingIsOff(): void
    {
        $this->service(['logBodies' => false])->log($this->message(), true);

        $record = $this->lastRecord();
        self::assertNull($record->htmlBody);
        self::assertNull($record->textBody);
        self::assertSame('Your order', $record->subject, 'the envelope is still logged');
    }

    public function testBodiesOfSensitiveSystemEmailsAreNeverStored(): void
    {
        foreach (['forgot_password', 'account_activation', 'verify_new_email'] as $key) {
            $this->service()->log($this->message($key, '<a href="https://x.test/reset?token=SECRET">Reset</a>'), true);

            $record = $this->lastRecord();
            self::assertSame($key, $record->systemKey);
            self::assertNull($record->getAttribute('htmlBody'), "$key html");
            self::assertNull($record->getAttribute('textBody'), "$key text");
            self::assertSame('customer@example.com', array_key_first($record->toAddresses), "$key envelope is kept");
        }
    }

    public function testRedactionHoldsWhetherOrNotCompressionIsOn(): void
    {
        foreach ([true, false] as $compress) {
            $this->service(['compressBodies' => $compress])->log($this->message('forgot_password'), true);
            self::assertNull($this->lastRecord()->getAttribute('htmlBody'));
        }
    }

    public function testRedactionCoversCustomKeysAndCanBeSwitchedOff(): void
    {
        $custom = $this->service(['sensitiveSystemKeys' => ['magic_link']]);
        self::assertTrue($custom->isRedacted('magic_link'));
        self::assertFalse($custom->isRedacted('forgot_password'), 'the list replaces the default');
        self::assertFalse($custom->isRedacted('order_receipt'));
        self::assertFalse($custom->isRedacted(null));
        self::assertFalse($custom->isRedacted(''));

        $off = $this->service(['redactSensitiveEmails' => false]);
        self::assertFalse($off->isRedacted('forgot_password'));
        $off->log($this->message('forgot_password'), true);
        self::assertNotNull($this->lastRecord()->getAttribute('htmlBody'));
    }

    public function testOrdinarySystemEmailsKeepTheirBodies(): void
    {
        $this->service()->log($this->message('order_receipt'), true);
        self::assertNotNull($this->lastRecord()->htmlBody);
    }

    public function testEntriesStoredBeforeCompressionExistedStillRead(): void
    {
        $id = $this->insertEntry(48, ['htmlBody' => '<p>Old row</p>', 'textBody' => 'Old row']);

        $record = MailLogRecord::findOne($id);
        self::assertSame('<p>Old row</p>', $record->htmlBody);
        self::assertSame('Old row', $record->textBody);
    }

    public function testCompressedAndUncompressedEntriesCoexist(): void
    {
        $legacy = $this->insertEntry(48, ['htmlBody' => $this->html()]);
        $this->service()->log($this->message(), true);

        self::assertSame($this->html(), MailLogRecord::findOne($legacy)->htmlBody);
        self::assertSame($this->html(), $this->lastRecord()->htmlBody);
    }

    public function testFailureIsLoggedWithItsError(): void
    {
        self::assertTrue($this->service()->log($this->message(), false, new RuntimeException('Connection refused')));

        $record = $this->lastRecord();
        self::assertFalse((bool) $record->success);
        self::assertSame('Connection refused', $record->error);
    }

    public function testFailuresAreSkippedWhenFailureLoggingIsOff(): void
    {
        self::assertFalse($this->service(['logFailures' => false])->log($this->message(), false, new RuntimeException('x')));
        self::assertSame(0, $this->service()->countAll());
    }

    public function testLoggingErrorsNeverEscapeToTheMailer(): void
    {
        \Yii::$app->getDb()->createCommand()->dropTable(MailLogRecord::tableName())->execute();

        // handleAfterSend is what Craft's mailer event calls; it must swallow DB errors.
        $this->service()->handleAfterSend(new \craft\mail\Mailer(), $this->message(), true);
        $this->addToAssertionCount(1);
    }

    public function testOverlongSubjectIsTruncatedRatherThanLost(): void
    {
        $message = $this->message();
        $message->setSubject(str_repeat('x', 800));
        self::assertTrue($this->service()->log($message, true));

        self::assertSame(500, mb_strlen((string) $this->lastRecord()->subject));
    }
}
