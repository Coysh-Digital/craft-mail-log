<?php

namespace CoyshDigital\MailLog\controllers;

use CoyshDigital\MailLog\MailLog;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class LogsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requirePermission('accessPlugin-mail-log');
        return parent::beforeAction($action);
    }

    /**
     * Log list view.
     */
    public function actionIndex(): Response
    {
        [$logs, $pagination] = MailLog::$plugin->logs->getLogs();
        return $this->renderTemplate('mail-log/index', [
            'logs' => $logs,
            'pagination' => $pagination,
            'canDelete' => Craft::$app->user->checkPermission('mailLog:delete'),
        ]);
    }

    /**
     * Log detail view.
     */
    public function actionView(int $id): Response
    {
        $log = MailLog::$plugin->logs->getLogById($id);
        if (!$log) {
            throw new NotFoundHttpException('Mail log entry not found.');
        }
        return $this->renderTemplate('mail-log/view', [
            'log' => $log,
            'canDelete' => Craft::$app->user->checkPermission('mailLog:delete'),
        ]);
    }

    /**
     * Delete a single entry.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('mailLog:delete');

        $id = (int) Craft::$app->request->getRequiredBodyParam('id');
        $log = MailLog::$plugin->logs->getLogById($id);
        if ($log) {
            MailLog::$plugin->logs->delete($log);
        }

        Craft::$app->session->setNotice(Craft::t('mail-log', 'Log entry deleted.'));
        return $this->redirect('mail-log');
    }

    /**
     * Delete every entry.
     */
    public function actionFlush(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('mailLog:delete');

        $count = MailLog::$plugin->logs->deleteAll();
        Craft::$app->session->setNotice(
            Craft::t('mail-log', '{count, number} {count, plural, one{entry} other{entries}} deleted.', ['count' => $count])
        );
        return $this->redirect('mail-log');
    }
}
