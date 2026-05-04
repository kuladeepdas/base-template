<?php
namespace Kdgraphics\BaseTemplate\elementactions;

use Kdgraphics\BaseTemplate\BaseTemplate;

use Kdgraphics\BaseTemplate\assetbundles\ImageResizerAsset;
use Kdgraphics\BaseTemplate\models\Settings;

use Craft;
use craft\base\ElementAction;
use craft\helpers\Json;

use yii\base\InvalidConfigException;

class ResizeImage extends ElementAction
{
    // Public Methods
    // =========================================================================

    public function getTriggerLabel(): string
    {
        return Craft::t('base-template', 'Resize image');
    }

    /**
     * @throws InvalidConfigException
     */
    public function getTriggerHtml(): ?string
    {
        /* @var Settings $settings */
        $settings = BaseTemplate::$plugin->getSettings();
        $imageWidth = $settings->imageWidth;
        $imageHeight = $settings->imageHeight;
        $type = Json::encode(static::class);

        Craft::$app->getView()->registerAssetBundle(ImageResizerAsset::class);

        Craft::$app->getView()->registerJs('new Craft.BaseTemplate.ResizeElementAction(' .
            '"' . $imageWidth . '", ' .
            '"' . $imageHeight . '", '
            . $type .
            ');');

        return null;
    }
}