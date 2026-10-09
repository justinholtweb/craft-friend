<?php

namespace justinholtweb\friend;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\ExceptionEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\ErrorHandler;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\friend\models\Settings;
use justinholtweb\friend\services\Candidates;
use justinholtweb\friend\services\Log;
use justinholtweb\friend\services\Matcher;
use justinholtweb\friend\services\Pins;
use justinholtweb\friend\services\PinTransfer;
use justinholtweb\friend\services\Rules;
use justinholtweb\friend\twig\FriendVariable;
use yii\base\Event;

/**
 * Friend — every dead URL has a friend.
 *
 * @property-read Rules $rules
 * @property-read Pins $pins
 * @property-read PinTransfer $pinTransfer
 * @property-read Matcher $matcher
 * @property-read Candidates $candidates
 * @property-read Log $log
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_VIEW = 'friend:viewLog';
    public const PERMISSION_MANAGE_RULES = 'friend:manageRules';
    public const PERMISSION_MANAGE_PINS = 'friend:managePins';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'friend';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'rules' => Rules::class,
                'pins' => Pins::class,
                'pinTransfer' => PinTransfer::class,
                'matcher' => Matcher::class,
                'candidates' => Candidates::class,
                'log' => Log::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerCpRoutes();
        $this->registerPermissions();
        $this->registerTwig();
        $this->registerGarbageCollection();
        $this->registerErrorHandling();
    }

    public function getRules(): Rules
    {
        return $this->get('rules');
    }

    public function getPins(): Pins
    {
        return $this->get('pins');
    }

    public function getPinTransfer(): PinTransfer
    {
        return $this->get('pinTransfer');
    }

    public function getMatcher(): Matcher
    {
        return $this->get('matcher');
    }

    public function getCandidates(): Candidates
    {
        return $this->get('candidates');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('friend', 'Friend');

        $user = Craft::$app->getUser();

        $item['subnav'] = [];

        if ($user->checkPermission(self::PERMISSION_MANAGE_RULES) || $user->getIsAdmin()) {
            $item['subnav']['rules'] = [
                'label' => Craft::t('friend', 'Rules'),
                'url' => 'friend/rules',
            ];
        }

        $item['subnav']['log'] = [
            'label' => Craft::t('friend', '404 log'),
            'url' => 'friend/log',
        ];

        if ($user->checkPermission(self::PERMISSION_MANAGE_PINS) || $user->getIsAdmin()) {
            $item['subnav']['pins'] = [
                'label' => Craft::t('friend', 'Pins'),
                'url' => 'friend/pins',
            ];
        }

        $item['subnav']['tester'] = [
            'label' => Craft::t('friend', 'Tester'),
            'url' => 'friend/tester',
        ];

        if ($user->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('friend', 'Settings'),
                'url' => 'settings/plugins/friend',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('friend/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
        ]);
    }

    // ------------------------------------------------------------------ registration

    /**
     * The whole plugin hangs off this one event.
     *
     * `craft\web\ErrorHandler` fires it at the top of `handleException()`, which is before Craft
     * reads `config/redirects.php` and before it renders the site's 404 template — so a redirect
     * from here costs one element query and nothing else. Everything downstream of it is already
     * wasted work.
     */
    private function registerErrorHandling(): void
    {
        Event::on(ErrorHandler::class, ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION, function(ExceptionEvent $event) {
            $this->getMatcher()->handleException($event->exception);
        });
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'friend' => 'friend/rules/index',
                'friend/rules' => 'friend/rules/index',
                'friend/rules/new' => 'friend/rules/edit',
                'friend/rules/<ruleId:\d+>' => 'friend/rules/edit',
                'friend/log' => 'friend/log/index',
                'friend/pins' => 'friend/pins/index',
                'friend/pins/new' => 'friend/pins/edit',
                'friend/pins/import' => 'friend/pins/import',
                'friend/pins/<pinId:\d+>' => 'friend/pins/edit',
                'friend/tester' => 'friend/tester/index',
            ];
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('friend', 'Friend'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('friend', 'View the 404 log'),
                        'nested' => [
                            self::PERMISSION_MANAGE_PINS => [
                                'label' => Craft::t('friend', 'Create and edit pins'),
                            ],
                            self::PERMISSION_MANAGE_RULES => [
                                'label' => Craft::t('friend', 'Create and edit rules'),
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('friend', FriendVariable::class);
        });
    }

    /**
     * The log is capped by age and by row count, and garbage collection is where that happens.
     *
     * Pruning on write would put the cost on the visitor who happened to trip the thousandth 404,
     * which is both unfair and unpredictable.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->getLog()->prune();
        });
    }
}
