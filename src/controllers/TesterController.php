<?php

namespace justinholtweb\friends\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\friends\models\Miss;
use justinholtweb\friends\Plugin;
use yii\web\Response;

/**
 * Try a URL and see the whole reasoning.
 *
 * A fuzzy matcher nobody can interrogate is a fuzzy matcher nobody will switch on. This screen is
 * the difference between "it redirected somewhere odd" and "rule 2 scored it 58 on a threshold of
 * 55, mostly on title overlap, so raise the threshold or drop the title weight".
 */
class TesterController extends Controller
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
        $uri = trim((string)$this->request->getParam('uri', ''));
        $siteId = (int)$this->request->getParam('siteId', Craft::$app->getSites()->getPrimarySite()->id);

        $matcher = Plugin::getInstance()->getMatcher();

        $miss = null;
        $outcome = null;
        $eligible = true;
        $ineligibleReason = null;

        if ($uri !== '') {
            $miss = Miss::fromUri($uri, $siteId);

            if (!$matcher->uriIsEligible($miss->uri)) {
                $eligible = false;
                $ineligibleReason = Craft::t('friends', 'A guard stops this URI before any rule is consulted — it matches an ignored pattern, or has an ignored file extension.');
            } else {
                // Traced, which also means uncached — the point of this screen is what the rules
                // say *now*, not what they said an hour ago.
                $outcome = $matcher->resolve($miss, true);
            }
        }

        return $this->renderTemplate('friends/tester/_index', [
            'uri' => $uri,
            'siteId' => $siteId,
            'miss' => $miss,
            'outcome' => $outcome,
            'eligible' => $eligible,
            'ineligibleReason' => $ineligibleReason,
            'configRedirectsPresent' => Plugin::getInstance()->getSettings()->honourConfigRedirects
                && $matcher->hasConfigRedirects(),
            'siteOptions' => array_map(
                static fn($site) => ['value' => (string)$site->id, 'label' => $site->name],
                Craft::$app->getSites()->getAllSites()
            ),
        ]);
    }
}
