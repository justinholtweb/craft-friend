<?php

namespace justinholtweb\friend\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use craft\web\UploadedFile;
use justinholtweb\friend\helpers\Csv;
use justinholtweb\friend\models\Pin;
use justinholtweb\friend\models\PinImport;
use justinholtweb\friend\models\Rule;
use justinholtweb\friend\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Stored exact mappings.
 */
class PinsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE_PINS);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('friend/pins/_index', [
            'pins' => Plugin::getInstance()->getPins()->getAllPins(),
        ]);
    }

    public function actionEdit(?int $pinId = null, ?Pin $pin = null): Response
    {
        if ($pin === null) {
            if ($pinId !== null) {
                $pin = Plugin::getInstance()->getPins()->getPinById($pinId);

                if ($pin === null) {
                    throw new NotFoundHttpException('Pin not found');
                }
            } else {
                $pin = new Pin(['uri' => (string)$this->request->getQueryParam('uri', '')]);
            }
        }

        $statusCodeOptions = [['value' => '', 'label' => Craft::t('friend', 'Use the plugin default')]];

        foreach (Rule::statusCodes() as $value => $label) {
            $statusCodeOptions[] = ['value' => (string)$value, 'label' => $label];
        }

        $siteOptions = [['value' => '', 'label' => Craft::t('friend', 'All sites')]];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteOptions[] = ['value' => (string)$site->id, 'label' => $site->name];
        }

        return $this->renderTemplate('friend/pins/_edit', [
            'pin' => $pin,
            'isNew' => !$pin->id,
            'title' => $pin->id ? $pin->uri : Craft::t('friend', 'New pin'),
            'statusCodeOptions' => $statusCodeOptions,
            'siteOptions' => $siteOptions,
            'elements' => $pin->getElement() ? [$pin->getElement()] : [],
            'elementType' => Entry::class,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $pins = Plugin::getInstance()->getPins();
        $id = $this->request->getBodyParam('id');
        $pin = $id ? $pins->getPinById((int)$id) : new Pin();

        if ($pin === null) {
            throw new NotFoundHttpException('Pin not found');
        }

        $siteId = $this->request->getBodyParam('siteId');
        $statusCode = $this->request->getBodyParam('statusCode');

        $pin->siteId = $siteId === '' || $siteId === null ? null : (int)$siteId;
        $pin->uri = (string)$this->request->getBodyParam('uri', '');
        $pin->enabled = (bool)$this->request->getBodyParam('enabled', true);
        $pin->targetType = (string)$this->request->getBodyParam('targetType', Pin::TARGET_ELEMENT);
        $pin->statusCode = $statusCode === '' || $statusCode === null ? null : (int)$statusCode;

        $elementIds = $this->request->getBodyParam('elementId');
        $pin->elementId = is_array($elementIds) ? ((int)($elementIds[0] ?? 0) ?: null) : ($elementIds ? (int)$elementIds : null);
        $pin->url = $this->request->getBodyParam('url') ?: null;

        if (!$pins->savePin($pin)) {
            $this->setFailFlash(Craft::t('friend', 'Couldn’t save the pin.'));

            Craft::$app->getUrlManager()->setRouteParams(['pin' => $pin]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('friend', 'Pin saved.'));

        return $this->redirectToPostedUrl($pin);
    }

    public function actionDelete(): Response
    {
        // Posted by a plain form on the pins index, so no requireAcceptsJson() — asSuccess()
        // redirects to the posted URL with a flash when the request is not JSON.
        $this->requirePostRequest();

        Plugin::getInstance()->getPins()->deletePinById((int)$this->request->getRequiredBodyParam('id'));

        return $this->asSuccess(Craft::t('friend', 'Pin deleted.'));
    }

    /**
     * The import form, and — after an upload — what the import did.
     */
    public function actionImport(?PinImport $result = null): Response
    {
        $siteOptions = [['value' => '', 'label' => Craft::t('friend', 'All sites')]];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteOptions[] = ['value' => (string)$site->id, 'label' => $site->name];
        }

        return $this->renderTemplate('friend/pins/_import', [
            'result' => $result,
            'siteOptions' => $siteOptions,
            'maxKb' => intdiv(Csv::MAX_BYTES, 1024),
            'maxRows' => Csv::MAX_ROWS,
            'canAllowExternal' => Craft::$app->getUser()->getIsAdmin(),
        ]);
    }

    /**
     * Run an uploaded CSV through the importer.
     *
     * The file is read from PHP's upload temp file and never stored. Only an admin may let a file
     * point pins at other hosts — for anyone else every destination has to be on this site.
     */
    public function actionUpload(): Response
    {
        $this->requirePostRequest();

        $file = UploadedFile::getInstanceByName('file');

        if ($file === null || $file->getHasError()) {
            throw new BadRequestHttpException('No CSV file was uploaded.');
        }

        $result = new PinImport(['dryRun' => (bool)$this->request->getBodyParam('dryRun')]);

        if ($file->size > Csv::MAX_BYTES) {
            $result->fileError = Craft::t('friend', 'The file is larger than {kb} KB.', ['kb' => intdiv(Csv::MAX_BYTES, 1024)]);
        } else {
            $siteId = $this->request->getBodyParam('siteId');

            $result = Plugin::getInstance()->getPinTransfer()->import($file->tempName, [
                'siteId' => $siteId === '' || $siteId === null ? null : (int)$siteId,
                'update' => (bool)$this->request->getBodyParam('update'),
                'dryRun' => $result->dryRun,
                'linkElements' => (bool)$this->request->getBodyParam('linkElements', true),
                'allowExternal' => Craft::$app->getUser()->getIsAdmin() && $this->request->getBodyParam('allowExternal'),
            ]);
        }

        if ($result->fileError !== null) {
            $this->setFailFlash($result->fileError);
        } elseif ($result->dryRun) {
            $this->setSuccessFlash(Craft::t('friend', 'Dry run — nothing was saved.'));
        } else {
            $this->setSuccessFlash(Craft::t('friend', 'Pins imported.'));
        }

        return $this->actionImport($result);
    }

    /**
     * Download every pin as CSV.
     */
    public function actionExport(): Response
    {
        $siteId = $this->request->getQueryParam('siteId');
        $csv = Plugin::getInstance()->getPinTransfer()->export($siteId ? (int)$siteId : null);

        return $this->response->sendContentAsFile($csv, 'friend-pins-' . date('Y-m-d') . '.csv', [
            'mimeType' => 'text/csv',
        ]);
    }
}
