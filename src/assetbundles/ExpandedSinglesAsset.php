<?php
namespace Kdgraphics\BaseTemplate\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

use Kdgraphics\BaseTemplate\assetbundles\CpAsset as BaseTemplateCpAsset;

class ExpandedSinglesAsset extends AssetBundle
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

        $this->css = [
            'css/expanded-singles.css',
        ];

        $this->js = [
            'js/expanded-singles.js',
        ];

        parent::init();
    }
}
