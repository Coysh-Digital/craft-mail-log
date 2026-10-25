<?php

namespace CoyshDigital\MailLog\controllers;

use CoyshDigital\MailLog\helpers\EmailPreview;
use CoyshDigital\MailLog\MailLog;
use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class LogsController extends Controller
{
    /**
     * Status filter values accepted by the list view.
     */
    private const STATUS_FILTERS = ['sent' => true, 'failed' => false];

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        // Let Craft enforce login first, so guests are sent to the login
        // page rather than shown a 403.
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-mail-log');
        return true;
    }

    /**
     * Log list view.
     */
    public function actionIndex(): Response
    {
        $search = $this->stringQueryParam('search');
        $status = $this->stringQueryParam('status');
        if (!array_key_exists($status, self::STATUS_FILTERS)) {
            $status = '';
        }

        [$logs, $pagination] = MailLog::$plugin->logs->getLogs(
            search: $search,
            success: self::STATUS_FILTERS[$status] ?? null,
        );

        // The search form submits via GET, which drops any query string in
        // its action URL (e.g. the path param when pretty URLs are off), so
        // pass those params through as hidden inputs.
        $indexUrl = UrlHelper::cpUrl('mail-log');
        parse_str((string) parse_url($indexUrl, PHP_URL_QUERY), $indexUrlParams);

        return $this->renderTemplate('mail-log/index', [
            'logs' => $logs,
            'pagination' => $pagination,
            'search' => $search,
            'status' => $status,
            'indexUrl' => strtok($indexUrl, '?'),
            'indexUrlParams' => $indexUrlParams,
            'canDelete' => Craft::$app->getUser()->checkPermission('mailLog:delete'),
        ]);
    }

    /**
     * Log detail view.
     */
    public function actionView(int $id): Response
    {
        $logs = MailLog::$plugin->logs;
        $log = $logs->getLogById($id);
        if (!$log) {
            throw new NotFoundHttpException('Mail log entry not found.');
        }

        // Bodies of sensitive system emails logged before redaction was
        // enabled are hidden too.
        $redacted = $logs->isRedacted($log->systemKey);
        $loadRemote = (bool) $this->request->getQueryParam('remote');
        $previewHtml = null;
        if (!$redacted && $log->htmlBody !== null && $log->htmlBody !== '') {
            $previewHtml = $loadRemote ? $log->htmlBody : EmailPreview::blockRemoteContent($log->htmlBody);
        }

        return $this->renderTemplate('mail-log/view', [
            'log' => $log,
            'redacted' => $redacted,
            'previewHtml' => $previewHtml,
            'loadRemote' => $loadRemote,
            'canDelete' => Craft::$app->getUser()->checkPermission('mailLog:delete'),
        ]);
    }

    /**
     * Delete a single entry.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('mailLog:delete');

        $logs = MailLog::$plugin->logs;
        $id = (int) $this->request->getRequiredBodyParam('id');
        $log = $logs->getLogById($id);

        if ($log && $logs->delete($log)) {
            $this->setSuccessFlash(Craft::t('mail-log', 'Log entry deleted.'));
        } else {
            $this->setFailFlash(Craft::t('mail-log', 'Couldn’t delete the log entry.'));
        }

        return $this->redirectToPostedUrl(null, 'mail-log');
    }

    /**
     * Delete every entry.
     */
    public function actionFlush(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('mailLog:delete');

        $count = MailLog::$plugin->logs->deleteAll();
        $this->setSuccessFlash(
            Craft::t('mail-log', '{count, number} {count, plural, one{entry} other{entries}} deleted.', ['count' => $count])
        );
        return $this->redirect('mail-log');
    }

    /**
     * Read a scalar query param as a trimmed string, ignoring arrays.
     */
    private function stringQueryParam(string $name): string
    {
        $value = $this->request->getQueryParam($name);
        return is_string($value) ? trim($value) : '';
    }
}
