<?php

namespace Inspire\Frontend\assetbundles;

use craft\web\AssetBundle;

/**
 * Class EmbeddedVideosPreviewAsset
 *
 * @package inspire\base\assetbundles
 * @author Inspire <plugins@inspire.com.au>
 * @since 3.1.0
 */
class EmbeddedVideosPreviewAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@inspire/frontend/resources/dist';
        $this->css = [
            'css' . DIRECTORY_SEPARATOR . 'embeddedvideos-preview.css',
        ];
        $this->js = [
            'js' . DIRECTORY_SEPARATOR . 'embeddedvideos-preview.js',
        ];
        
        parent::init();
    }
}
