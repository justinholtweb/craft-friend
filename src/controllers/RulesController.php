<?php

namespace justinholtweb\friend\controllers;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use craft\web\assets\admintable\AdminTableAsset;
use justinholtweb\friend\models\Rule;
use justinholtweb\friend\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Managing the ordered rule set.
 */
class RulesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE_RULES);

        return true;
    }

    public function actionIndex(): Response
    {
        $rules = Plugin::getInstance()->getRules()->getAllRules();

        $tableData = [];

        foreach ($rules as $rule) {
            $tableData[] = [
                'id' => $rule->id,
                'title' => $rule->name,
                'url' => $rule->getCpEditUrl(),
                'action' => $rule->getActionLabel(),
                'threshold' => $rule->action === Rule::ACTION_IGNORE ? '—' : $rule->threshold . '%',
                'enabled' => $rule->enabled,
            ];
        }

        $this->getView()->registerAssetBundle(AdminTableAsset::class);
        $this->getView()->registerJs($this->_indexJs($tableData), View::POS_END);

        return $this->renderTemplate('friend/rules/_index', [
            'rules' => $rules,
            'newRuleUrl' => UrlHelper::cpUrl('friend/rules/new'),
        ]);
    }

    public function actionEdit(?int $ruleId = null, ?Rule $rule = null): Response
    {
        if ($rule === null) {
            if ($ruleId !== null) {
                $rule = Plugin::getInstance()->getRules()->getRuleById($ruleId);

                if ($rule === null) {
                    throw new NotFoundHttpException('Rule not found');
                }
            } else {
                $rule = new Rule();
            }
        }

        return $this->renderTemplate('friend/rules/_edit', [
            'rule' => $rule,
            'isNew' => !$rule->id,
            'title' => $rule->id ? (string)$rule->name : Craft::t('friend', 'New rule'),
            'elementTypeOptions' => $this->_options(Rule::elementTypeOptions()),
            'actionOptions' => $this->_options(Rule::actions()),
            'statusCodeOptions' => array_merge(
                [['value' => '', 'label' => Craft::t('friend', 'Use the plugin default')]],
                $this->_options(Rule::statusCodes())
            ),
            'methodOptions' => $this->_options(Rule::methodLabels()),
            'siteOptions' => array_map(
                static fn($site) => ['value' => $site->id, 'label' => $site->name],
                Craft::$app->getSites()->getAllSites()
            ),
            'sectionOptions' => array_map(
                static fn($section) => ['value' => $section->id, 'label' => $section->name],
                Craft::$app->getEntries()->getAllSections()
            ),
            'entryTypeOptions' => array_map(
                static fn($type) => ['value' => $type->id, 'label' => $type->name],
                Craft::$app->getEntries()->getAllEntryTypes()
            ),
            'categoryGroupOptions' => array_map(
                static fn($group) => ['value' => $group->id, 'label' => $group->name],
                Craft::$app->getCategories()->getAllGroups()
            ),
            'entryTypeClass' => Entry::class,
            'categoryTypeClass' => Category::class,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $service = Plugin::getInstance()->getRules();
        $id = $this->request->getBodyParam('id');
        $rule = $id ? $service->getRuleById((int)$id) : new Rule();

        if ($rule === null) {
            throw new NotFoundHttpException('Rule not found');
        }

        $rule->name = $this->request->getBodyParam('name');
        $rule->handle = $this->request->getBodyParam('handle') ?: StringHelper::toHandle((string)$rule->name);
        $rule->description = $this->request->getBodyParam('description');
        $rule->enabled = (bool)$this->request->getBodyParam('enabled', true);

        $rule->siteIds = $this->_ids($this->request->getBodyParam('siteIds'));
        $rule->uriPattern = $this->_string($this->request->getBodyParam('uriPattern'));
        $rule->excludePatterns = $this->_patterns($this->request->getBodyParam('excludePatterns'));
        $rule->minSegments = $this->_int($this->request->getBodyParam('minSegments'));
        $rule->maxSegments = $this->_int($this->request->getBodyParam('maxSegments'));

        $rule->elementType = (string)$this->request->getBodyParam('elementType', Entry::class);
        $rule->sectionIds = $this->_ids($this->request->getBodyParam('sectionIds'));
        $rule->entryTypeIds = $this->_ids($this->request->getBodyParam('entryTypeIds'));
        $rule->categoryGroupIds = $this->_ids($this->request->getBodyParam('categoryGroupIds'));
        $rule->uriPrefix = $this->_string($this->request->getBodyParam('uriPrefix'));
        $rule->enabledOnly = (bool)$this->request->getBodyParam('enabledOnly', true);

        $rule->methods = array_values((array)$this->request->getBodyParam('methods', []));
        $rule->weightSlug = $this->_int($this->request->getBodyParam('weightSlug')) ?? 0;
        $rule->weightTitle = $this->_int($this->request->getBodyParam('weightTitle')) ?? 0;
        $rule->weightPath = $this->_int($this->request->getBodyParam('weightPath')) ?? 0;
        $rule->threshold = $this->_int($this->request->getBodyParam('threshold')) ?? 55;
        $rule->candidateLimit = $this->_int($this->request->getBodyParam('candidateLimit'));

        // Posted as `ruleAction`, not `action`: `action` is how Craft is told which controller to
        // run, so a select named that would overwrite `friend/rules/save` with `redirect` and the
        // request would 404 on a route nobody wrote — before this method ever ran.
        $rule->action = (string)$this->request->getBodyParam('ruleAction', Rule::ACTION_REDIRECT);
        $rule->statusCode = $this->_int($this->request->getBodyParam('statusCode'));
        $rule->fallback = (string)$this->request->getBodyParam('fallback', Rule::FALLBACK_NONE);
        $rule->fallbackUrl = $this->_string($this->request->getBodyParam('fallbackUrl'));

        if (!$service->saveRule($rule)) {
            $this->setFailFlash(Craft::t('friend', 'Couldn’t save the rule.'));

            Craft::$app->getUrlManager()->setRouteParams(['rule' => $rule]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('friend', 'Rule saved.'));

        return $this->redirectToPostedUrl($rule);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)$this->request->getRequiredBodyParam('id');

        if (!Plugin::getInstance()->getRules()->deleteRuleById($id)) {
            return $this->asFailure(Craft::t('friend', 'Couldn’t delete the rule.'));
        }

        return $this->asSuccess(Craft::t('friend', 'Rule deleted.'));
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = Json::decode($this->request->getRequiredBodyParam('ids'));

        if (!Plugin::getInstance()->getRules()->reorderRules($ids)) {
            return $this->asFailure(Craft::t('friend', 'Couldn’t reorder the rules.'));
        }

        return $this->asSuccess(Craft::t('friend', 'Rules reordered.'));
    }

    // ------------------------------------------------------------------

    /**
     * Craft's number fields post an empty string when they are cleared, and an empty string
     * assigned to a typed `int` property is a TypeError rather than a zero — an author emptying a
     * field would fatal the save.
     */
    private function _int(mixed $value): ?int
    {
        if ($value === null || $value === '' || (is_array($value) && !$value)) {
            return null;
        }

        return (int)$value;
    }

    private function _string(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /**
     * @return int[]
     */
    private function _ids(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $value)));
    }

    /**
     * @return string[]
     */
    private function _patterns(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $patterns = [];

        foreach ($value as $row) {
            $pattern = is_array($row) ? ($row['pattern'] ?? reset($row)) : $row;

            if (is_string($pattern) && trim($pattern) !== '') {
                $patterns[] = trim($pattern);
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * @param array<int|string, string> $map
     * @return array<int, array{value: string, label: string}>
     */
    private function _options(array $map): array
    {
        $options = [];

        foreach ($map as $value => $label) {
            $options[] = ['value' => (string)$value, 'label' => $label];
        }

        return $options;
    }

    private function _indexJs(array $tableData): string
    {
        $data = Json::encode($tableData);
        $empty = Json::encode(Craft::t('friend', 'No rules yet.'));
        $actionLabel = Json::encode(Craft::t('friend', 'What it does'));
        $thresholdLabel = Json::encode(Craft::t('friend', 'Threshold'));
        $enabledLabel = Json::encode(Craft::t('app', 'Enabled'));
        $reorderOk = Json::encode(Craft::t('friend', 'Rules reordered.'));
        $reorderFail = Json::encode(Craft::t('friend', 'Couldn’t reorder the rules.'));

        return <<<JS
new Craft.VueAdminTable({
    columns: [
        { name: '__slot:title', title: Craft.t('app', 'Name') },
        { name: 'action', title: {$actionLabel} },
        { name: 'threshold', title: {$thresholdLabel} },
        {
            name: 'enabled',
            title: {$enabledLabel},
            callback: function(value) {
                return value ? '<span data-icon="check"></span>' : '<span class="light">—</span>';
            }
        }
    ],
    container: '#friend-rules',
    deleteAction: 'friend/rules/delete',
    emptyMessage: {$empty},
    padded: true,
    reorderAction: 'friend/rules/reorder',
    reorderSuccessMessage: {$reorderOk},
    reorderFailMessage: {$reorderFail},
    tableData: {$data}
});
JS;
    }
}
