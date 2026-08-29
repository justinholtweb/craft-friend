<?php

namespace justinholtweb\friends\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use DateTime;
use justinholtweb\friends\records\RuleRecord;

/**
 * One rule in the ordered set.
 *
 * A rule answers three separate questions, and keeping them separate is what stops it turning into
 * a pile of switches: **when** does it apply (conditions), **what** may it send people to
 * (sources), and **how sure** does it have to be (retrieval, weights, threshold).
 *
 * Rules are consulted in order. The first one that *decides* wins — and declining because nothing
 * cleared the threshold is not a decision, so evaluation falls through to the next rule.
 */
class Rule extends Model
{
    /** Send the visitor to the winning candidate. */
    public const ACTION_REDIRECT = 'redirect';

    /** Stay 404, but hand the candidates to the template as suggestions. */
    public const ACTION_SUGGEST = 'suggest';

    /** Match the URI and deliberately do nothing, so no later rule gets a turn. */
    public const ACTION_IGNORE = 'ignore';

    /** Not an action a rule can be given — what an Outcome reports when nothing decided. */
    public const ACTION_NONE = 'none';

    /** An element whose slug is exactly the missing last segment. */
    public const METHOD_SLUG = 'slug';

    /** Elements whose slug or title contains any significant token from the URL. */
    public const METHOD_TOKENS = 'tokens';

    /** Craft's own search index, queried with the tokens OR-ed together. */
    public const METHOD_SEARCH = 'search';

    /** The nearest existing element at a shorter prefix of the same path. */
    public const METHOD_ANCESTOR = 'ancestor';

    public const FALLBACK_NONE = 'none';
    public const FALLBACK_URL = 'url';

    public ?int $id = null;
    public ?string $name = null;
    public ?string $handle = null;
    public ?string $description = null;
    public bool $enabled = true;
    public ?int $sortOrder = null;
    public ?string $uid = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;

    // ---------------------------------------------------------------- when it applies

    /**
     * @var int[] Site IDs this rule is limited to. Empty means every site.
     */
    public array $siteIds = [];

    /**
     * @var string|null Glob (or `re:` regex) the missing URI must match. Null means any.
     */
    public ?string $uriPattern = null;

    /**
     * @var string[] Patterns that disqualify the rule even when `uriPattern` matched.
     */
    public array $excludePatterns = [];

    public ?int $minSegments = null;
    public ?int $maxSegments = null;

    // ---------------------------------------------------------------- what it may point at

    /** @var class-string<ElementInterface> */
    public string $elementType = Entry::class;

    /** @var int[] Section IDs, when the element type is Entry. Empty means all of them. */
    public array $sectionIds = [];

    /** @var int[] Entry type IDs. Empty means all of them. */
    public array $entryTypeIds = [];

    /** @var int[] Category group IDs, when the element type is Category. */
    public array $categoryGroupIds = [];

    /**
     * @var string|null Only consider elements whose URI starts with this. The one narrowing that
     *                  works for every element type, including ones this plugin has never heard of.
     */
    public ?string $uriPrefix = null;

    /** Only consider elements that are live/enabled. Off is almost always a mistake. */
    public bool $enabledOnly = true;

    // ---------------------------------------------------------------- how sure it must be

    /**
     * @var string[] Retrieval methods, any combination.
     */
    public array $methods = [self::METHOD_SLUG, self::METHOD_TOKENS, self::METHOD_SEARCH];

    public int $weightSlug = 60;
    public int $weightTitle = 25;
    public int $weightPath = 15;

    /** 0–100. The whole safety mechanism. */
    public int $threshold = 55;

    /** Most elements any one retrieval method may pull back. Null uses the plugin default. */
    public ?int $candidateLimit = null;

    // ---------------------------------------------------------------- what it does

    public string $action = self::ACTION_REDIRECT;

    /** Null uses the plugin default. */
    public ?int $statusCode = null;

    /** What to do when the rule applied but nothing cleared the threshold. */
    public string $fallback = self::FALLBACK_NONE;

    public ?string $fallbackUrl = null;

    // ----------------------------------------------------------------

    /** @return array<string, string> */
    public static function actions(): array
    {
        return [
            self::ACTION_REDIRECT => Craft::t('friends', 'Redirect to the best match'),
            self::ACTION_SUGGEST => Craft::t('friends', 'Stay 404, offer suggestions'),
            self::ACTION_IGNORE => Craft::t('friends', 'Do nothing, and stop here'),
        ];
    }

    /** @return array<string, string> */
    public static function methodLabels(): array
    {
        return [
            self::METHOD_SLUG => Craft::t('friends', 'Exact slug'),
            self::METHOD_TOKENS => Craft::t('friends', 'Word overlap'),
            self::METHOD_SEARCH => Craft::t('friends', 'Search index'),
            self::METHOD_ANCESTOR => Craft::t('friends', 'Nearest ancestor page'),
        ];
    }

    /** @return array<string, string> */
    public static function statusCodes(): array
    {
        return [
            301 => Craft::t('friends', '301 — Moved permanently'),
            302 => Craft::t('friends', '302 — Found (temporary)'),
            307 => Craft::t('friends', '307 — Temporary redirect'),
            308 => Craft::t('friends', '308 — Permanent redirect'),
        ];
    }

    /**
     * Element types a rule can point at: the ones that have URLs, since a redirect to an element
     * with no URL is not a redirect.
     *
     * @return array<class-string<ElementInterface>, string>
     */
    public static function elementTypeOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getElements()->getAllElementTypes() as $type) {
            /** @var class-string<ElementInterface> $type */
            if (!$type::hasUris()) {
                continue;
            }

            $options[$type] = $type::pluralDisplayName();
        }

        // Entries first, whatever the alphabet says — it is the answer for almost every site.
        if (isset($options[Entry::class])) {
            $options = [Entry::class => $options[Entry::class]] + $options;
        }

        return $options;
    }

    public function getActionLabel(): string
    {
        return self::actions()[$this->action] ?? $this->action;
    }

    /**
     * Not `hasMethod()`: `yii\base\Model` already has one, and an incompatible override is a
     * fatal compile error the moment the class is autoloaded — nowhere near the call site, and
     * not a warning. Same family as `Component::load()`.
     */
    public function usesMethod(string $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    public function appliesToSite(int $siteId): bool
    {
        return $this->siteIds === [] || in_array($siteId, array_map('intval', $this->siteIds), true);
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('friends/rules/' . $this->id);
    }

    /**
     * @return array<string, int|float>
     */
    public function weights(): array
    {
        return [
            'slug' => $this->weightSlug,
            'title' => $this->weightTitle,
            'path' => $this->weightPath,
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'action', 'elementType'], 'required'],
            [['name', 'description', 'uriPattern', 'uriPrefix', 'fallbackUrl'], 'string'],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'dateCreated', 'dateUpdated', 'uid']],
            [
                ['handle'],
                UniqueValidator::class,
                'targetClass' => RuleRecord::class,
                'targetAttribute' => 'handle',
                'message' => Craft::t('friends', 'That handle is already in use.'),
            ],
            [['enabled', 'enabledOnly'], 'boolean'],
            [['threshold'], 'integer', 'min' => 0, 'max' => 100],
            [['weightSlug', 'weightTitle', 'weightPath'], 'integer', 'min' => 0, 'max' => 100],
            [['minSegments', 'maxSegments'], 'integer', 'min' => 1, 'max' => 20],
            [['candidateLimit'], 'integer', 'min' => 1, 'max' => 500],
            [['action'], 'in', 'range' => array_keys(self::actions())],
            [['fallback'], 'in', 'range' => [self::FALLBACK_NONE, self::FALLBACK_URL]],
            [['statusCode'], 'in', 'range' => array_keys(self::statusCodes()), 'skipOnEmpty' => true],
            [['elementType'], 'validateElementType'],
            [['uriPattern'], 'validatePattern'],

            // `skipOnEmpty` matters on both of these: an empty array counts as empty to Yii, so
            // without it the one case each rule exists to catch is the one it never sees.
            [['methods'], 'validateMethods', 'skipOnEmpty' => false],
            [['fallbackUrl'], 'validateFallbackUrl', 'skipOnEmpty' => false],

            [
                ['siteIds', 'excludePatterns', 'sectionIds', 'entryTypeIds', 'categoryGroupIds'],
                'safe',
            ],
        ];
    }

    public function validateElementType(string $attribute): void
    {
        $type = $this->elementType;

        if (!is_string($type) || !class_exists($type) || !is_subclass_of($type, ElementInterface::class)) {
            $this->addError($attribute, Craft::t('friends', 'That is not an element type.'));
            return;
        }

        if (!$type::hasUris()) {
            $this->addError($attribute, Craft::t('friends', '{type} elements do not have URLs, so nothing can be redirected to one.', [
                'type' => $type::displayName(),
            ]));
        }
    }

    public function validateMethods(string $attribute): void
    {
        $known = array_keys(self::methodLabels());
        $this->methods = array_values(array_intersect($known, (array)$this->methods));

        if (!$this->methods) {
            $this->addError($attribute, Craft::t('friends', 'Pick at least one way to find candidates.'));
        }
    }

    public function validatePattern(string $attribute): void
    {
        $pattern = (string)$this->uriPattern;

        if (!str_starts_with($pattern, 're:')) {
            return;
        }

        if (@preg_match('~' . str_replace('~', '\~', substr($pattern, 3)) . '~i', '') === false) {
            $this->addError($attribute, Craft::t('friends', 'That regular expression is not valid.'));
        }
    }

    public function validateFallbackUrl(string $attribute): void
    {
        if ($this->fallback === self::FALLBACK_URL && trim((string)$this->fallbackUrl) === '') {
            $this->addError($attribute, Craft::t('friends', 'A fallback needs somewhere to go.'));
        }
    }

    /**
     * The JSON blocks, as the record stores them.
     *
     * Scalars an index sorts or filters on get their own columns; everything else lives in one of
     * these four, because a rule grows knobs over time and a migration per knob is a tax nobody
     * gets anything for.
     *
     * @return array<string, string>
     */
    public function jsonBlocks(): array
    {
        return [
            'siteIds' => Json::encode(array_values(array_map('intval', $this->siteIds))),
            'conditions' => Json::encode([
                'uriPattern' => $this->uriPattern,
                'excludePatterns' => array_values($this->excludePatterns),
                'minSegments' => $this->minSegments,
                'maxSegments' => $this->maxSegments,
            ]),
            'sources' => Json::encode([
                'elementType' => $this->elementType,
                'sectionIds' => array_values(array_map('intval', $this->sectionIds)),
                'entryTypeIds' => array_values(array_map('intval', $this->entryTypeIds)),
                'categoryGroupIds' => array_values(array_map('intval', $this->categoryGroupIds)),
                'uriPrefix' => $this->uriPrefix,
                'enabledOnly' => $this->enabledOnly,
            ]),
            'scoring' => Json::encode([
                'methods' => array_values($this->methods),
                'weightSlug' => $this->weightSlug,
                'weightTitle' => $this->weightTitle,
                'weightPath' => $this->weightPath,
                'candidateLimit' => $this->candidateLimit,
                'fallback' => $this->fallback,
                'fallbackUrl' => $this->fallbackUrl,
            ]),
        ];
    }

    /**
     * Rebuild a rule from a database row.
     *
     * Unknown or missing keys keep the property's default rather than nulling it, so a rule saved
     * by an older schema version still loads — and a `null` never reaches a non-nullable property.
     */
    public static function fromRow(array $row): self
    {
        $rule = new self([
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'handle' => $row['handle'],
            'description' => $row['description'] ?? null,
            'enabled' => (bool)$row['enabled'],
            'sortOrder' => $row['sortOrder'] !== null ? (int)$row['sortOrder'] : null,
            'action' => $row['action'] ?: self::ACTION_REDIRECT,
            'statusCode' => isset($row['statusCode']) && $row['statusCode'] ? (int)$row['statusCode'] : null,
            'threshold' => (int)($row['threshold'] ?? 55),
            'uid' => $row['uid'] ?? null,
            'dateCreated' => $row['dateCreated'] ?? null,
            'dateUpdated' => $row['dateUpdated'] ?? null,
        ]);

        $rule->siteIds = array_map('intval', self::decode($row['siteIds'] ?? null));

        $conditions = self::decode($row['conditions'] ?? null);
        $rule->uriPattern = $conditions['uriPattern'] ?? null;
        $rule->excludePatterns = array_values((array)($conditions['excludePatterns'] ?? []));
        $rule->minSegments = isset($conditions['minSegments']) ? (int)$conditions['minSegments'] ?: null : null;
        $rule->maxSegments = isset($conditions['maxSegments']) ? (int)$conditions['maxSegments'] ?: null : null;

        $sources = self::decode($row['sources'] ?? null);
        $rule->elementType = $sources['elementType'] ?? Entry::class;
        $rule->sectionIds = array_map('intval', (array)($sources['sectionIds'] ?? []));
        $rule->entryTypeIds = array_map('intval', (array)($sources['entryTypeIds'] ?? []));
        $rule->categoryGroupIds = array_map('intval', (array)($sources['categoryGroupIds'] ?? []));
        $rule->uriPrefix = $sources['uriPrefix'] ?? null;
        $rule->enabledOnly = (bool)($sources['enabledOnly'] ?? true);

        $scoring = self::decode($row['scoring'] ?? null);
        $rule->methods = array_values((array)($scoring['methods'] ?? $rule->methods));
        $rule->weightSlug = (int)($scoring['weightSlug'] ?? $rule->weightSlug);
        $rule->weightTitle = (int)($scoring['weightTitle'] ?? $rule->weightTitle);
        $rule->weightPath = (int)($scoring['weightPath'] ?? $rule->weightPath);
        $rule->candidateLimit = isset($scoring['candidateLimit']) ? ((int)$scoring['candidateLimit'] ?: null) : null;
        $rule->fallback = $scoring['fallback'] ?? self::FALLBACK_NONE;
        $rule->fallbackUrl = $scoring['fallbackUrl'] ?? null;

        return $rule;
    }

    private static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = Json::decodeIfJson($value);

        return is_array($decoded) ? $decoded : [];
    }
}
