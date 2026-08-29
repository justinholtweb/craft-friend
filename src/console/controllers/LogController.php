<?php

namespace justinholtweb\friends\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\friends\Plugin;
use yii\console\ExitCode;

/**
 * `php craft friends/log/…`
 */
class LogController extends Controller
{
    /** How many rows to show. */
    public int $limit = 25;

    /** Only rows Friends did not resolve. */
    public bool $unresolved = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit', 'unresolved']);
    }

    /**
     * Show the busiest 404s.
     */
    public function actionIndex(): int
    {
        $log = Plugin::getInstance()->getLog();
        $summary = $log->getSummary();

        $entries = $log->getEntries([
            'limit' => $this->limit,
            'sort' => 'hits',
            'direction' => 'desc',
            'status' => $this->unresolved ? 'unresolved' : null,
        ]);

        $this->stdout("\n");
        $this->stdout(sprintf(
            "  %d URIs, %d hits — %d redirected, %d unresolved\n\n",
            $summary['total'],
            $summary['hits'],
            $summary['redirected'],
            $summary['unresolved']
        ), Console::FG_GREY);

        foreach ($entries as $entry) {
            $this->stdout(sprintf('  %6d  ', $entry->hits), Console::FG_GREY);
            $this->stdout(str_pad(mb_strimwidth('/' . $entry->uri, 0, 45, '…'), 46));
            $this->stdout(
                $entry->wasRedirected()
                    ? sprintf('%d → %s', $entry->statusCode, $entry->targetUrl)
                    : '404',
                $entry->wasRedirected() ? Console::FG_GREEN : Console::FG_GREY
            );
            $this->stdout("\n");
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Apply the retention settings now instead of waiting for garbage collection.
     */
    public function actionPrune(): int
    {
        $deleted = Plugin::getInstance()->getLog()->prune();

        $this->stdout("Pruned $deleted log entries.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Empty the log.
     */
    public function actionClear(): int
    {
        if (!$this->confirm('Delete every log entry?')) {
            return ExitCode::OK;
        }

        $deleted = Plugin::getInstance()->getLog()->clear();

        $this->stdout("Deleted $deleted log entries.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
