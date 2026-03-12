<?php

namespace Inspire\Frontend\assetbundles;

use craft\web\AssetBundle;

class ColorAlphaInputAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@inspire/frontend/resources/dist';
        $this->css = [
            'css' . DIRECTORY_SEPARATOR . 'color-alpha-input.css',
        ];
        $this->js = [
            'js' . DIRECTORY_SEPARATOR . 'color-alpha-input.js',
        ];

        parent::init();
    }
}
