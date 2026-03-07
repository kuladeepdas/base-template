<?php

namespace Inspire\Frontend\embeddedvideos\behaviors;

use craft\elements\Asset;
use Inspire\Frontend\embeddedvideos\models\EmbeddedVideo;
use Inspire\Frontend\embeddedvideos\Plugin as EmbeddedVideos;
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

