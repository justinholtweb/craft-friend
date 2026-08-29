<?php

namespace justinholtweb\friend\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\friend\models\Pin;
use justinholtweb\friend\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The 404 log, and turning a good guess into a permanent answer.
 */
class LogController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $log = Plugin::getInstance()->getLog();

        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $perPage = 50;

        $options = [
            'search' => trim((string)$this->request->getQueryParam('search', '')),
            'status' => $this->request->getQueryParam('status'),
            'siteId' => $this->request->getQueryParam('siteId'),
            'sort' => $this->request->getQueryParam('sort', 'dateLastHit'),
            'direction' => $this->request->getQueryParam('direction', 'desc'),
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];

        $total = $log->getTotal($options);

        return $this->renderTemplate('friend/log/_index', [
            'entries' => $log->getEntries($options),
            'summary' => $log->getSummary(),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => (int)ceil($total / $perPage),
            'options' => $options,
            'canPin' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_PINS),
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        Plugin::getInstance()->getLog()->deleteById((int)$this->request->getRequiredBodyParam('id'));

        return $this->asSuccess(Craft::t('friend', 'Entry deleted.'));
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $deleted = Plugin::getInstance()->getLog()->clear();

        $this->setSuccessFlash(Craft::t('friend', '{count} entries cleared.', ['count' => $deleted]));

        return $this->redirectToPostedUrl();
    }

    /**
     * Promote a log row to a pin.
     *
     * The whole point of watching the log: once the engine has picked the right target for a URL
     * a few hundred times, an admin can make that answer permanent and stop paying for the guess.
     */
    public function actionPin(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_PINS);

        $entry = Plugin::getInstance()->getLog()->getEntryById((int)$this->request->getRequiredBodyParam('id'));

        if ($entry === null) {
            throw new NotFoundHttpException('Log entry not found');
        }

        if (!$entry->targetElementId && !$entry->targetUrl) {
            $this->setFailFlash(Craft::t('friend', 'That entry has no target to pin.'));

            return $this->redirectToPostedUrl();
        }

        $pins = Plugin::getInstance()->getPins();
        $pin = $pins->findByUri($entry->siteId, $entry->uri) ?? new Pin([
            'siteId' => $entry->siteId,
            'uri' => $entry->uri,
        ]);

        if ($entry->targetElementId) {
            $pin->targetType = Pin::TARGET_ELEMENT;
            $pin->elementId = $entry->targetElementId;
        } else {
            $pin->targetType = Pin::TARGET_URL;
            $pin->url = $entry->targetUrl;
        }

        if (!$pins->savePin($pin)) {
            $this->setFailFlash(Craft::t('friend', 'Couldn’t pin that: {errors}', [
                'errors' => implode(' ', $pin->getFirstErrors()),
            ]));

            return $this->redirectToPostedUrl();
        }

        $this->setSuccessFlash(Craft::t('friend', 'Pinned.'));

        return $this->redirectToPostedUrl();
    }
}
