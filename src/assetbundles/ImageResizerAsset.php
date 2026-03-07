<?php
namespace Inspire\Frontend\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

use Inspire\Frontend\assetbundles\CpAsset as InspireCpAsset;

class ImageResizerAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    public function init(): void
    {
        $this->sourcePath = "@inspire/frontend/resources/dist";

        $this->depends = [
            InspireCpAsset::class,
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
