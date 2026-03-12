<?php

namespace Inspire\Frontend\assetbundles;

use craft\web\AssetBundle;

class ColorAlphaSettingsAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@inspire/frontend/resources/dist';
        $this->css = [
            'css' . DIRECTORY_SEPARATOR . 'color-alpha-settings.css',
        ];
        $this->js = [
            'js' . DIRECTORY_SEPARATOR . 'color-alpha-settings.js',
        ];

        parent::init();
    }
}
