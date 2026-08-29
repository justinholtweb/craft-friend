<?php

namespace justinholtweb\friends\records;

use craft\db\ActiveRecord;
use justinholtweb\friends\db\Table;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property bool $enabled
 * @property int|null $sortOrder
 * @property string $action
 * @property int|null $statusCode
 * @property int $threshold
 * @property string|null $siteIds
 * @property string|null $conditions
 * @property string|null $sources
 * @property string|null $scoring
 */
class RuleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::RULES;
    }
}
