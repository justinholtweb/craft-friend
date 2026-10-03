<?php

namespace justinholtweb\friend\services;

use Craft;
use craft\base\Component;
use craft\web\Request as WebRequest;
use craft\web\RedirectRule;
use justinholtweb\friend\helpers\Uris;
use justinholtweb\friend\models\Candidate;
use justinholtweb\friend\models\Miss;
use justinholtweb\friend\models\Outcome;
use justinholtweb\friend\models\Pin;
use justinholtweb\friend\models\Rule;
use justinholtweb\friend\models\Trace;
use justinholtweb\friend\Plugin;
use Throwable;
use yii\web\HttpException;

/**
 * The decision: pins, then rules, then nothing.
 *
 * Entered from `craft\web\ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION`, which Craft fires at the
 * very top of `handleException()` — before it consults `config/redirects.php`, and long before it
 * renders the site's 404 template. That is the only place a redirect is genuinely free. By the
 * time a `Response` event could see the 404, the error template has already been rendered and is
 * about to be thrown away.
 */
class Matcher extends Component
{
    /** The outcome for the request in flight, so a 404 template can ask what happened. */
    private ?Outcome $_outcome = null;

    private ?int $_generation = null;

    // ------------------------------------------------------------------ entry point

    public function handleException(Throwable $exception): void
    {
        try {
            $this->_handle($exception);
        } catch (Throwable $e) {
            // Whatever goes wrong in here, the visitor is still owed their 404 and not a 500.
            Craft::error('Could not look for a friend: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    private function _handle(Throwable $exception): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->enabled) {
            return;
        }

        if (!$exception instanceof HttpException || $exception->statusCode !== 404) {
            return;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest || !$this->requestIsEligible($request)) {
            return;
        }

        $miss = Miss::fromRequest($request);

        if ($miss->uri === '' || !$this->uriIsEligible($miss->uri)) {
            return;
        }

        // Friend runs *first*, so an explicit `config/redirects.php` rule would otherwise never
        // get a look in. Explicit beats inferred: if that file already covers this URI, stand
        // down and let Craft do its own thing three lines further down its own method.
        if ($settings->honourConfigRedirects && $this->configRedirectCovers($miss->uri)) {
            return;
        }

        $outcome = $this->resolve($miss);
        $this->_outcome = $outcome;

        $plugin->getLog()->record($miss, $outcome);

        if (!$outcome->shouldRedirect()) {
            return;
        }

        Craft::$app->getResponse()->redirect($outcome->targetUrl, $outcome->statusCode);
        Craft::$app->end();
    }

    // ------------------------------------------------------------------ guards

    /**
     * Whether this request is one a redirect could possibly help.
     *
     * A 404 from an action request, a POST, the control panel or a preview is a 404 about
     * something other than a visitor following a stale link, and moving it somewhere else can only
     * turn a clear failure into a confusing one.
     */
    public function requestIsEligible(WebRequest $request): bool
    {
        if (!$request->getIsSiteRequest() || $request->getIsCpRequest() || $request->getIsActionRequest()) {
            return false;
        }

        if (!in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
            return false;
        }

        if (method_exists($request, 'getIsPreview') && $request->getIsPreview()) {
            return false;
        }

        return true;
    }

    public function uriIsEligible(string $uri): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $uri = Uris::normalize($uri);

        if ($uri === '') {
            return false;
        }

        $extension = Uris::extension($uri);

        if ($extension !== null && in_array($extension, $settings->ignoredExtensions, true)) {
            return false;
        }

        return !Uris::matchesAny($uri, $settings->ignoredPatterns);
    }

    /**
     * Whether `config/redirects.php` already answers for this URI.
     *
     * Reads the same file through the same rule objects Craft does, and asks the same question —
     * without acting on the answer, which is Craft's job.
     *
     * Only answerable about the URI actually being requested. `RedirectRule::getMatch()` reads
     * `Craft::$app->getRequest()` rather than taking a subject, so asking it about a *hypothetical*
     * path would silently match Craft's rules against the current request instead — which in the
     * tester means matching them against a control panel URL. Re-implementing the comparison would
     * mean re-implementing token patterns and closure rules too, and a second copy of that logic
     * drifting from Craft's is worse than declining to answer.
     */
    public function configRedirectCovers(string $uri): bool
    {
        if (!class_exists(RedirectRule::class)) {
            return false;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest || Uris::normalize($request->getFullPath()) !== Uris::normalize($uri)) {
            return false;
        }

        try {
            $rules = Craft::$app->getConfig()->getConfigFromFile('redirects');
        } catch (Throwable) {
            return false;
        }

        if (!$rules) {
            return false;
        }

        foreach ($rules as $from => $rule) {
            try {
                if (!$rule instanceof RedirectRule) {
                    $config = is_string($rule) ? ['to' => $rule] : $rule;
                    $rule = Craft::createObject(['class' => RedirectRule::class, 'from' => $from, ...$config]);
                }

                if ($rule->getMatch() !== null) {
                    return true;
                }
            } catch (Throwable $e) {
                Craft::warning('Could not read a config redirect rule: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        }

        return false;
    }

    /**
     * Whether the site has a `config/redirects.php` with anything in it.
     *
     * The tester cannot evaluate those rules against a hypothetical URI, so it says so rather than
     * implying it checked.
     */
    public function hasConfigRedirects(): bool
    {
        try {
            return (bool)Craft::$app->getConfig()->getConfigFromFile('redirects');
        } catch (Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------ resolution

    /**
     * Pins, then rules, then nothing.
     *
     * `$withTraces` turns on the per-rule commentary and switches the cache off — the tester and
     * the console command need to see the reasoning, and reasoning read out of a cache would be
     * about a decision made under settings that may since have changed.
     */
    public function resolve(Miss $miss, bool $withTraces = false): Outcome
    {
        if (!$withTraces) {
            $cached = $this->_readCache($miss);

            if ($cached !== null) {
                return $cached;
            }
        }

        $outcome = $this->_resolve($miss, $withTraces);

        if (!$withTraces) {
            $this->_writeCache($miss, $outcome);
        }

        return $outcome;
    }

    private function _resolve(Miss $miss, bool $withTraces): Outcome
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $outcome = new Outcome(['miss' => $miss]);

        $pin = $plugin->getPins()->find($miss->siteId, $miss->uri);

        if ($pin !== null) {
            $url = $pin->getTargetUrl();

            if ($url !== null && !$this->_pointsAtItself($url, $miss)) {
                $plugin->getPins()->recordHit($pin);

                $outcome->source = Outcome::SOURCE_PIN;
                $outcome->pin = $pin;
                $outcome->action = Rule::ACTION_REDIRECT;
                $outcome->targetUrl = $url;
                $outcome->statusCode = $pin->statusCode ?? $settings->redirectStatusCode;
                $outcome->candidate = new Candidate([
                    'elementId' => $pin->elementId,
                    'title' => $pin->getElement()?->title ?? $url,
                    'uri' => Uris::normalize($pin->getElement()?->uri ?? $url),
                    'url' => $url,
                    'score' => 100.0,
                    'methods' => ['pin'],
                ]);

                if ($withTraces) {
                    $outcome->traces[] = new Trace([
                        'ruleName' => Craft::t('friend', 'Pin'),
                        'status' => Trace::MATCHED,
                        'reason' => Craft::t('friend', 'A pin covers this URI, so no rule was consulted.'),
                        'bestScore' => 100.0,
                        'candidateCount' => 1,
                    ]);
                }

                return $outcome;
            }
        }

        foreach ($plugin->getRules()->getEnabledRules($miss->siteId) as $rule) {
            $trace = new Trace(['ruleId' => $rule->id, 'ruleName' => (string)$rule->name]);
            $decided = $this->_applyRule($rule, $miss, $outcome, $trace);

            if ($withTraces) {
                $outcome->traces[] = $trace;
            }

            if ($decided) {
                return $outcome;
            }
        }

        return $outcome;
    }

    /**
     * Run one rule. Returns whether it *decided* — which declining to match is not.
     */
    private function _applyRule(Rule $rule, Miss $miss, Outcome $outcome, Trace $trace): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $reason = $this->_whyRuleDoesNotApply($rule, $miss);

        if ($reason !== null) {
            $trace->status = Trace::SKIPPED;
            $trace->reason = $reason;

            return false;
        }

        if ($rule->action === Rule::ACTION_IGNORE) {
            $trace->status = Trace::IGNORED;
            $trace->reason = Craft::t('friend', 'The rule matched and is set to do nothing, so no later rule was consulted.');

            $outcome->source = Outcome::SOURCE_RULE;
            $outcome->rule = $rule;
            $outcome->action = Rule::ACTION_IGNORE;

            return true;
        }

        $candidates = Plugin::getInstance()->getCandidates()->find($rule, $miss);
        $trace->candidateCount = count($candidates);

        $best = $candidates[0] ?? null;
        $trace->bestScore = $best?->score;

        if ($best === null || $best->score < $rule->threshold || $best->url === null) {
            if ($best === null) {
                $trace->status = Trace::NO_CANDIDATES;
                $trace->reason = Craft::t('friend', 'Nothing came back from any of its retrieval methods.');
            } else {
                $trace->status = Trace::BELOW_THRESHOLD;
                $trace->reason = Craft::t('friend', 'Best score {score} is under the rule’s threshold of {threshold}.', [
                    'score' => $best->score,
                    'threshold' => $rule->threshold,
                ]);
            }

            return $this->_applyFallback($rule, $outcome, $trace, $candidates);
        }

        $outcome->source = Outcome::SOURCE_RULE;
        $outcome->rule = $rule;
        $outcome->candidate = $best;
        $outcome->candidates = $candidates;

        if ($rule->action === Rule::ACTION_SUGGEST) {
            $trace->status = Trace::SUGGESTED;
            $trace->reason = Craft::t('friend', 'Matched, but the rule offers suggestions rather than redirecting.');
            $outcome->action = Rule::ACTION_SUGGEST;

            return true;
        }

        $trace->status = Trace::MATCHED;
        $trace->reason = Craft::t('friend', 'Redirecting to {uri}.', ['uri' => $best->uri]);

        $outcome->action = Rule::ACTION_REDIRECT;
        $outcome->targetUrl = $best->url;
        $outcome->statusCode = $rule->statusCode ?? $settings->redirectStatusCode;

        return true;
    }

    /**
     * @param Candidate[] $candidates
     */
    private function _applyFallback(Rule $rule, Outcome $outcome, Trace $trace, array $candidates): bool
    {
        if ($rule->fallback !== Rule::FALLBACK_URL || trim((string)$rule->fallbackUrl) === '') {
            // Candidates are still worth carrying even when nothing cleared the threshold: a
            // "did you mean" list has a much lower bar than a redirect, and a template asking for
            // suggestions would otherwise have to redo the work.
            if ($candidates && !$outcome->candidates) {
                $outcome->candidates = $candidates;
            }

            return false;
        }

        $settings = Plugin::getInstance()->getSettings();

        $trace->status = Trace::MATCHED;
        $trace->reason = Craft::t('friend', 'Nothing cleared the threshold, so the rule’s fallback URL was used.');

        $outcome->source = Outcome::SOURCE_RULE;
        $outcome->rule = $rule;
        $outcome->candidates = $candidates;
        $outcome->action = Rule::ACTION_REDIRECT;
        // "A site URI, or a full URL" — the same reading a URL pin gets. A full URL handed to
        // urlFor() would be normalised down to its path on the current site.
        $fallbackUrl = trim((string)$rule->fallbackUrl);
        $outcome->targetUrl = preg_match('~^([a-z][a-z0-9+.-]*:)?//~i', $fallbackUrl) === 1
            ? $fallbackUrl
            : Plugin::getInstance()->getCandidates()->urlFor($fallbackUrl, $outcome->miss->siteId);
        $outcome->statusCode = $rule->statusCode ?? $settings->redirectStatusCode;

        return $outcome->targetUrl !== null;
    }

    private function _whyRuleDoesNotApply(Rule $rule, Miss $miss): ?string
    {
        if (!$rule->appliesToSite($miss->siteId)) {
            return Craft::t('friend', 'Limited to other sites.');
        }

        if ($rule->uriPattern && !Uris::matchesPattern($miss->uri, $rule->uriPattern)) {
            return Craft::t('friend', 'The URI does not match `{pattern}`.', ['pattern' => $rule->uriPattern]);
        }

        if ($rule->excludePatterns && Uris::matchesAny($miss->uri, $rule->excludePatterns)) {
            return Craft::t('friend', 'The URI matches one of the rule’s exclusions.');
        }

        $segments = count($miss->segments);

        if ($rule->minSegments && $segments < $rule->minSegments) {
            return Craft::t('friend', 'The path has {count} segments; the rule wants at least {min}.', [
                'count' => $segments,
                'min' => $rule->minSegments,
            ]);
        }

        if ($rule->maxSegments && $segments > $rule->maxSegments) {
            return Craft::t('friend', 'The path has {count} segments; the rule wants at most {max}.', [
                'count' => $segments,
                'max' => $rule->maxSegments,
            ]);
        }

        return null;
    }

    /**
     * Whether a target would send the visitor back to the URL that just failed.
     *
     * Only a pin can do this — candidates are filtered on the way out of retrieval — but a pin is
     * typed by hand, and a hand-typed redirect loop is a page that never loads at all.
     */
    private function _pointsAtItself(string $url, Miss $miss): bool
    {
        return Uris::normalize($url) === $miss->uri;
    }

    // ------------------------------------------------------------------ the current request

    /**
     * The outcome for the request in flight, resolved on demand.
     *
     * A 404 template calling `craft.friend.suggestions()` on a site where every rule is set to
     * redirect will find nothing resolved yet — no rule decided, so nothing was stored. Resolving
     * lazily means the tag works the same either way.
     */
    public function currentOutcome(): ?Outcome
    {
        if ($this->_outcome !== null) {
            return $this->_outcome;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest || !$this->requestIsEligible($request)) {
            return null;
        }

        $miss = Miss::fromRequest($request);

        // The same guards the error handler applies: a scanner's `/wp-login.php` should cost a
        // 404 template's suggestions tag nothing, and neither should anything when Friend is off.
        if ($miss->uri === '' || !Plugin::getInstance()->getSettings()->enabled || !$this->uriIsEligible($miss->uri)) {
            return null;
        }

        return $this->_outcome = $this->resolve($miss, true);
    }

    public function setCurrentOutcome(?Outcome $outcome): void
    {
        $this->_outcome = $outcome;
    }

    // ------------------------------------------------------------------ caching

    /**
     * Bumping a generation counter beats deleting keys.
     *
     * There is one cache entry per dead URL and no index of them, so "forget everything Friend
     * decided" cannot be expressed as a list of keys to delete. Folding a counter into the key
     * makes the whole previous generation unreachable in one write, and lets the old entries age
     * out on their own.
     */
    public function clearCaches(): void
    {
        $this->_outcome = null;
        $this->_generation = null;

        $cache = Craft::$app->getCache();
        $cache->set('friend:generation', (int)$cache->get('friend:generation') + 1);
    }

    private function _generation(): int
    {
        return $this->_generation ??= (int)Craft::$app->getCache()->get('friend:generation');
    }

    private function _cacheKey(Miss $miss): string
    {
        return sprintf('friend:m:%d:%d:%s', $this->_generation(), $miss->siteId, sha1($miss->uri));
    }

    private function _readCache(Miss $miss): ?Outcome
    {
        $duration = Plugin::getInstance()->getSettings()->cacheDuration;

        if ($duration <= 0) {
            return null;
        }

        $data = Craft::$app->getCache()->get($this->_cacheKey($miss));

        if (!is_array($data)) {
            return null;
        }

        $outcome = new Outcome([
            'miss' => $miss,
            'source' => $data['source'] ?? null,
            'action' => $data['action'] ?? Rule::ACTION_NONE,
            'targetUrl' => $data['targetUrl'] ?? null,
            'statusCode' => (int)($data['statusCode'] ?? 0),
        ]);

        if (!empty($data['ruleId'])) {
            $outcome->rule = Plugin::getInstance()->getRules()->getRuleById((int)$data['ruleId']);
        }

        if ($outcome->targetUrl !== null) {
            $outcome->candidate = new Candidate([
                'elementId' => $data['elementId'] ?? null,
                'title' => (string)($data['title'] ?? ''),
                'uri' => (string)($data['uri'] ?? ''),
                'url' => $outcome->targetUrl,
                'score' => (float)($data['score'] ?? 0),
                'methods' => ['cache'],
            ]);
        }

        return $outcome;
    }

    private function _writeCache(Miss $miss, Outcome $outcome): void
    {
        $duration = Plugin::getInstance()->getSettings()->cacheDuration;

        if ($duration <= 0) {
            return;
        }

        // With no explicit @web, Craft builds site URLs from the request's own Host header, so a
        // target built for one visitor was built from whatever host *they* sent. Caching it would
        // hand an attacker's host to everyone who follows the same dead link.
        $request = Craft::$app->getRequest();

        if ($request instanceof WebRequest && $request->isWebAliasSetDynamically) {
            return;
        }

        // A suggestion outcome is a list of candidates, and a list of candidates is exactly what
        // this cache does not store. Caching the decision without them would serve an empty "did
        // you mean" list for an hour. The same goes for a miss no rule decided but that still
        // carries the candidates a declining rule found.
        if ($outcome->action === Rule::ACTION_SUGGEST || ($outcome->action === Rule::ACTION_NONE && $outcome->candidates)) {
            return;
        }

        Craft::$app->getCache()->set($this->_cacheKey($miss), [
            'source' => $outcome->source,
            'action' => $outcome->action,
            'ruleId' => $outcome->rule?->id,
            'targetUrl' => $outcome->targetUrl,
            'statusCode' => $outcome->statusCode,
            'elementId' => $outcome->candidate?->elementId,
            'title' => $outcome->candidate?->title,
            'uri' => $outcome->candidate?->uri,
            'score' => $outcome->candidate?->score,
        ], $duration);
    }
}
