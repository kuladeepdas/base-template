<?php

namespace Kdgraphics\BaseTemplate\embeddedvideos\assetpreviews;

use Craft;
use craft\base\AssetPreviewHandler;
use Kdgraphics\BaseTemplate\embeddedvideos\Plugin as EmbeddedVideos;

/**
 * Asset preview handler for Embedded Videos.
 *
 * @package kdgraphics\base-template\embeddedvideos\assetpreviews
 * @author KD Graphics
 * @since 2.2.2
 */
class EmbeddedVideo extends AssetPreviewHandler
{
    /**
     * @inheritdoc
     */
    public function getPreviewHtml(array $variables = []): string
    {
        $embeddedVideo = EmbeddedVideos::$plugin->methods->getEmbeddedVideo($this->asset);

        if ($embeddedVideo !== null) {
            return Craft::$app->getView()->renderTemplate('base-template/embedded-videos/_previews/default', [
                'embeddedVideo' => $embeddedVideo,
                'showContent' => false,
                'inIframe' => false,
            ]);
        }

        return '';
    }
}
