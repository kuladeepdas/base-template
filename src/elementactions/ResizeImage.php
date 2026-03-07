<?php
namespace Inspire\Frontend\elementactions;

use Inspire\Frontend\Frontend;

use Inspire\Frontend\assetbundles\ImageResizerAsset;
use Inspire\Frontend\models\Settings;

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
        return Craft::t('inspire-frontend', 'Resize image');
    }

    /**
     * @throws InvalidConfigException
     */
    public function getTriggerHtml(): ?string
    {
        /* @var Settings $settings */
        $settings = Frontend::$plugin->getSettings();
        $imageWidth = $settings->imageWidth;
        $imageHeight = $settings->imageHeight;
        $type = Json::encode(static::class);

        Craft::$app->getView()->registerAssetBundle(ImageResizerAsset::class);

        Craft::$app->getView()->registerJs('new Craft.InspireFrontend.ResizeElementAction(' .
            '"' . $imageWidth . '", ' .
            '"' . $imageHeight . '", '
            . $type .
            ');');

        return null;
    }
}