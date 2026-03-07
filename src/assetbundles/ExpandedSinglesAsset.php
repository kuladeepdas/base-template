<?php
namespace Inspire\Frontend\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

use Inspire\Frontend\assetbundles\CpAsset as InspireCpAsset;

class ExpandedSinglesAsset extends AssetBundle
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

        $this->css = [
            'css/expanded-singles.css',
        ];

        $this->js = [
            'js/expanded-singles.js',
        ];

        parent::init();
    }
}
