<?php

namespace justinholtweb\friend\records;

use craft\db\ActiveRecord;
use justinholtweb\friend\db\Table;

/**
 * @property int $id
 * @property int|null $siteId
 * @property string $uri
 * @property bool $enabled
 * @property string $targetType
 * @property int|null $elementId
 * @property string|null $url
 * @property int|null $statusCode
 * @property int $hits
 * @property string|null $dateLastHit
 */
class PinRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::PINS;
    }
}
