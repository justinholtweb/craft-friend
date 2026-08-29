<?php

namespace justinholtweb\friend\records;

use craft\db\ActiveRecord;
use justinholtweb\friend\db\Table;

/**
 * @property int $id
 * @property int|null $siteId
 * @property string $uri
 * @property string|null $referrer
 * @property int $hits
 * @property int $statusCode
 * @property int|null $ruleId
 * @property string|null $ruleName
 * @property int|null $targetElementId
 * @property string|null $targetUrl
 * @property float|null $score
 * @property string|null $source
 * @property string $dateFirstHit
 * @property string $dateLastHit
 */
class LogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LOG;
    }
}
