<?php

namespace Kdgraphics\BaseTemplate\models;

use craft\base\Model;

/**
 * @property-read array $autoloadBundles
 * @property-read array $manifestPath
 */
class Settings extends Model
{
    /** @var array */
    public array $autoloadBundles = [];

    /** @var string */
    public string $manifestPath = '@webroot/manifest.json';

    /** @var string */
    public string $lastAutoBuildSyncVersion = '';
    public bool $movedFeatureSettingsMigrated = false;

    // Expanded Singles
    public bool $expandSingles = true;
    public bool $redirectToEntry = false;

    // Image Resizer
    public bool $useGlobalSettings = true;
    public bool $enabled = true;
    public int $imageWidth = 2048;
    public int $imageHeight = 2048;
    public int $imageQuality = 100;
    public array $assetSourceSettings = [];
    public bool $skipLarger = true;
    public bool $nonDestructiveResize = false;

    // Embedded videos
    public string $referer = '';
    public array $parameters = [
        ['param' => 'maxwidth', 'value' => '1920'],
        ['param' => 'maxheight', 'value' => '1080'],
    ];
    public array $whitelist = [
        'youtu.be',
        'youtube.com',
        'youtube-nocookie.com',
        'ytimg.com',
        'vimeo.com',
        'vimeocdn.com',
        'player.vimeo.com',
    ];
    public array $extraWhitelist = [];
    public int $maxAssetNameLength = 50;
    public int $maxFileNameLength = 50;
    public int $cacheDuration = 300;
    public bool $showThumbnailsInCp = true;
    public bool $useYouTubeNoCookie = false;
    public bool $disableVimeoTracking = false;
    public bool $preventNonWhitelistedUploads = false;
    public bool $assetLocationsEnabled = true;

    public function rules(): array
    {
        return [
            ['referer', 'string'],
            ['parameters', 'safe'],
            [['whitelist', 'extraWhitelist'], 'each', 'rule' => ['string']],
            [['maxAssetNameLength', 'maxFileNameLength', 'cacheDuration', 'imageWidth', 'imageHeight', 'imageQuality'], 'integer'],
            [['showThumbnailsInCp', 'useYouTubeNoCookie', 'disableVimeoTracking', 'preventNonWhitelistedUploads', 'expandSingles', 'redirectToEntry', 'useGlobalSettings', 'enabled', 'skipLarger', 'nonDestructiveResize', 'movedFeatureSettingsMigrated', 'assetLocationsEnabled'], 'boolean'],
            ['assetSourceSettings', 'safe'],
        ];
    }
}
