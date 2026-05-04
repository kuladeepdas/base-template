<?php
namespace Kdgraphics\BaseTemplate\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

class CpAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    public function init(): void
    {
        $this->sourcePath = '@kdgraphics/base-template/resources/dist';

        $this->depends = [
            CraftCpAsset::class
        ];

        $this->css = [
            'css/base-template-ui.css',
        ];

        $this->js = [
            'js/base-template-ui.js'
        ];

        parent::init();
    }
}
