<?php

namespace CoyshDigital\MailLog\tests\unit;

use CoyshDigital\MailLog\models\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    public function testDefaultsKeepThirtyDaysAndCompressBodies(): void
    {
        $settings = new Settings();

        self::assertSame(30, $settings->retentionDays);
        self::assertTrue($settings->compressBodies);
        self::assertTrue($settings->logBodies);
        self::assertTrue($settings->redactSensitiveEmails);
        self::assertTrue($settings->validate());
    }

    public function testExplicitRetentionOverridesTheDefault(): void
    {
        // This is how Craft applies stored project config and config/mail-log.php.
        $settings = new Settings();
        $settings->setAttributes(['retentionDays' => 90], false);
        self::assertSame(90, $settings->retentionDays);
    }

    public function testExplicitZeroStillMeansKeepForever(): void
    {
        // Installs that saved 0 before the default changed must stay unlimited.
        $settings = new Settings();
        $settings->setAttributes(['retentionDays' => 0], false);

        self::assertSame(0, $settings->retentionDays);
        self::assertTrue($settings->validate());
    }

    public function testNegativeRetentionIsInvalid(): void
    {
        $settings = new Settings();
        $settings->retentionDays = -1;

        self::assertFalse($settings->validate(['retentionDays']));
    }

    public function testUnboundedBodyStorageWarning(): void
    {
        $settings = new Settings();
        self::assertFalse($settings->hasUnboundedBodyStorage(), 'default is bounded');

        $settings->retentionDays = 0;
        self::assertTrue($settings->hasUnboundedBodyStorage(), 'forever + bodies');

        $settings->logBodies = false;
        self::assertFalse($settings->hasUnboundedBodyStorage(), 'forever without bodies is fine');
    }
}
