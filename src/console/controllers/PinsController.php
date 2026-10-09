<?php

namespace justinholtweb\friend\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\friend\Plugin;
use yii\console\ExitCode;

/**
 * `php craft friend/pins/…` — pins in and out of CSV.
 */
class PinsController extends Controller
{
    /**
     * @var string|null Site handle: the site for rows without a `site` column (import), or the site to export. Default: all sites.
     */
    public ?string $site = null;

    /**
     * @var bool Overwrite a pin that already exists for the same site and URI.
     */
    public bool $update = false;

    /**
     * @var bool Check every row and report, without saving anything.
     */
    public bool $dryRun = false;

    /**
     * @var bool Pin the entry when a destination is an entry's URI, so the redirect follows it.
     */
    public bool $linkElements = true;

    /**
     * @var bool Accept destinations on other hosts. Off by default: an imported pin is a redirect.
     */
    public bool $allowExternal = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'import' => array_merge($options, ['site', 'update', 'dryRun', 'linkElements', 'allowExternal']),
            'export' => array_merge($options, ['site']),
            default => $options,
        };
    }

    /**
     * Import pins from a CSV file — `from,to[,status,site,enabled]`, or Retour/Redirect Manager headers.
     */
    public function actionImport(string $file): int
    {
        $siteId = null;

        if ($this->site !== null) {
            $site = Craft::$app->getSites()->getSiteByHandle($this->site);

            if ($site === null) {
                $this->stderr("No site with the handle '{$this->site}'.\n", Console::FG_RED);

                return ExitCode::USAGE;
            }

            $siteId = $site->id;
        }

        $result = Plugin::getInstance()->getPinTransfer()->import($file, [
            'siteId' => $siteId,
            'update' => $this->update,
            'dryRun' => $this->dryRun,
            'linkElements' => $this->linkElements,
            'allowExternal' => $this->allowExternal,
        ]);

        if ($result->fileError !== null) {
            $this->stderr($result->fileError . "\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->stdout("\n");

        if ($result->dryRun) {
            $this->stdout("  Dry run — nothing was saved.\n\n", Console::FG_YELLOW);
        }

        $this->stdout(sprintf(
            "  %d rows: %d created, %d updated, %d already pinned and left alone, %d refused\n",
            $result->rows,
            $result->created,
            $result->updated,
            $result->skipped,
            count($result->errors)
        ));

        if ($result->linked) {
            $this->stdout("  {$result->linked} point at an entry and will follow it.\n", Console::FG_GREY);
        }

        if ($result->errors) {
            $this->stdout("\n");

            foreach ($result->errors as $line => $message) {
                $this->stdout(sprintf('  line %-6d ', $line), Console::FG_GREY);
                $this->stdout($message . "\n", Console::FG_RED);
            }
        }

        $this->stdout("\n");

        return $result->errors ? ExitCode::DATAERR : ExitCode::OK;
    }

    /**
     * Export pins as CSV, to a file or to standard output.
     */
    public function actionExport(?string $file = null): int
    {
        $siteId = null;

        if ($this->site !== null) {
            $site = Craft::$app->getSites()->getSiteByHandle($this->site);

            if ($site === null) {
                $this->stderr("No site with the handle '{$this->site}'.\n", Console::FG_RED);

                return ExitCode::USAGE;
            }

            $siteId = $site->id;
        }

        $csv = Plugin::getInstance()->getPinTransfer()->export($siteId);

        if ($file === null || $file === '-') {
            $this->stdout($csv);

            return ExitCode::OK;
        }

        if (file_put_contents($file, $csv) === false) {
            $this->stderr("Couldn't write $file.\n", Console::FG_RED);

            return ExitCode::CANTCREAT;
        }

        $this->stdout('Exported ' . max(0, substr_count($csv, "\n") - 1) . " pins to $file.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
