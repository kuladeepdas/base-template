<?php
namespace Inspire\Frontend\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

class CpAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    public function init(): void
    {
        $this->sourcePath = '@inspire/frontend/resources/dist';

        $this->depends = [
            CraftCpAsset::class
        ];

        $this->css = [
            'css/inspire-ui.css',
        ];

        $this->js = [
            'js/inspire-ui.js'
        ];

        parent::init();
    }
}