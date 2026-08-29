<?php

namespace justinholtweb\friend\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use craft\helpers\StringHelper;
use craft\elements\Entry;
use justinholtweb\friend\db\Table;
use justinholtweb\friend\models\Rule;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();
        $this->seedRules();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::PINS);
        $this->dropTableIfExists(Table::RULES);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::RULES, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'description' => $this->text(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer(),

            // Denormalised out of the JSON blocks below so the index can show and sort on them
            // without decoding every row.
            'action' => $this->string(16)->notNull()->defaultValue(Rule::ACTION_REDIRECT),
            'statusCode' => $this->integer(),
            'threshold' => $this->integer()->notNull()->defaultValue(55),

            'siteIds' => $this->text(),
            'conditions' => $this->text(),
            'sources' => $this->text(),
            'scoring' => $this->text(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::PINS, [
            'id' => $this->primaryKey(),

            // Null means every site.
            'siteId' => $this->integer(),

            'uri' => $this->string(500)->notNull(),

            // A URI can be longer than any index key either database will take, and a scanner will
            // happily send one that is. Hashing the site and URI together gives a fixed-width
            // unique key that works the same on MySQL and Postgres, with no prefix lengths.
            'uriHash' => $this->char(40)->notNull(),

            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'targetType' => $this->string(16)->notNull()->defaultValue('element'),
            'elementId' => $this->integer(),
            'url' => $this->string(500),
            'statusCode' => $this->integer(),
            'hits' => $this->integer()->notNull()->defaultValue(0),
            'dateLastHit' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'uri' => $this->string(500)->notNull(),
            'uriHash' => $this->char(40)->notNull(),
            'referrer' => $this->string(500),
            'hits' => $this->integer()->notNull()->defaultValue(1),

            // 0 when the visitor was left on the 404.
            'statusCode' => $this->integer()->notNull()->defaultValue(0),

            'ruleId' => $this->integer(),

            // Kept even though `ruleId` has a foreign key: a deleted rule nulls the id, and a log
            // row that can no longer say which rule made the call is a log row nobody can act on.
            'ruleName' => $this->string(),

            'targetElementId' => $this->integer(),
            'targetUrl' => $this->string(500),
            'score' => $this->decimal(5, 1),
            'source' => $this->string(16),
            'dateFirstHit' => $this->dateTime()->notNull(),
            'dateLastHit' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::RULES, ['handle'], true);
        $this->createIndex(null, Table::RULES, ['enabled', 'sortOrder']);

        $this->createIndex(null, Table::PINS, ['uriHash'], true);
        $this->createIndex(null, Table::PINS, ['siteId']);
        $this->createIndex(null, Table::PINS, ['elementId']);

        $this->createIndex(null, Table::LOG, ['uriHash'], true);
        $this->createIndex(null, Table::LOG, ['siteId', 'dateLastHit']);
        $this->createIndex(null, Table::LOG, ['statusCode']);
        $this->createIndex(null, Table::LOG, ['hits']);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::PINS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::PINS, ['elementId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::LOG, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::LOG, ['ruleId'], Table::RULES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::LOG, ['targetElementId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);
    }

    /**
     * Two rules out of the box: one that works, and one switched off to read.
     *
     * A rules engine that installs empty is a rules engine whose first impression is a blank
     * screen and a manual. The enabled rule is the conservative version of what everybody wants —
     * entries, three retrieval methods, a threshold high enough that it declines more often than
     * it guesses.
     */
    private function seedRules(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $rules = [
            (new Rule([
                'name' => 'Similar entries',
                'handle' => 'similarEntries',
                'description' => 'Looks for the entry whose slug and title are closest to the missing URL.',
                'enabled' => true,
                'sortOrder' => 1,
                'elementType' => Entry::class,
                'methods' => [Rule::METHOD_SLUG, Rule::METHOD_TOKENS, Rule::METHOD_SEARCH],
                'threshold' => 55,
                'action' => Rule::ACTION_REDIRECT,
            ])),
            (new Rule([
                'name' => 'Nearest surviving page',
                'handle' => 'nearestPage',
                'description' => 'When nothing looks similar, walk up the path: /services/seo-audits → /services. Switched off until you have decided you want it.',
                'enabled' => false,
                'sortOrder' => 2,
                'elementType' => Entry::class,
                'methods' => [Rule::METHOD_ANCESTOR],
                'threshold' => 50,
                'action' => Rule::ACTION_REDIRECT,
            ])),
        ];

        foreach ($rules as $rule) {
            $this->insert(Table::RULES, array_merge([
                'name' => $rule->name,
                'handle' => $rule->handle,
                'description' => $rule->description,
                'enabled' => $rule->enabled,
                'sortOrder' => $rule->sortOrder,
                'action' => $rule->action,
                'statusCode' => null,
                'threshold' => $rule->threshold,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ], $rule->jsonBlocks()));
        }
    }
}
