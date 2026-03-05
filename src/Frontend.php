<?php

namespace Inspire\Frontend;

use Craft;
use craft\web\Controller;
use craft\web\Response;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\PluginEvent;
use craft\events\RegisterCpSettingsEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\TemplateEvent;
use craft\services\Plugins;
use craft\web\twig\variables\Cp;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use Inspire\Frontend\controllers\UtilityController;
use Inspire\Frontend\models\Settings;
use Inspire\Frontend\services\PrefetchService;
use Inspire\Frontend\twig\ImagesHelper;
use Inspire\Frontend\twig\ImagesTwigExtension;
use Inspire\Frontend\variables\PrefetchVariable;
use putyourlightson\blitzhints\BlitzHints;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * @property-read PrefetchService $prefetch
 */
class Frontend extends Plugin
{
    /** @var self */
    public static self $plugin;

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritdoc
     */
    public bool $hasCpSection = false;

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        /**
         * register services
         */
        $this->setComponents([
            'prefetch' => PrefetchService::class,
        ]);

        /**
         * register template root
         */
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event) {
                $event->roots[''][] = __DIR__ . '/templates';
            }
        );
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event): void {
                $event->roots['inspire-frontend'] = __DIR__ . '/templates';
            }
        );

        $this->registerSettingsLinks();
        Craft::$app->getView()->registerTwigExtension(new ImagesTwigExtension());
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_TEMPLATE,
            static function(TemplateEvent $event): void {
                if (!array_key_exists('images', $event->variables)) {
                    $event->variables['images'] = new ImagesHelper();
                }
            }
        );

        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function(PluginEvent $event): void {
                if ($event->plugin?->id !== $this->id) {
                    return;
                }
                $this->runAutoBuildScriptSync(force: true);
            }
        );
        $this->runAutoBuildScriptSync();

        /**
         * set up Blitz Hints
         */
        BlitzHints::bootstrap();

        /**
         * only for site loads
         */
        $request = Craft::$app->getRequest();
        if ($request->getIsSiteRequest()) {
            /**
             * register prefetch
             */
            Event::on(
                CraftVariable::class,
                CraftVariable::EVENT_INIT,
                function (Event $event) {
                    $variable = $event->sender;
                    $variable->set('prefetch', PrefetchVariable::class);
                }
            );
            Event::on(
                View::class,
                View::EVENT_BEGIN_BODY,
                function (Event $event) {
                    self::$plugin->prefetch->onBeginBody();
                }
            );

            if (!$request->getIsActionRequest()) {
                /**
                 * autoload config based bundles
                 */
                foreach ($this->getSettings()->autoloadBundles as $bundle) {
                    Craft::$app->getView()->registerAssetBundle($bundle);
                }
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): Model
    {
        return new Settings();
    }

    public function getSettingsResponse(): Response
    {
        $tab = (string)Craft::$app->request->getQueryParam('tab', 'rebuild-assets');
        $allowedTabs = ['rebuild-assets', 'update-build-script', 'git-status'];
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'rebuild-assets';
        }

        /** @var Controller $controller */
        $controller = Craft::$app->controller;

        return $controller->renderTemplate(
            'inspire-frontend/settings/index',
            [
                'plugin' => $this,
                'settings' => $this->getSettings(),
                'selectedTab' => $tab,
            ]
        );
    }

    protected function registerSettingsLinks(): void
    {
        $register = function(RegisterCpSettingsEvent $event): void {
            $systemLabel = Craft::t('app', 'System');
            $pluginsLabel = Craft::t('app', 'Plugins');
            $handle = 'inspire-frontend';
            $iconMaskPath = $this->getBasePath() . '/icon.svg';

            $frontendItem = [
                'url' => "settings/plugins/$handle",
                'label' => 'Frontend',
                'iconMask' => $iconMaskPath,
            ];

            $systemItems = $event->settings[$systemLabel] ?? [];
            unset($systemItems[$handle]);

            $orderedSystemItems = [];
            $inserted = false;
            foreach ($systemItems as $key => $item) {
                if ($key === 'plugins' && !$inserted) {
                    $orderedSystemItems[$handle] = $frontendItem;
                    $inserted = true;
                }
                $orderedSystemItems[$key] = $item;
            }

            if (!$inserted) {
                $orderedSystemItems[$handle] = $frontendItem;
            }

            $event->settings[$systemLabel] = $orderedSystemItems;
            unset($event->settings[$pluginsLabel][$handle]);
        };

        Event::on(Cp::class, Cp::EVENT_REGISTER_CP_SETTINGS, $register);
        Event::on(Cp::class, Cp::EVENT_REGISTER_READ_ONLY_CP_SETTINGS, $register);
    }

    private function runAutoBuildScriptSync(bool $force = false): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest() && !$force) {
            return;
        }

        if (!Craft::$app->getIsInstalled()) {
            return;
        }

        $settings = $this->getSettings();
        if (!$settings instanceof Settings) {
            return;
        }

        $currentVersion = (string)$this->getVersion();
        if ($currentVersion === '') {
            return;
        }

        if (!$force && $settings->lastAutoBuildSyncVersion === $currentVersion) {
            return;
        }

        try {
            $controller = new UtilityController('inspire-frontend-utility', Craft::$app);
            $result = $controller->runBuildScriptAutoSync(true);

            if (!empty($result['success'])) {
                Craft::info('Auto build-script sync completed for inspire-frontend v' . $currentVersion, __METHOD__);
            } else {
                $message = (string)($result['message'] ?? 'Auto build-script sync failed');
                Craft::warning($message, __METHOD__);
            }
        } catch (\Throwable $exception) {
            Craft::error('Auto build-script sync exception: ' . $exception->getMessage(), __METHOD__);
        } finally {
            $settings->lastAutoBuildSyncVersion = $currentVersion;
            try {
                Craft::$app->getPlugins()->savePluginSettings($this, $settings->toArray());
            } catch (\Throwable $exception) {
                Craft::warning('Unable to persist auto build-script sync version marker: ' . $exception->getMessage(), __METHOD__);
            }
        }
    }
}
