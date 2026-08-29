<?php

namespace justinholtweb\friend\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\friend\helpers\Uris;

/**
 * A stored exact mapping for one URI.
 *
 * The escape hatch. Scoring is a guess, and every guessing system needs a place to record the
 * answer once a human knows it — otherwise the only way to fix one wrong redirect is to make the
 * rules worse for every other URL.
 *
 * Pins are consulted before any rule and cost a single indexed lookup.
 */
class Pin extends Model
{
    public const TARGET_ELEMENT = 'element';
    public const TARGET_URL = 'url';

    public ?int $id = null;

    /** Null means every site. */
    public ?int $siteId = null;

    public string $uri = '';
    public bool $enabled = true;
    public string $targetType = self::TARGET_ELEMENT;
    public ?int $elementId = null;
    public ?string $url = null;
    public ?int $statusCode = null;
    public int $hits = 0;
    public ?DateTime $dateLastHit = null;
    public ?string $uid = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;

    private ?ElementInterface $_element = null;
    private bool $_elementLoaded = false;

    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['dateLastHit']);
    }

    public function getElement(): ?ElementInterface
    {
        if (!$this->_elementLoaded) {
            $this->_elementLoaded = true;
            $this->_element = $this->elementId
                ? Craft::$app->getElements()->getElementById($this->elementId, null, $this->siteId)
                : null;
        }

        return $this->_element;
    }

    /**
     * Where this pin actually sends someone, resolved now rather than stored.
     *
     * An element's URL is not a fixed string — it changes with the site, with the URI, with the
     * environment's base URL. Storing it would make every pin a stale copy of the truth.
     */
    public function getTargetUrl(): ?string
    {
        if ($this->targetType === self::TARGET_URL) {
            $url = trim((string)$this->url);

            if ($url === '') {
                return null;
            }

            return preg_match('~^([a-z][a-z0-9+.-]*:)?//~i', $url) === 1
                ? $url
                : UrlHelper::siteUrl($url, null, null, $this->siteId);
        }

        return $this->getElement()?->getUrl();
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('friend/pins/' . $this->id);
    }

    protected function defineRules(): array
    {
        return [
            [['uri', 'targetType'], 'required'],
            [['uri', 'url'], 'string'],
            [['enabled'], 'boolean'],
            [['siteId', 'elementId', 'hits'], 'integer'],
            [['statusCode'], 'in', 'range' => array_keys(Rule::statusCodes()), 'skipOnEmpty' => true],
            [['targetType'], 'in', 'range' => [self::TARGET_ELEMENT, self::TARGET_URL]],
            [['elementId', 'url'], 'validateTarget', 'skipOnEmpty' => false],
        ];
    }

    public function validateTarget(): void
    {
        if ($this->targetType === self::TARGET_ELEMENT) {
            if (!$this->elementId) {
                $this->addError('elementId', Craft::t('friend', 'Pick something to point at.'));
            } elseif ($this->getElement() === null) {
                $this->addError('elementId', Craft::t('friend', 'That element no longer exists.'));
            }

            return;
        }

        if (trim((string)$this->url) === '') {
            $this->addError('url', Craft::t('friend', 'A pin needs somewhere to go.'));
        }
    }

    public function beforeValidate(): bool
    {
        $this->uri = Uris::normalize($this->uri);

        return parent::beforeValidate();
    }
}
