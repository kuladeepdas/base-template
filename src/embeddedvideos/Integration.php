<?php

namespace Kdgraphics\BaseTemplate\embeddedvideos;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\events\DefineAssetThumbUrlEvent;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineMenuItemsEvent;
use craft\events\RegisterAssetFileKindsEvent;
use craft\events\RegisterElementHtmlAttributesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\services\Assets;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use craft\events\TemplateEvent;
use Kdgraphics\BaseTemplate\BaseTemplate;
use Kdgraphics\BaseTemplate\embeddedvideos\assetpreviews\EmbeddedVideo as EmbeddedVideoPreview;
use Kdgraphics\BaseTemplate\assetbundles\EmbeddedVideosMainAsset;
use Kdgraphics\BaseTemplate\embeddedvideos\behaviors\AssetBehavior;
use Kdgraphics\BaseTemplate\embeddedvideos\models\EmbeddedVideo;
use Kdgraphics\BaseTemplate\embeddedvideos\Variable;
use yii\base\Event;
use yii\i18n\PhpMessageSource;

class Integration
{
    private BaseTemplate $basePlugin;
    private string $defaultThumbnailUrl = '';
    private bool $cpResourcesRegistered = false;

    public function __construct(BaseTemplate $basePlugin)
    {
        $this->basePlugin = $basePlugin;
    }

    public function register(): void
    {
        $this->registerTranslations();
        $this->registerFileKinds();
        $this->configureTemplateVariable();
        $this->registerAssetBehavior();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->configureCpResources();
            $this->configureAssetThumbnails();
            $this->registerPreviewHandler();
            $this->registerSaveListener();
            $this->registerDeleteListener();
            $this->registerLinkAction();

            if ($this->basePlugin->getSettings()->showThumbnailsInCp) {
                $this->configureAssetIndexAttributes();
            } else {
                $this->configureAssetIndexAttributesNoThumbnail();
            }
        }
    }

    private function registerTranslations(): void
    {
        $baseConfig = [
            'class' => PhpMessageSource::class,
            'sourceLanguage' => 'en-US',
            'basePath' => $this->basePlugin->getBasePath() . '/embeddedvideos/translations',
            'fileMap' => [
                'embeddedvideos' => 'embeddedvideos.php',
                'embedded-videos' => 'embeddedvideos.php',
            ],
        ];

        Craft::$app->i18n->translations['embeddedvideos'] = $baseConfig;
        Craft::$app->i18n->translations['embedded-videos'] = $baseConfig;
    }

    private function registerFileKinds(): void
    {
        Event::on(
            AssetsHelper::class,
            AssetsHelper::EVENT_REGISTER_FILE_KINDS,
            function(RegisterAssetFileKindsEvent $event): void {
                $event->fileKinds['embeddedVideo'] = [
                    'label' => Craft::t('embeddedvideos', 'Embedded Video'),
                    'extensions' => ['json'],
                ];
            }
        );
    }

    private function configureTemplateVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event): void {
                $event->sender->set('embeddedVideos', Variable::class);
            }
        );
    }

    private function registerAssetBehavior(): void
    {
        Event::on(
            Asset::class,
            Asset::EVENT_DEFINE_BEHAVIORS,
            static function(DefineBehaviorsEvent $event): void {
                $event->behaviors['embeddedVideoAsset'] = AssetBehavior::class;
            }
        );
    }

    private function configureCpResources(): void
    {
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_TEMPLATE,
            function(TemplateEvent $event): void {
                if ($this->cpResourcesRegistered) {
                    return;
                }

                $prevent = $this->basePlugin->getSettings()->preventNonWhitelistedUploads ? 'true' : 'false';
                $viewService = Craft::$app->getView();
                $viewService->registerAssetBundle(EmbeddedVideosMainAsset::class);
                $viewService->registerJs("EmbeddedVideos.preventNonWhitelistedUploads = $prevent");
                $this->defaultThumbnailUrl = Craft::$app->getAssetManager()->getPublishedUrl('@kdgraphics/base-template/embeddedvideos/resources/default-thumb.svg', true);
                $this->cpResourcesRegistered = true;
            }
        );
    }

    private function configureAssetThumbnails(): void
    {
        Event::on(
            Assets::class,
            Assets::EVENT_DEFINE_THUMB_URL,
            function(DefineAssetThumbUrlEvent $event): void {
                if (!$this->isEmbeddedVideoKind($event->asset->kind)) {
                    return;
                }

                if (!$this->basePlugin->getSettings()->showThumbnailsInCp) {
                    $event->url = $this->defaultThumbnailUrl;
                    return;
                }

                $embeddedVideo = $this->basePlugin->getEmbeddedVideos()->getEmbeddedVideo($event->asset);
                $thumbSize = max($event->width, $event->height);
                $thumbnailUrl = $embeddedVideo ? $this->getThumbnailUrl($embeddedVideo, $thumbSize) : null;

                if ($thumbnailUrl) {
                    $event->url = $thumbnailUrl;
                }
            }
        );
    }

    private function registerPreviewHandler(): void
    {
        Event::on(
            Assets::class,
            Assets::EVENT_REGISTER_PREVIEW_HANDLER,
            function(\craft\events\AssetPreviewEvent $event): void {
                if ($this->isEmbeddedVideoKind($event->asset->kind)) {
                    $event->previewHandler = new EmbeddedVideoPreview($event->asset);
                }
            }
        );
    }

    private function registerSaveListener(): void
    {
        Event::on(Element::class, Element::EVENT_AFTER_SAVE, function(Event $event): void {
            if ($event->sender instanceof Asset && $this->isEmbeddedVideoKind($event->sender->kind)) {
                $contents = $event->sender->getContents();
                if ($this->basePlugin->getEmbeddedVideos()->isValidEmbeddedVideoData(Json::decodeIfJson($contents))) {
                    Craft::$app->getCache()->add($this->basePlugin->getEmbeddedVideos()->getCachedAssetKey($event->sender), $contents, 0);
                }
            }
        });
    }

    private function registerDeleteListener(): void
    {
        Event::on(Element::class, Element::EVENT_AFTER_DELETE, function(Event $event): void {
            if ($event->sender instanceof Asset) {
                Craft::$app->getCache()->delete($this->basePlugin->getEmbeddedVideos()->getCachedAssetKey($event->sender));
            }
        });
    }

    private function registerLinkAction(): void
    {
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_ACTION_MENU_ITEMS,
            function(DefineMenuItemsEvent $event): void {
                if (
                    !$event->sender instanceof Asset ||
                    ($embeddedVideo = $this->basePlugin->getEmbeddedVideos()->getEmbeddedVideo($event->sender)) === null
                ) {
                    return;
                }

                $event->items[] = [
                    'id' => 'view-embedded',
                    'icon' => 'share',
                    'label' => Craft::t('embeddedvideos', 'View embedded video'),
                    'url' => $embeddedVideo->url,
                    'attributes' => [
                        'target' => '_blank',
                    ],
                ];
            }
        );
    }

    private function configureAssetIndexAttributesNoThumbnail(): void
    {
        Event::on(
            Asset::class,
            Asset::EVENT_REGISTER_HTML_ATTRIBUTES,
            function(RegisterElementHtmlAttributesEvent $event): void {
                if ($this->isEmbeddedVideoKind($event->sender->kind)) {
                    $event->htmlAttributes['data-embedded-video'] = null;
                }
            }
        );
    }

    private function configureAssetIndexAttributes(): void
    {
        $newAttributes = [
            'provider' => 'Provider',
        ];

        Event::on(
            Asset::class,
            Asset::EVENT_REGISTER_HTML_ATTRIBUTES,
            function(RegisterElementHtmlAttributesEvent $event): void {
                $embeddedVideo = $this->basePlugin->getEmbeddedVideos()->getEmbeddedVideo($event->sender);
                if ($embeddedVideo && $embeddedVideo->code && $embeddedVideo->getIsSafe()) {
                    $event->htmlAttributes['data-embedded-video'] = $embeddedVideo->aspectRatio;
                }
            }
        );

        Event::on(
            Asset::class,
            Asset::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function(RegisterElementTableAttributesEvent $event) use ($newAttributes): void {
                foreach ($newAttributes as $attributeHandle => $attributeLabel) {
                    $event->tableAttributes[$attributeHandle] = [
                        'label' => Craft::t('embeddedvideos', $attributeLabel),
                    ];
                }
            }
        );

        Event::on(
            Asset::class,
            Asset::EVENT_DEFINE_ATTRIBUTE_HTML,
            function(DefineAttributeHtmlEvent $event) use ($newAttributes): void {
                if (array_key_exists($event->attribute, $newAttributes)) {
                    $event->html = '';
                }

                $embeddedVideo = $this->basePlugin->getEmbeddedVideos()->getEmbeddedVideo($event->sender);
                $html = $embeddedVideo ? $this->getTableAttributeHtml($embeddedVideo, $event->attribute) : null;

                if ($html !== null) {
                    $event->html = $html;
                }
            }
        );
    }

    private function isEmbeddedVideoKind(?string $kind): bool
    {
        return in_array($kind, [Asset::KIND_JSON, 'embeddedVideo'], true);
    }

    private function getThumbnailUrl(EmbeddedVideo $embeddedVideo, int $size, int $maxSize = 200): string|false
    {
        if (!$this->basePlugin->getSettings()->showThumbnailsInCp) {
            return $this->defaultThumbnailUrl;
        }

        $url = false;
        $image = $embeddedVideo->getImageToSize($size);

        if ($image && UrlHelper::isAbsoluteUrl($image['url'])) {
            $imageSize = max($image['width'], $image['height']);
            if ($size <= $maxSize || $imageSize > $maxSize) {
                $url = $image['url'];
            }
        } else {
            $url = $embeddedVideo->image;
        }

        return $url;
    }

    private function getTableAttributeHtml(EmbeddedVideo $embeddedVideo, string $attribute): ?string
    {
        return match ($attribute) {
            'provider' => $embeddedVideo->providerName
                ? sprintf(
                    "<span class='embedded-videos_label'>%s%s%s</span>",
                    $embeddedVideo->providerUrl ? "<a href='$embeddedVideo->providerUrl' target='_blank' rel='noopener'>" : '',
                    $embeddedVideo->providerName,
                    $embeddedVideo->providerUrl ? '</a>' : ''
                )
                : null,
            'kind' => $embeddedVideo->type
                ? Craft::t('embeddedvideos', ucfirst($embeddedVideo->type))
                : Craft::t('embeddedvideos', 'Embedded video'),
            'width' => $embeddedVideo->imageWidth ? $embeddedVideo->imageWidth . 'px' : null,
            'height' => $embeddedVideo->imageHeight ? $embeddedVideo->imageHeight . 'px' : null,
            'imageSize' => ($embeddedVideo->imageWidth && $embeddedVideo->imageHeight)
                ? $embeddedVideo->imageWidth . ' × ' . $embeddedVideo->imageHeight
                : null,
            'link' => $embeddedVideo->url
                ? "<a href='$embeddedVideo->url' target='_blank' rel='noopener' data-icon='world' title='" . Craft::t('app', 'Visit webpage') . "'></a>"
                : null,
            default => null,
        };
    }
}
