<?php
namespace Kdgraphics\BaseTemplate\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

use Kdgraphics\BaseTemplate\assetbundles\CpAsset as BaseTemplateCpAsset;

class ImageResizerAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    public function init(): void
    {
        $this->sourcePath = "@kdgraphics/base-template/resources/dist";

        $this->depends = [
            BaseTemplateCpAsset::class,
            CpAsset::class,
        ];

        $this->js = [
            'js/image-resizer.js',
        ];

        $this->css = [
            'css/image-resizer.css',
        ];

        parent::init();
    }
}
