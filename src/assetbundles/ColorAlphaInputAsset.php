<?php

namespace Kdgraphics\BaseTemplate\assetbundles;

use craft\web\AssetBundle;

class ColorAlphaInputAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@kdgraphics/base-template/resources/dist';
        $this->css = [
            'css' . DIRECTORY_SEPARATOR . 'color-alpha-input.css',
        ];
        $this->js = [
            'js' . DIRECTORY_SEPARATOR . 'color-alpha-input.js',
        ];

        parent::init();
    }
}
