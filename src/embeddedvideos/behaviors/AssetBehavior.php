<?php

namespace Kdgraphics\BaseTemplate\embeddedvideos\behaviors;

use craft\elements\Asset;
use Kdgraphics\BaseTemplate\embeddedvideos\models\EmbeddedVideo;
use Kdgraphics\BaseTemplate\embeddedvideos\Plugin as EmbeddedVideos;
use yii\base\Behavior;

class AssetBehavior extends Behavior
{
    public function getEmbedData(): ?EmbeddedVideo
    {
        $owner = $this->owner;
        if (!$owner instanceof Asset) {
            return null;
        }

        return EmbeddedVideos::$plugin->methods->getEmbeddedVideo($owner);
    }
}

