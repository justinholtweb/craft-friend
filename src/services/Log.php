<?php

namespace justinholtweb\friend\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\friend\db\Table;
use justinholtweb\friend\models\LogEntry;
use justinholtweb\friend\models\Miss;
use justinholtweb\friend\models\Outcome;
use justinholtweb\friend\Plugin;
use Throwable;

/**
 * The 404 log — aggregated per site and URI, not appended per request.
 *
 * A hit counter answers "what is actually broken on this site" at a glance. A million-row request
 * log answers the same question after a query nobody ever writes, and costs a row per bot.
 */
class Log extends Component
{
    /** URIs and referrers are stored in `varchar(500)` columns; the hash carries the identity. */
    private const MAX_LENGTH = 500;

    public function record(Miss $miss, Outcome $outcome): void
    {
        if (!Plugin::getInstance()->getSettings()->logMisses) {
            return;
        }

        try {
            $this->_record($miss, $outcome);
        } catch (Throwable $e) {
            // Logging a 404 must never be able to turn it into a 500.
            Craft::warning('Could not record a miss: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    private function _record(Miss $miss, Outcome $outcome): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());
        $hash = Pins::hash($miss->siteId, $miss->uri);

        $values = [
            'statusCode' => $outcome->shouldRedirect() ? $outcome->statusCode : 0,
            'ruleId' => $outcome->rule?->id,
            'ruleName' => $outcome->rule?->name,
            'targetElementId' => $outcome->candidate?->elementId,
            'targetUrl' => $this->_truncate($outcome->targetUrl),
            'score' => $outcome->getScore(),
            'source' => $outcome->source,
            'referrer' => $this->_truncate($miss->referrer),
            'dateLastHit' => $now,
            'dateUpdated' => $now,
        ];

        // Read then write, rather than a native upsert with a `hits + 1` expression: the
        // expression forms differ between MySQL and Postgres, and losing one count to a race on a
        // 404 counter is a far cheaper bug than a query that only works on one database.
        $existing = (new Query())
            ->select(['id', 'hits'])
            ->from(Table::LOG)
            ->where(['uriHash' => $hash])
            ->one();

        if ($existing) {
            $values['hits'] = (int)$existing['hits'] + 1;
            $db->createCommand()->update(Table::LOG, $values, ['id' => $existing['id']])->execute();

            return;
        }

        $db->createCommand()->insert(Table::LOG, $values + [
            'siteId' => $miss->siteId,
            'uri' => $this->_truncate($miss->uri),
            'uriHash' => $hash,
            'hits' => 1,
            'dateFirstHit' => $now,
            'dateCreated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    /**
     * @return LogEntry[]
     */
    public function getEntries(array $options = []): array
    {
        $query = $this->_query();

        $this->_applyFilters($query, $options);

        $sort = $options['sort'] ?? 'dateLastHit';
        $direction = ($options['direction'] ?? 'desc') === 'asc' ? SORT_ASC : SORT_DESC;

        $query->orderBy([
            in_array($sort, ['hits', 'uri', 'score', 'dateFirstHit', 'dateLastHit'], true) ? $sort : 'dateLastHit' => $direction,
            'id' => SORT_DESC,
        ]);

        $query->limit($options['limit'] ?? 100);
        $query->offset($options['offset'] ?? 0);

        return array_map(static fn(array $row) => new LogEntry($row), $query->all());
    }

    public function getTotal(array $options = []): int
    {
        $query = $this->_query();
        $this->_applyFilters($query, $options);

        return (int)$query->count();
    }

    public function getEntryById(int $id): ?LogEntry
    {
        $row = $this->_query()->andWhere(['id' => $id])->one();

        return $row ? new LogEntry($row) : null;
    }

    /**
     * @return array{total: int, redirected: int, unresolved: int, hits: int}
     */
    public function getSummary(): array
    {
        $rows = (new Query())
            ->select(['statusCode', 'COUNT(*) as rows', 'SUM([[hits]]) as hits'])
            ->from(Table::LOG)
            ->groupBy(['statusCode'])
            ->all();

        $summary = ['total' => 0, 'redirected' => 0, 'unresolved' => 0, 'hits' => 0];

        foreach ($rows as $row) {
            // `COUNT()` and `SUM()` come back as strings from PDO, and a string reaching an int
            // property is a TypeError rather than a cast.
            $count = (int)$row['rows'];
            $summary['total'] += $count;
            $summary['hits'] += (int)$row['hits'];

            if ((int)$row['statusCode'] >= 300) {
                $summary['redirected'] += $count;
            } else {
                $summary['unresolved'] += $count;
            }
        }

        return $summary;
    }

    public function deleteById(int $id): bool
    {
        Craft::$app->getDb()->createCommand()->delete(Table::LOG, ['id' => $id])->execute();

        return true;
    }

    public function clear(): int
    {
        return Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    }

    /**
     * Trim the log by age and then by row count.
     *
     * Both, and in that order: retention says how long a 404 stays interesting, the cap says how
     * much of the database this plugin is allowed to be. A site that gets scanned hard hits the
     * cap long before anything ages out.
     */
    public function prune(): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $db = Craft::$app->getDb();
        $deleted = 0;

        if ($settings->logRetentionDays > 0) {
            $cutoff = (new DateTime())->modify("-{$settings->logRetentionDays} days");
            $deleted += $db->createCommand()
                ->delete(Table::LOG, ['<', 'dateLastHit', Db::prepareDateForDb($cutoff)])
                ->execute();
        }

        if ($settings->logMaxRows > 0) {
            $total = (int)(new Query())->from(Table::LOG)->count();
            $excess = $total - $settings->logMaxRows;

            if ($excess > 0) {
                $ids = (new Query())
                    ->select(['id'])
                    ->from(Table::LOG)
                    ->orderBy(['dateLastHit' => SORT_ASC, 'id' => SORT_ASC])
                    ->limit($excess)
                    ->column();

                if ($ids) {
                    $deleted += $db->createCommand()->delete(Table::LOG, ['id' => $ids])->execute();
                }
            }
        }

        return $deleted;
    }

    private function _applyFilters(Query $query, array $options): void
    {
        if (!empty($options['siteId'])) {
            $query->andWhere(['siteId' => (int)$options['siteId']]);
        }

        if (!empty($options['search'])) {
            $search = str_replace(['%', '_'], ['\%', '\_'], (string)$options['search']);
            $query->andWhere(['or',
                ['like', 'uri', $search],
                ['like', 'targetUrl', $search],
            ]);
        }

        $status = $options['status'] ?? null;

        if ($status === 'redirected') {
            $query->andWhere(['>=', 'statusCode', 300]);
        } elseif ($status === 'unresolved') {
            $query->andWhere(['<', 'statusCode', 300]);
        }
    }

    /**
     * Long URIs and long referrers are both routine — one from a scanner, one from an ad network
     * stapling its whole tracking payload to the path. The hash carries identity, so the stored
     * text is free to be a readable prefix.
     */
    private function _truncate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, self::MAX_LENGTH);
    }

    private function _query(): Query
    {
        return (new Query())
            ->select([
                'id', 'siteId', 'uri', 'referrer', 'hits', 'statusCode', 'ruleId', 'ruleName',
                'targetElementId', 'targetUrl', 'score', 'source', 'dateFirstHit', 'dateLastHit',
                'uid',
            ])
            ->from(Table::LOG);
    }
}
