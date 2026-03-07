<?php

namespace Inspire\Frontend\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Class EmbeddedVideosMainAsset
 *
 * @package inspire\base\assetbundles
 * @author Inspire <plugins@inspire.com.au>
 * @since 3.1.0
 */
class EmbeddedVideosMainAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@inspire/frontend/resources/dist';
        $this->depends = [
            CpAsset::class,
        ];
        $this->css = [
            'css' . DIRECTORY_SEPARATOR . 'embeddedvideos-main.css',
        ];
        $this->js = [
            'js' . DIRECTORY_SEPARATOR . 'embeddedvideos-main.js',
        ];

        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function registerAssetFiles($view): void
    {
        $view->registerTranslations('embeddedvideos', [
            'As a security measure embed codes will not be shown.',
            'Embed',
            'Replace',
            'This information is coming from an untrusted source.',
        ]);

        parent::registerAssetFiles($view);
    }
}
