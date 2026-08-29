<?php

namespace justinholtweb\friend\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\friend\db\Table;
use justinholtweb\friend\helpers\Uris;
use justinholtweb\friend\models\Pin;
use justinholtweb\friend\Plugin;
use justinholtweb\friend\records\PinRecord;
use Throwable;

/**
 * Stored exact mappings, consulted before any rule.
 */
class Pins extends Component
{
    /**
     * The unique key for a pin or a log row.
     *
     * A URI can be longer than any index key either database will take — and a scanner will send
     * one that is. Hashing the site and the URI together gives a fixed-width key that behaves the
     * same on MySQL and Postgres, with no prefix lengths and no truncation.
     */
    public static function hash(?int $siteId, string $uri): string
    {
        return sha1(($siteId ?? 0) . '|' . Uris::normalize($uri));
    }

    /**
     * The pin covering this URI on this site, if there is one.
     *
     * A site-specific pin beats a site-agnostic one, so a multi-site install can pin the same path
     * two different ways. Both possibilities are fetched in one query, because this runs on every
     * 404 and a second round trip to find nothing is a second round trip.
     */
    public function find(int $siteId, string $uri): ?Pin
    {
        $uri = Uris::normalize($uri);

        $rows = $this->_query()
            ->andWhere([
                'uriHash' => [self::hash($siteId, $uri), self::hash(null, $uri)],
                'enabled' => true,
            ])
            ->all();

        if (!$rows) {
            return null;
        }

        usort($rows, static fn(array $a, array $b) => ($b['siteId'] !== null ? 1 : 0) <=> ($a['siteId'] !== null ? 1 : 0));

        return new Pin($rows[0]);
    }

    /**
     * @return Pin[]
     */
    public function getAllPins(?int $siteId = null): array
    {
        $query = $this->_query()->orderBy(['uri' => SORT_ASC]);

        if ($siteId !== null) {
            $query->andWhere(['or', ['siteId' => $siteId], ['siteId' => null]]);
        }

        return array_map(static fn(array $row) => new Pin($row), $query->all());
    }

    public function getPinById(int $id): ?Pin
    {
        $row = $this->_query()->andWhere(['id' => $id])->one();

        return $row ? new Pin($row) : null;
    }

    public function savePin(Pin $pin, bool $runValidation = true): bool
    {
        if ($runValidation && !$pin->validate()) {
            return false;
        }

        $record = $pin->id ? PinRecord::findOne($pin->id) : new PinRecord();

        if ($record === null) {
            return false;
        }

        if (!$pin->id) {
            $record->uid = StringHelper::UUID();
        }

        $record->siteId = $pin->siteId;
        $record->uri = $pin->uri;
        $record->uriHash = self::hash($pin->siteId, $pin->uri);
        $record->enabled = $pin->enabled;
        $record->targetType = $pin->targetType;
        $record->elementId = $pin->targetType === Pin::TARGET_ELEMENT ? $pin->elementId : null;
        $record->url = $pin->targetType === Pin::TARGET_URL ? $pin->url : null;
        $record->statusCode = $pin->statusCode;

        if (!$record->save(false)) {
            return false;
        }

        $pin->id = (int)$record->id;
        $pin->uid = $record->uid;

        Plugin::getInstance()->getMatcher()->clearCaches();

        return true;
    }

    public function deletePinById(int $id): bool
    {
        try {
            PinRecord::findOne($id)?->delete();
        } catch (Throwable $e) {
            Craft::error("Could not delete pin $id: " . $e->getMessage(), Plugin::LOG_CATEGORY);
            return false;
        }

        Plugin::getInstance()->getMatcher()->clearCaches();

        return true;
    }

    /**
     * Whether an identical pin already exists, so the CP can offer to update rather than collide.
     */
    public function findByUri(?int $siteId, string $uri): ?Pin
    {
        $row = $this->_query()->andWhere(['uriHash' => self::hash($siteId, $uri)])->one();

        return $row ? new Pin($row) : null;
    }

    public function recordHit(Pin $pin): void
    {
        if (!$pin->id) {
            return;
        }

        try {
            Craft::$app->getDb()->createCommand()->update(
                Table::PINS,
                [
                    'hits' => (int)$pin->hits + 1,
                    'dateLastHit' => Db::prepareDateForDb(new DateTime()),
                ],
                ['id' => $pin->id]
            )->execute();
        } catch (Throwable $e) {
            // A counter is never worth failing a redirect for.
            Craft::warning('Could not count a pin hit: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    private function _query(): Query
    {
        return (new Query())
            ->select([
                'id', 'siteId', 'uri', 'enabled', 'targetType', 'elementId', 'url', 'statusCode',
                'hits', 'dateLastHit', 'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from(Table::PINS);
    }
}
