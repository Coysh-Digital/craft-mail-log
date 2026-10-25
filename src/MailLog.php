<?php

namespace CoyshDigital\MailLog;

use Craft;
use CoyshDigital\MailLog\models\Settings;
use CoyshDigital\MailLog\services\LogsService;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\mail\Mailer;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use yii\base\Event;
use yii\mail\MailEvent;

/**
 * Mail Log plugin.
 *
 * Listens for every email sent by Craft's mailer and persists a log entry
 * containing envelope data, bodies (optional), attachment filenames and
 * error details for failures.
 *
 * Successful sends are captured from craft\mail\Mailer::EVENT_AFTER_SEND.
 * Because this event is defined on yii\mail\BaseMailer and fired inside its
 * send() method, it is independent of whichever transport adapter the site
 * has configured (Sendmail, SMTP, Gmail, SES, etc.).
 *
 * Failed sends never reach EVENT_AFTER_SEND (the transport throws, and
 * Craft's mailer catches the exception and returns false), so they are
 * captured by wrapping the mailer's transport during EVENT_BEFORE_SEND.
 * See FailureReportingTransport.
 *
 * @property-read LogsService $logs
 * @method Settings getSettings()
 */
class MailLog extends Plugin
{
    /**
     * @var MailLog|null
     */
    public static ?MailLog $plugin = null;

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @inheritdoc
     */
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'logs' => LogsService::class,
        ]);

        $this->registerMailerListeners();
        $this->registerGarbageCollection();
        $this->registerPermissions();

        if (Craft::$app->request->getIsCpRequest()) {
            $this->registerCpRoutes();
        }
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        if (!Craft::$app->user->checkPermission('accessPlugin-mail-log')) {
            return null;
        }
        $item = parent::getCpNavItem();
        $label = $this->getSettings()->cpNavLabel;
        $item['label'] = $label !== '' ? $label : Craft::t('mail-log', 'Mail Log');
        return $item;
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('mail-log/settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * Hook the send events on Craft's mailer. The service never lets an
     * exception escape from these handlers, so logging can't break sending.
     */
    protected function registerMailerListeners(): void
    {
        Event::on(
            Mailer::class,
            Mailer::EVENT_BEFORE_SEND,
            function (MailEvent $event): void {
                /** @var Mailer $mailer */
                $mailer = $event->sender;
                $this->logs->handleBeforeSend($mailer, $event->message);
            }
        );

        Event::on(
            Mailer::class,
            Mailer::EVENT_AFTER_SEND,
            function (MailEvent $event): void {
                /** @var Mailer $mailer */
                $mailer = $event->sender;
                $this->logs->handleAfterSend($mailer, $event->message, $event->isSuccessful);
            }
        );
    }

    /**
     * Apply the retention setting whenever Craft runs garbage collection,
     * so old entries are removed even without a cron job.
     */
    protected function registerGarbageCollection(): void
    {
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function (): void {
                try {
                    $this->logs->pruneExpired();
                } catch (\Throwable $e) {
                    Craft::error('Mail Log failed to prune old entries: ' . $e->getMessage(), 'mail-log');
                }
            }
        );
    }

    /**
     * Register granular permissions. Craft auto-creates
     * accessPlugin-mail-log; we add view/delete on top.
     */
    protected function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('mail-log', 'Mail Log'),
                    'permissions' => [
                        'mailLog:delete' => [
                            'label' => Craft::t('mail-log', 'Delete mail log entries'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Register CP routes. POST action routes are resolved by Craft
     * automatically under /actions/mail-log/<controller>/<action>
     * so they don't need to be declared here.
     */
    protected function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $event->rules = array_merge($event->rules, [
                    'mail-log' => 'mail-log/logs/index',
                    'mail-log/view/<id:\d+>' => 'mail-log/logs/view',
                ]);
            }
        );
    }
}
