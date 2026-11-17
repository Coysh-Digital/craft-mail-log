<?php

namespace CoyshDigital\MailLog\tests\support;

use CoyshDigital\MailLog\models\Settings;
use CoyshDigital\MailLog\services\LogsService;

/**
 * A LogsService with injectable settings, so it can run without a booted
 * plugin.
 */
class TestLogsService extends LogsService
{
    public function __construct(public Settings $testSettings)
    {
        parent::__construct();
    }

    protected function settings(): Settings
    {
        return $this->testSettings;
    }
}
