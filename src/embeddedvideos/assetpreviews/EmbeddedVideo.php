<?php

namespace Inspire\Frontend\embeddedvideos\assetpreviews;

use Craft;
use craft\base\AssetPreviewHandler;
use Inspire\Frontend\embeddedvideos\Plugin as EmbeddedVideos;

/**
 * Asset preview handler for Embedded Videos.
 *
 * @package inspire\embeddedvideos\assetpreviews
 * @author Inspire <plugins@inspire.com.au>
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
            return Craft::$app->getView()->renderTemplate('inspire-frontend/embedded-videos/_previews/default', [
                'embeddedVideo' => $embeddedVideo,
                'showContent' => false,
                'inIframe' => false,
            ]);
        }

        return '';
    }
}
