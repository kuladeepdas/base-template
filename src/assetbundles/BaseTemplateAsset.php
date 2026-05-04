<?php

namespace Kdgraphics\BaseTemplate\assetbundles;

use Craft;
use craft\web\AssetBundle;
use Kdgraphics\BaseTemplate\BaseTemplate;
use JsonException;

abstract class BaseTemplateAsset extends AssetBundle
{
    /** @var array|null */
    protected ?array $_manifest = null;

    /** @var array */
    public $cssOptions = [
        'appendTimestamp' => false
    ];

    /** @var array */
    public $jsOptions = [
        'appendTimestamp' => false
    ];

    /**
     * @return void
     */
    public function init(): void
    {
        $appendTimestamp = Craft::$app->getConfig()->getGeneral()->devMode;
        $this->cssOptions['appendTimestamp'] = $appendTimestamp;
        $this->jsOptions['appendTimestamp'] = $appendTimestamp;

        parent::init();

        $manifest = $this->getManifest();

        $js = $manifest[$this->frontendName . '.js'] ?? false;
        if ($js) {
            $this->js[] = [$js, 'type' => 'module'];
        }

        $css = $manifest[$this->frontendName . '.css'] ?? false;
        if ($css) {
            $this->css[] = $css;
        }
    }

    /**
     * @return array
     */
    protected function getManifest(): array
    {
        if (is_null($this->_manifest)) {
            $this->_manifest = [];

            $manifest = Craft::getAlias(BaseTemplate::$plugin->getSettings()->manifestPath);
            if (file_exists($manifest)) {
                try {
                    $decoded = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
                    $this->_manifest = is_array($decoded) ? $decoded : [];
                } catch (JsonException) {
                    $this->_manifest = [];
                }
            }
        }

        return $this->_manifest;
    }
}
