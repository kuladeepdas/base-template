<?php

namespace Kdgraphics\BaseTemplate\assetbundles;

use craft\web\AssetBundle;

class ColorAlphaSettingsAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@kdgraphics/base-template/resources/dist';
        $this->css = [
            'css' . DIRECTORY_SEPARATOR . 'color-alpha-settings.css',
        ];
        $this->js = [
            'js' . DIRECTORY_SEPARATOR . 'color-alpha-settings.js',
        ];

        parent::init();
    }
}
