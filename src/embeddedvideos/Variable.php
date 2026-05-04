<?php

namespace Kdgraphics\BaseTemplate\embeddedvideos;

use Craft;
use craft\elements\Asset;

use Kdgraphics\BaseTemplate\embeddedvideos\models\EmbeddedVideo;
use Kdgraphics\BaseTemplate\embeddedvideos\Plugin as EmbeddedVideos;

/**
 * Class Variable
 *
 * @package kdgraphics\base-template\embeddedvideos
 * @author KD Graphics
 * @author Benjamin Fleming
 * @since 1.0.0
 */
class Variable
{
    /**
     * Retrieves the embedded video model from an asset, if one exists.
     *
     * @param Asset $asset
     * @return EmbeddedVideo|null
     */
    public function get(?Asset $asset): ?EmbeddedVideo
    {
        return EmbeddedVideos::$plugin->methods->getEmbeddedVideo($asset);
    }

    /**
     * Creates an embedded video model from an array of property/value pairs.
     * Returns the model unless the array included any properties that aren't defined on the model.
     *
     * @param array $array
     * @return EmbeddedVideo|null
     * @since 2.10.0
     */
    public function create(array $array): ?EmbeddedVideo
    {
        return EmbeddedVideos::$plugin->methods->createEmbeddedVideo($array);
    }

}
