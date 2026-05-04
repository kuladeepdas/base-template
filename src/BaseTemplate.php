<?php

namespace Kdgraphics\BaseTemplate;

use Craft;
use craft\web\Controller;
use craft\web\Response;
use craft\base\Model;
use craft\base\Plugin;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\RegisterCpSettingsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\RegisterElementSourcesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\TemplateEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\web\twig\variables\Cp;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use craft\services\UserPermissions;
use craft\services\Fields;
use craft\models\FieldLayout;
use craft\ckeditor\events\DefineLinkOptionsEvent;
use craft\ckeditor\Field as CkEditorField;
use craft\redactor\events\RegisterLinkOptionsEvent;
use craft\redactor\Field as RedactorField;
use Kdgraphics\BaseTemplate\models\Settings;
use Kdgraphics\BaseTemplate\services\Logs;
use Kdgraphics\BaseTemplate\services\PrefetchService;
use Kdgraphics\BaseTemplate\services\Resize;
use Kdgraphics\BaseTemplate\services\SchemaSnapshotService;
use Kdgraphics\BaseTemplate\services\Service;
use Kdgraphics\BaseTemplate\services\SinglesList;
use Kdgraphics\BaseTemplate\twig\ImagesHelper;
use Kdgraphics\BaseTemplate\twig\ImagesTwigExtension;
use Kdgraphics\BaseTemplate\twig\ColorTwigExtension;
use Kdgraphics\BaseTemplate\variables\PrefetchVariable;
use Kdgraphics\BaseTemplate\fields\ColorAlpha;
use Kdgraphics\BaseTemplate\embeddedvideos\Integration as EmbeddedVideosIntegration;
use Kdgraphics\BaseTemplate\embeddedvideos\Service as EmbeddedVideosService;
use Kdgraphics\BaseTemplate\embeddedvideos\Controller as EmbeddedVideosController;
use Kdgraphics\BaseTemplate\assetlocations\fieldlayoutelements\AssetLocationsField;
use Kdgraphics\BaseTemplate\assetlocations\services\FindAssetService as AssetLocationsFindAssetService;
use Kdgraphics\BaseTemplate\assetlocations\services\FindElementService as AssetLocationsFindElementService;
use Kdgraphics\BaseTemplate\assetlocations\services\GetSectionService as AssetLocationsGetSectionService;
use Kdgraphics\BaseTemplate\assetlocations\services\GetUsageService as AssetLocationsGetUsageService;
use putyourlightson\blitzhints\BlitzHints;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * @property-read PrefetchService $prefetch
 */
class BaseTemplate extends Plugin
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
            'schemaSnapshot' => SchemaSnapshotService::class,
            'service' => Service::class,
            'resize' => Resize::class,
            'singlesList' => SinglesList::class,
            'logs' => Logs::class,
            'embeddedVideos' => EmbeddedVideosService::class,
            'assetLocationsFindAsset' => AssetLocationsFindAssetService::class,
            'assetLocationsSection' => AssetLocationsGetSectionService::class,
            'assetLocationsElementService' => AssetLocationsFindElementService::class,
            'assetLocationsUsage' => AssetLocationsGetUsageService::class,
        ]);

        \Kdgraphics\BaseTemplate\embeddedvideos\Plugin::boot($this);
        Craft::setAlias('@kdgraphics/base-template/resources', $this->getBasePath() . '/resources');
        Craft::setAlias('@kdgraphics/base-template/embeddedvideos', $this->getBasePath() . '/embeddedvideos');
        $this->controllerMap['embedded-videos'] = EmbeddedVideosController::class;

        (new EmbeddedVideosIntegration($this))->register();
        $this->registerFrontendFeatureHandlers();
        $this->registerAssetLocationsHandlers();
        $this->registerFieldTypes();
        $this->registerPermissions();

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
                $event->roots['base-template'] = __DIR__ . '/templates';
            }
        );

        $this->registerSettingsLinks();
        Craft::$app->getView()->registerTwigExtension(new ImagesTwigExtension());
        Craft::$app->getView()->registerTwigExtension(new ColorTwigExtension());
        $twig = Craft::$app->getView()->getTwig();
        if ($twig !== null && !array_key_exists('images', $twig->getGlobals())) {
            $twig->addGlobal('images', new ImagesHelper());
        }
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_TEMPLATE,
            static function(TemplateEvent $event): void {
                if (!array_key_exists('images', $event->variables)) {
                    $event->variables['images'] = new ImagesHelper();
                }
            }
        );

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
        $allowedTabs = [
            'rebuild-assets',
            'update-build-script',
            'git-status',
            'content-models',
            'asset-locations',
            'expanded-singles',
            'embedded-videos',
            'image-resizer',
            'resize-bulk',
            'logs',
        ];
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'rebuild-assets';
        }

        /** @var Controller $controller */
        $controller = Craft::$app->controller;

        return $controller->renderTemplate(
            'base-template/settings/index',
            [
                'plugin' => $this,
                'settings' => $this->getSettings(),
                'selectedTab' => $tab,
                'snapshotTypes' => $this->getSchemaSnapshot()->getSnapshotTypeLabels(),
                'logEntries' => $this->getLogs()->getLogEntries(),
                'sourceOptions' => $this->getVolumeOptions(),
                'folderOptions' => $this->getFolderOptions(),
            ]
        );
    }

    public function getSchemaSnapshot(): SchemaSnapshotService
    {
        /** @var SchemaSnapshotService $service */
        $service = $this->get('schemaSnapshot');
        return $service;
    }

    protected function registerSettingsLinks(): void
    {
        $register = function(RegisterCpSettingsEvent $event): void {
            $systemLabel = Craft::t('app', 'System');
            $pluginsLabel = Craft::t('app', 'Plugins');
            $handle = 'base-template';
            $iconMaskPath = $this->getBasePath() . '/icon.svg';

            $frontendItem = [
                'url' => "settings/plugins/$handle",
                'label' => 'Base Template',
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

    public function getService(): Service
    {
        /** @var Service $service */
        $service = $this->get('service');
        return $service;
    }

    public function getResize(): Resize
    {
        /** @var Resize $service */
        $service = $this->get('resize');
        return $service;
    }

    public function getSinglesList(): SinglesList
    {
        /** @var SinglesList $service */
        $service = $this->get('singlesList');
        return $service;
    }

    public function getLogs(): Logs
    {
        /** @var Logs $service */
        $service = $this->get('logs');
        return $service;
    }

    public function getEmbeddedVideos(): EmbeddedVideosService
    {
        /** @var EmbeddedVideosService $service */
        $service = $this->get('embeddedVideos');
        return $service;
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event): void {
            $event->permissions[] = [
                'heading' => Craft::t('base-template', 'Base Template'),
                'permissions' => [
                    'imageResizer-resizeImage' => ['label' => Craft::t('base-template', 'Resize images')],
                ],
            ];
        });
    }

    private function registerFrontendFeatureHandlers(): void
    {
        Event::on(Asset::class, Asset::EVENT_BEFORE_HANDLE_FILE, [$this->getService(), 'beforeHandleAssetFile']);
        Event::on(Asset::class, Element::EVENT_REGISTER_ACTIONS, [$this->getService(), 'registerAssetActions']);

        Event::on(Entry::class, Element::EVENT_REGISTER_SOURCES, function(RegisterElementSourcesEvent $event): void {
            if ($this->getSettings()->expandSingles) {
                foreach ($event->sources as $source) {
                    if (array_key_exists('key', $source) && $source['key'] === 'singles') {
                        $this->getSinglesList()->createSinglesList($event);
                    }
                }
            }
        });

        if (class_exists(RedactorField::class)) {
            Event::on(RedactorField::class, RedactorField::EVENT_REGISTER_LINK_OPTIONS, function(RegisterLinkOptionsEvent $event): void {
                if ($this->getSettings()->expandSingles) {
                    foreach ($event->linkOptions as $i => $linkOption) {
                        if ($linkOption['refHandle'] === 'entry' && in_array('singles', $linkOption['sources'])) {
                            $modifiedSources = $this->getSinglesList()->createSectionedSinglesList($linkOption['sources']);
                            if ($modifiedSources) {
                                $event->linkOptions[$i]['sources'] = $modifiedSources;
                            }
                        }
                    }
                }
            });
        }

        if (class_exists(CkEditorField::class)) {
            Event::on(CkEditorField::class, CkEditorField::EVENT_DEFINE_LINK_OPTIONS, function(DefineLinkOptionsEvent $event): void {
                if ($this->getSettings()->expandSingles) {
                    foreach ($event->linkOptions as $i => $linkOption) {
                        if ($linkOption['refHandle'] === 'entry' && in_array('singles', $linkOption['sources'])) {
                            $modifiedSources = $this->getSinglesList()->createSectionedSinglesList($linkOption['sources']);
                            if ($modifiedSources) {
                                $event->linkOptions[$i]['sources'] = $modifiedSources;
                            }
                        }
                    }
                }
            });
        }
    }

    private function registerAssetLocationsHandlers(): void
    {
        if (!$this->getSettings()->assetLocationsEnabled) {
            return;
        }

        Event::on(
            FieldLayout::class,
            FieldLayout::EVENT_DEFINE_NATIVE_FIELDS,
            function (DefineFieldLayoutFieldsEvent $event): void {
                $fieldLayout = $event->sender;
                if ($fieldLayout->type === Asset::class) {
                    $event->fields[] = AssetLocationsField::class;
                }
            }
        );

        Event::on(
            Asset::class,
            Asset::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function (RegisterElementTableAttributesEvent $event): void {
                $event->tableAttributes['used'] = [
                    'label' => Craft::t('app', 'In Use'),
                ];
            }
        );

        Event::on(
            Asset::class,
            Asset::EVENT_DEFINE_ATTRIBUTE_HTML,
            function (DefineAttributeHtmlEvent $event): void {
                if ($event->attribute !== 'used') {
                    return;
                }

                /** @var Asset $asset */
                $asset = $event->sender;
                $isUsed = $this->assetLocationsUsage->getUsage($asset);
                $event->html = $isUsed
                    ? '<span class="status live" title="This asset is used"></span><span class="visually-hidden">This asset is used</span>'
                    : '<span class="status pending" title="This asset may be used"></span><span class="visually-hidden">This asset may be used</span>';
                $event->handled = true;
            }
        );
    }

    private function registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = ColorAlpha::class;
            }
        );
    }

    private function getVolumeOptions(): array
    {
        $sourceOptions = [];
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $source) {
            $sourceOptions[] = ['label' => $source->name, 'value' => $source->id];
        }
        return $sourceOptions;
    }

    private function getFolderOptions(): array
    {
        $folderOptions = [];
        $assetTree = Craft::$app->getAssets()->getFolderTreeByVolumeIds(Craft::$app->getVolumes()->getAllVolumeIds());
        $this->getService()->getAssetFolders($assetTree, $folderOptions);
        return $folderOptions;
    }

}
