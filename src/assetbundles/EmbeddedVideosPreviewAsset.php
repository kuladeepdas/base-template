<?php

namespace Kdgraphics\BaseTemplate\assetbundles;

use craft\web\AssetBundle;

/**
 * Class EmbeddedVideosPreviewAsset
 *
 * @package kdgraphics\base-template\assetbundles
 * @author KD Graphics
 * @since 3.1.0
 */
class EmbeddedVideosPreviewAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@kdgraphics/base-template/resources/dist';
        $this->css = [
            'css' . DIRECTORY_SEPARATOR . 'embeddedvideos-preview.css',
        ];
        $this->js = [
            'js' . DIRECTORY_SEPARATOR . 'embeddedvideos-preview.js',
        ];
        
        parent::init();
    }
}
