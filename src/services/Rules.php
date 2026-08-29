<?php

namespace justinholtweb\friends\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\friends\db\Table;
use justinholtweb\friends\models\Rule;
use justinholtweb\friends\Plugin;
use justinholtweb\friends\records\RuleRecord;
use Throwable;

/**
 * The ordered rule set.
 */
class Rules extends Component
{
    /** @var Rule[]|null */
    private ?array $_rules = null;

    /**
     * @return Rule[] in evaluation order
     */
    public function getAllRules(): array
    {
        if ($this->_rules === null) {
            $this->_rules = array_map(
                static fn(array $row) => Rule::fromRow($row),
                $this->_query()->all()
            );
        }

        return $this->_rules;
    }

    /**
     * The rules that could act on a request to this site, in order.
     *
     * @return Rule[]
     */
    public function getEnabledRules(int $siteId): array
    {
        return array_values(array_filter(
            $this->getAllRules(),
            static fn(Rule $rule) => $rule->enabled && $rule->appliesToSite($siteId)
        ));
    }

    public function getRuleById(int $id): ?Rule
    {
        foreach ($this->getAllRules() as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        return null;
    }

    public function getRuleByHandle(string $handle): ?Rule
    {
        foreach ($this->getAllRules() as $rule) {
            if ($rule->handle === $handle) {
                return $rule;
            }
        }

        return null;
    }

    public function saveRule(Rule $rule, bool $runValidation = true): bool
    {
        $isNew = !$rule->id;

        if ($runValidation && !$rule->validate()) {
            return false;
        }

        $record = $isNew ? new RuleRecord() : RuleRecord::findOne($rule->id);

        if ($record === null) {
            return false;
        }

        if ($isNew) {
            $rule->uid = StringHelper::UUID();
            $record->uid = $rule->uid;

            // New rules land at the bottom. Anywhere else would silently reorder a set the admin
            // has already put in the order they meant.
            $rule->sortOrder ??= ((int)(new Query())->from(Table::RULES)->max('[[sortOrder]]')) + 1;
        }

        $record->name = $rule->name;
        $record->handle = $rule->handle;
        $record->description = $rule->description;
        $record->enabled = $rule->enabled;
        $record->sortOrder = $rule->sortOrder;
        $record->action = $rule->action;
        $record->statusCode = $rule->statusCode;
        $record->threshold = $rule->threshold;

        foreach ($rule->jsonBlocks() as $column => $json) {
            $record->$column = $json;
        }

        if (!$record->save(false)) {
            return false;
        }

        $rule->id = (int)$record->id;
        $rule->uid = $record->uid;

        $this->clearCaches();

        return true;
    }

    public function deleteRuleById(int $id): bool
    {
        $record = RuleRecord::findOne($id);

        if ($record === null) {
            return true;
        }

        try {
            $record->delete();
        } catch (Throwable $e) {
            Craft::error("Could not delete rule $id: " . $e->getMessage(), Plugin::LOG_CATEGORY);
            return false;
        }

        $this->clearCaches();

        return true;
    }

    /**
     * @param int[]|string[] $ids
     */
    public function reorderRules(array $ids): bool
    {
        $db = Craft::$app->getDb();

        foreach (array_values($ids) as $index => $id) {
            $db->createCommand()
                ->update(Table::RULES, ['sortOrder' => $index + 1], ['id' => (int)$id])
                ->execute();
        }

        $this->clearCaches();

        return true;
    }

    public function toggleRule(int $id, bool $enabled): bool
    {
        Craft::$app->getDb()->createCommand()
            ->update(Table::RULES, ['enabled' => $enabled, 'dateUpdated' => Db::prepareDateForDb(new \DateTime())], ['id' => $id])
            ->execute();

        $this->clearCaches();

        return true;
    }

    public function clearCaches(): void
    {
        $this->_rules = null;
        Plugin::getInstance()->getMatcher()->clearCaches();
    }

    private function _query(): Query
    {
        return (new Query())
            ->select([
                'id', 'name', 'handle', 'description', 'enabled', 'sortOrder', 'action',
                'statusCode', 'threshold', 'siteIds', 'conditions', 'sources', 'scoring',
                'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from(Table::RULES)
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC]);
    }
}
