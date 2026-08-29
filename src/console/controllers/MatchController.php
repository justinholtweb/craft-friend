<?php

namespace justinholtweb\friend\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\friend\models\Miss;
use justinholtweb\friend\models\Rule;
use justinholtweb\friend\models\Trace;
use justinholtweb\friend\Plugin;
use yii\console\ExitCode;

/**
 * `php craft friend/match/test <uri>` — the tester, without a browser.
 */
class MatchController extends Controller
{
    /** Site handle to resolve against. Defaults to the primary site. */
    public ?string $site = null;

    /** Show every candidate, not just the ones that would be offered. */
    public bool $all = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['site', 'all']);
    }

    /**
     * Resolve a URI as though it had just 404'd, and print the reasoning.
     */
    public function actionTest(string $uri): int
    {
        $sites = Craft::$app->getSites();
        $site = $this->site ? $sites->getSiteByHandle($this->site) : $sites->getPrimarySite();

        if ($site === null) {
            $this->stderr("No site with the handle '{$this->site}'.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $plugin = Plugin::getInstance();
        $matcher = $plugin->getMatcher();
        $miss = Miss::fromUri($uri, $site->id);

        $this->stdout("\n");
        $this->stdout('  ' . $miss->uri . "\n", Console::BOLD);
        $this->stdout('  site: ' . $site->name . '   slug: ' . ($miss->slug ?: '—') . '   tokens: ' . (implode(', ', $miss->tokens) ?: '—') . "\n\n", Console::FG_GREY);

        if (!$matcher->uriIsEligible($miss->uri)) {
            $this->stdout("  Blocked by a guard — an ignored pattern or file extension. No rule is consulted.\n\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if ($plugin->getSettings()->honourConfigRedirects && $matcher->hasConfigRedirects()) {
            $this->stdout("  Note: config/redirects.php exists and wins over Friend. Its rules match against\n"
                . "  the live request, so they cannot be evaluated for a hypothetical URI here.\n\n", Console::FG_YELLOW);
        }

        $outcome = $matcher->resolve($miss, true);

        foreach ($outcome->traces as $trace) {
            $colour = match ($trace->status) {
                Trace::MATCHED, Trace::SUGGESTED => Console::FG_GREEN,
                Trace::IGNORED => Console::FG_YELLOW,
                default => Console::FG_GREY,
            };

            $score = $trace->bestScore !== null ? sprintf(' (best %.1f)', $trace->bestScore) : '';
            $this->stdout('  ' . str_pad($trace->status, 17), $colour);
            $this->stdout($trace->ruleName . $score . ' — ' . $trace->reason . "\n");
        }

        $this->stdout("\n");

        $candidates = $this->all ? $outcome->candidates : array_slice($outcome->candidates, 0, 8);

        if ($candidates) {
            $this->stdout("  Candidates\n", Console::BOLD);

            foreach ($candidates as $candidate) {
                $this->stdout(sprintf(
                    "    %5.1f  %-45s  slug %.2f  title %.2f  path %.2f  [%s]\n",
                    $candidate->score,
                    mb_strimwidth('/' . $candidate->uri, 0, 45, '…'),
                    $candidate->breakdown['slug'] ?? 0,
                    $candidate->breakdown['title'] ?? 0,
                    $candidate->breakdown['path'] ?? 0,
                    implode(',', $candidate->methods)
                ));
            }

            $this->stdout("\n");
        }

        if ($outcome->shouldRedirect()) {
            $this->stdout("  → {$outcome->statusCode} {$outcome->targetUrl}\n\n", Console::FG_GREEN, Console::BOLD);
        } elseif ($outcome->action === Rule::ACTION_SUGGEST) {
            $this->stdout("  → stays 404, with " . count($outcome->candidates) . " suggestion(s) for the template\n\n", Console::FG_CYAN);
        } elseif ($outcome->action === Rule::ACTION_IGNORE) {
            $this->stdout("  → stays 404 — a rule matched and is set to do nothing\n\n", Console::FG_YELLOW);
        } else {
            $this->stdout("  → stays 404 — no rule decided\n\n", Console::FG_GREY);
        }

        return ExitCode::OK;
    }

    /**
     * List the rule set in evaluation order.
     */
    public function actionRules(): int
    {
        $rules = Plugin::getInstance()->getRules()->getAllRules();

        if (!$rules) {
            $this->stdout("No rules.\n");

            return ExitCode::OK;
        }

        $this->stdout("\n");

        foreach ($rules as $rule) {
            $this->stdout(sprintf('  %-3s ', $rule->sortOrder), Console::FG_GREY);
            $this->stdout(str_pad((string)$rule->name, 30), $rule->enabled ? Console::BOLD : Console::FG_GREY);
            $this->stdout(sprintf(
                "%-10s  threshold %d  %s\n",
                $rule->action,
                $rule->threshold,
                implode(',', $rule->methods)
            ), $rule->enabled ? null : Console::FG_GREY);
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }
}
