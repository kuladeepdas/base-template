<?php

namespace Inspire\Frontend\embeddedvideos;

use Craft;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\FileHelper;
use craft\helpers\Html as HtmlHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use craft\models\VolumeFolder;
use DOMDocument;
use Inspire\Frontend\embeddedvideos\models\EmbeddedVideo;
use Inspire\Frontend\embeddedvideos\Plugin as EmbeddedVideos;
use Twig\Markup as TwigMarkup;
use yii\base\Component;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\InvalidArgumentException;

/**
 * Class Service
 *
 * @package inspire\embeddedvideos
 * @author Inspire <plugins@inspire.com.au>
 * @author Benjamin Fleming
 * @since 1.0.0
 */
class Service extends Component
{
    private array $embeddedVideoData = [];

    /**
     * Requests embed data from a URL.
     *
     * @param string $url
     * @param bool $checkCache Whether to check for data associated with the URL that's been stored in Craft's cache
     * @return EmbeddedVideo
     */
    public function requestUrl(string $url, bool $checkCache = true, bool $enforceWhitelist = true): EmbeddedVideo
    {
        // youtu.be links returning supported browser page instead of the video page
        if (preg_match('/:\/\/youtu.be\/([A-Za-z0-9_-]{11})(\?|$)/', $url, $matches)) {
            $url = sprintf('https://www.youtube.com/watch?v=%s', $matches[1]);
        }

        $pluginSettings = EmbeddedVideos::$plugin->getSettings();
        $cacheService = Craft::$app->getCache();
        $cacheKey = 'embeddedvideo:' . $url;
        $embeddedVideo = $checkCache ? $cacheService->get($cacheKey) : null;

        if (!$embeddedVideo) {
            $pluginSettings = EmbeddedVideos::$plugin->getSettings();
            $array = $this->_getDataFromEmbed($url);
            $embeddedVideo = $this->createEmbeddedVideo($array);

            $cacheService->set($cacheKey, $embeddedVideo, $pluginSettings->cacheDuration);
        }

        if ($enforceWhitelist && $pluginSettings->preventNonWhitelistedUploads && !$embeddedVideo->getIsSafe()) {
            throw new Exception(Craft::t('embeddedvideos', 'Tried to upload embedded video with non-whitelisted provider'));
        }

        return $embeddedVideo;
    }

    private function _getDataFromEmbed(string $url): array
    {
        $pluginSettings = EmbeddedVideos::$plugin->getSettings();
        $embedSettings = ['oembed:query_parameters' => []];
        $clientSettings = [];
        $headers = array_filter([
            'Referer' => $pluginSettings->referer ? Craft::parseEnv($pluginSettings->referer) : null,
        ]);

        if (!empty($pluginSettings->parameters)) {
            foreach ($pluginSettings->parameters as $parameter) {
                $param = $parameter['param'] ?? null;
                $value = $parameter['value'] ?? null;
                if (is_string($param) && $param !== '' && is_string($value)) {
                    $embedSettings['oembed:query_parameters'][$param] = $value;
                }
            }
        }

        return $this->_getDataFromSupportedProvider($url, $headers, $clientSettings, $embedSettings);
    }

    /**
     * Checks a URL against the whitelist set on the plugin settings model.
     *
     * @param string $url
     * @return bool Whether the URL is in the whitelist.
     */
    public function checkWhitelist(string $url): bool
    {
        $pluginSettings = EmbeddedVideos::$plugin->getSettings();
        $whitelist = array_merge($pluginSettings->whitelist, $pluginSettings->extraWhitelist);

        foreach ($whitelist as $whitelistUrl) {
            if ($whitelistUrl) {
                $pattern = explode('*', $whitelistUrl);
                $pattern = array_map('preg_quote', $pattern);
                $pattern = implode('[a-z][a-z0-9]*', $pattern);
                $pattern = "%^(https?:)?//([a-z0-9\-]+\\.)?$pattern([:/].*)?$%";

                if (preg_match($pattern, $url)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Retrieves an embedded video model from an asset element, if one exists.
     *
     * @param Asset $asset
     * @return EmbeddedVideo|null
     * @throws \yii\base\InvalidConfigException
     * @throws \craft\errors\AssetException
     */
    public function getEmbeddedVideo(?Asset $asset): ?EmbeddedVideo
    {
        // Embedded videos are stored as JSON and can be tagged with custom embedded video kinds.
        if (!in_array($asset?->kind, [Asset::KIND_JSON, 'embeddedVideo'], true)) {
            return null;
        }

        // If the embedded video data has already been loaded this request, there's no need to reload it
        if (isset($this->embeddedVideoData[$asset->uid])) {
            return $this->embeddedVideoData[$asset->uid];
        }

        $pluginSettings = EmbeddedVideos::$plugin->getSettings();
        $embeddedVideo = null;

        try {
            $decodedJson = $this->_getAssetContents($asset);

            // Make YouTube iframes use the nocookie embed URL if the relevant setting is enabled
            if ($decodedJson['providerName'] === 'YouTube' && $pluginSettings->useYouTubeNoCookie) {
                $decodedJson['code'] = preg_replace(
                    '/src="https?:\/\/www.youtube.com\/embed\/(.+)\?/',
                    'src="https://www.youtube-nocookie.com/embed/$1?',
                    $decodedJson['code']
                );
            }

            // Make Vimeo iframes use the nocookie embed URL if the relevant setting is enabled
            if ($decodedJson['providerName'] === 'Vimeo' && $pluginSettings->disableVimeoTracking) {
                $oldSrc = HtmlHelper::parseTagAttributes($decodedJson['code'])['src'];
                $newSrc = HtmlHelper::decode(UrlHelper::urlWithParams($oldSrc, ['dnt' => '1']));
                $decodedJson['code'] = HtmlHelper::modifyTagAttributes($decodedJson['code'], ['src' => $newSrc]);
            }

            if (is_array($decodedJson)) {
                $embeddedVideo = $this->createEmbeddedVideo($decodedJson);
                $this->embeddedVideoData[$asset->uid] = $embeddedVideo;
            }
        } catch (\Throwable $e) {
            // Ignore errors and assume it's not an embedded video
            $embeddedVideo = null;
        }

        return $embeddedVideo;
    }

    /**
     * Returns whether the given array represents valid data for creating an EmbeddedVideo.
     *
     * @since 2.3.0
     * @param array $array
     * @return bool
     */
    public function isValidEmbeddedVideoData(array $array): bool
    {
        return $this->createEmbeddedVideo($array) !== null;
    }

    /**
     * Creates an embedded video model from an array of property/value pairs.
     * Returns the model unless the array included any properties that aren't defined on the model.
     *
     * @param array $array
     * @return EmbeddedVideo|null
     */
    public function createEmbeddedVideo(array $array): ?EmbeddedVideo
    {
        $isLegacy = isset($array['__embeddedvideo__']);
        if ($isLegacy) {
            $array = $this->_convertFromLegacy($array);
        }

        foreach (array_keys($array) as $key) {
            if (
                !property_exists(EmbeddedVideo::class, $key) &&
                !in_array($key, EmbeddedVideo::deprecatedProperties())
            ) {
                return null;
            }
        }

        if (isset($array['code'])) {
            $code = $array['code'] instanceof TwigMarkup ? (string)$array['code'] : (is_string($array['code']) ? $array['code'] : '');
            $array['code'] = empty($code) ? null : Template::raw($code);
        }

        // Attempts to extract missing dimensional properties from the embed code
        $dimensions = $this->_getDimensions($array);
        $array['width'] = $dimensions[0] ?: null;
        $array['height'] = $dimensions[1] ?: null;

        // Sets aspect ratio if it's missing
        if (!isset($array['aspectRatio']) && isset($array['width']) && isset($array['height'])) {
            $array['aspectRatio'] = $array['height'] / $array['width'] * 100;
        }

        // Correct invalid types to similar, valid types
        if (isset($array['type']) && $array['type'] === 'photo') {
            $array['type'] = 'image';
        }

        $embeddedVideo = new EmbeddedVideo($array);

        return $embeddedVideo->validate() ? $embeddedVideo : null;
    }

    /**
     * Creates an asset element ready to be saved from an embedded video model.
     *
     * @param EmbeddedVideo $embeddedVideo
     * @param VolumeFolder $folder The folder to save the asset to.
     * @return Asset
     * @throws \craft\errors\AssetLogicException
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     */
    public function createAsset(EmbeddedVideo $embeddedVideo, VolumeFolder $folder): Asset
    {
        $assetsService = Craft::$app->getAssets();
        $pluginSettings = EmbeddedVideos::$plugin->getSettings();

        $tempFilePath = Assets::tempFilePath();
        $fileContents = Json::encode($embeddedVideo,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        FileHelper::writeToFile($tempFilePath, $fileContents);

        // Ensure the title contains no emoji
        $assetTitle = StringHelper::replaceMb4($embeddedVideo->title ?: $embeddedVideo->url, '');

        $fileName = Assets::prepareAssetName($assetTitle, false);
        $fileName = str_replace('.', '', $fileName);
        $fileName = $fileName ?: 'embedded-video';
        $fileName = StringHelper::safeTruncate($fileName, $pluginSettings->maxFileNameLength) . '.json';
        $fileName = $assetsService->getNameReplacementInFolder($fileName, $folder->id);

        $asset = new Asset();
        $asset->title = StringHelper::safeTruncate($assetTitle, $pluginSettings->maxAssetNameLength);
        $asset->tempFilePath = $tempFilePath;
        $asset->filename = $fileName;
        $asset->newFolderId = $folder->id;
        $asset->volumeId = $folder->volumeId;
        $asset->uploaderId = Craft::$app->getUser()->getId();
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        return $asset;
    }

    /**
     * Checks an embedded video embed code for URL's that are safe (or in other words, are whitelisted).
     *
     * @param EmbeddedVideo $embeddedVideo
     * @return bool
     */
    public function isEmbedSafe(EmbeddedVideo $embeddedVideo): bool
    {
        $isSafe = $this->checkWhitelist($embeddedVideo->url);

        if ($isSafe) {
            $dom = $this->getEmbedCode($embeddedVideo);

            if ($dom) {
                foreach ($dom->getElementsByTagName('iframe') as $iframeElement) {
                    $href = $iframeElement->getAttribute('href');
                    $isSafe = $isSafe && (!$href || $this->checkWhitelist($href));
                }

                foreach ($dom->getElementsByTagName('script') as $scriptElement) {
                    $src = $scriptElement->getAttribute('src');
                    $content = $scriptElement->textContent;

                    // Inline scripts are impossible to analyse for safety, so just assume they're all evil
                    $isSafe = $isSafe && !$content && (!$src || $this->checkWhitelist($src));
                }
            }
        }

        return $isSafe;
    }

    /**
     * Gets the embed code as DOM, if one exists and is valid.
     *
     * @param EmbeddedVideo $embeddedVideo
     * @return DOMDocument|null
     */
    public function getEmbedCode(EmbeddedVideo $embeddedVideo): ?DOMDocument
    {
        $dom = null;

        if ($embeddedVideo->code) {
            $errors = libxml_use_internal_errors(true);
            $entities = libxml_disable_entity_loader(true);

            try {
                $dom = new DOMDocument();
                $code = "<div>$embeddedVideo->code</div>";
                $isHtml = $dom->loadHTML($code, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

                if (!$isHtml) {
                    throw new ErrorException();
                }
            } catch (ErrorException $e) {
                // Corrupted code property, likely due to invalid HTML.
                $dom = null;
            } finally {
                libxml_use_internal_errors($errors);
                libxml_disable_entity_loader($entities);
            }
        }

        return $dom;
    }

    /**
     * Gets the HTML for the embedded video.
     * This method automatically checks if the embed code is safe to use. If it is, then the embed code is returned.
     * Otherwise, if the embedded video is not a "link" type and it has an image, an <img> tag is returned. Otherwise,
     * an <a> link tag is returned.
     *
     * @param EmbeddedVideo $embeddedVideo
     * @return TwigMarkup
     */
    public function getEmbedHtml(EmbeddedVideo $embeddedVideo): TwigMarkup
    {
        if ($embeddedVideo->code && $embeddedVideo->getIsSafe()) {
            $html = $embeddedVideo->code;
        } elseif ($embeddedVideo->type !== 'link' && $embeddedVideo->image) {
            $html = Template::raw("<img src=\"$embeddedVideo->image\" alt=\"$embeddedVideo->title\" width=\"$embeddedVideo->imageWidth\" height=\"$embeddedVideo->imageHeight\">");
        } else {
            $html = Template::raw("<a href=\"$embeddedVideo->url\" target=\"_blank\" rel=\"noopener\">$embeddedVideo->title</a>");
        }

        return $html;
    }

    /**
     * Returns the image from an embedded video closest to some size.
     * It favours images that most minimally exceed the supplied size.
     *
     * @param EmbeddedVideo $embeddedVideo
     * @param int $size
     * @return array|null
     */
    public function getImageToSize(EmbeddedVideo $embeddedVideo, int $size): ?array
    {
        return is_array($embeddedVideo->images) ?
            $this->_getImageToSize($embeddedVideo->images, $size) : null;
    }

    /**
     * Returns the provider icon from an embedded video closest to some size.
     * It favours icons that most minimally exceed the supplied size.
     *
     * @param EmbeddedVideo $embeddedVideo
     * @param int $size
     * @return array|null
     */
    public function getProviderIconToSize(EmbeddedVideo $embeddedVideo, int $size): ?array
    {
        return is_array($embeddedVideo->providerIcons) ?
            $this->_getImageToSize($embeddedVideo->providerIcons, $size) : null;
    }

    /**
     * Gets the width/height of an embedded video.
     * Attempts to extract missing dimensional properties from the embed code.
     *
     * @param array $array
     * @return array
     */
    private function _getDimensions(array $array): array
    {
        $embeddedVideo = new EmbeddedVideo($array);
        $width = $embeddedVideo->width;
        $height = $embeddedVideo->height;

        if (!$width || !$height) {
            $dom = $this->getEmbedCode($embeddedVideo);

            if ($dom) {
                $iframeElement = $dom->getElementsByTagName('iframe')->item(0);

                if ($iframeElement) {
                    $width = $iframeElement->getAttribute('width');
                    $height = $iframeElement->getAttribute('height');
                    $style = $iframeElement->getAttribute('style');

                    $matches = [];
                    $matchCount = preg_match_all('/(width|height):\s*([0-9]+(\.[0-9]+)?)px/i', $style, $matches);

                    for ($i = 0; $i < $matchCount; $i++) {
                        $styleProperty = strtolower($matches[1][$i]);
                        $styleValue = $matches[2][$i];

                        switch ($styleProperty) {
                            case 'width':
                                $width = $styleValue;
                                break;
                            case 'height':
                                $height = $styleValue;
                                break;
                        }
                    }

                    $width = $width ? floatval($width) : null;
                    $height = $height ? floatval($height) : null;
                } elseif (($imgElement = $dom->getElementsByTagName('img')->item(0)) !== null) {
                    $imageInfo = getimagesize($imgElement->getAttribute('src'));
                    $width = $imageInfo[0];
                    $height = $imageInfo[1];
                }
            }
        }

        // TikTok sets the width and height as '100%', which Embedded Videos doesn't really like. Just remove them.
        if ($width === '100%' && $height === '100%') {
            $width = null;
            $height = null;
        }

        return [$width, $height];
    }

    /**
     * Helper method for retrieving an image closest to some size.
     *
     * @param array $images
     * @param int $size
     * @return array|null
     */
    private function _getImageToSize(array $images, int $size): ?array
    {
        $selectedImage = null;
        $selectedSize = 0;

        foreach ($images as $image) {
            if (is_array($image)) {
                $imageWidth = isset($image['width']) && is_numeric($image['width']) ? $image['width'] : 0;
                $imageHeight = isset($image['height']) && is_numeric($image['height']) ? $image['height'] : 0;
                $imageSize = max($imageWidth, $imageHeight);

                if (
                    !$selectedImage ||
                    ($selectedSize < $size && $imageSize > $selectedSize) ||
                    ($selectedSize > $imageSize && $imageSize > $size)
                ) {
                    $selectedImage = $image;
                    $selectedSize = $imageSize;
                }
            }
        }

        return $selectedImage;
    }

    private function _getDataFromSupportedProvider(
        string $url,
        array $headers,
        array $clientSettings,
        array $embedSettings
    ): array
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));

        if (str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be')) {
            return $this->_requestOEmbed(
                'https://www.youtube.com/oembed',
                $url,
                'YouTube',
                'https://www.youtube.com',
                $headers,
                $clientSettings,
                $embedSettings
            );
        }

        if (str_contains($host, 'vimeo.com')) {
            // Vimeo oEmbed can reject tracking/query params on shared URLs.
            $url = $this->_normalizeVimeoUrl($url);

            return $this->_requestOEmbed(
                'https://vimeo.com/api/oembed.json',
                $url,
                'Vimeo',
                'https://vimeo.com',
                $headers,
                $clientSettings,
                $embedSettings
            );
        }

        throw new Exception('Only public YouTube and Vimeo URLs are supported.');
    }

    private function _normalizeVimeoUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $host = strtolower((string)$parts['host']);
        if (!str_contains($host, 'vimeo.com')) {
            return $url;
        }

        $scheme = !empty($parts['scheme']) ? $parts['scheme'] : 'https';
        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';

        return sprintf('%s://%s%s', $scheme, $host, $path);
    }

    private function _requestOEmbed(
        string $endpoint,
        string $url,
        string $providerName,
        string $providerUrl,
        array $headers,
        array $clientSettings,
        array $embedSettings
    ): array {
        $query = array_merge(
            ['url' => $url, 'format' => 'json'],
            $embedSettings['oembed:query_parameters'] ?? []
        );

        $requestOptions = [
            'headers' => $headers,
            'query' => $query,
            'allow_redirects' => true,
            'http_errors' => false,
            'timeout' => isset($clientSettings['timeout']) ? (float)$clientSettings['timeout'] : 15,
            'connect_timeout' => isset($clientSettings['connect_timeout']) ? (float)$clientSettings['connect_timeout'] : 10,
        ];

        if (!empty($clientSettings['user_agent'])) {
            $requestOptions['headers']['User-Agent'] = (string)$clientSettings['user_agent'];
        }

        $client = Craft::createGuzzleClient();
        $response = $client->get($endpoint, $requestOptions);
        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new Exception(sprintf('Failed to fetch oEmbed data (HTTP %d).', $statusCode));
        }

        $payload = Json::decode((string)$response->getBody());
        if (!is_array($payload)) {
            throw new Exception('Invalid oEmbed response payload.');
        }

        $title = trim((string)($payload['title'] ?? ''));
        $resolvedUrl = trim((string)($payload['url'] ?? $url));
        $type = strtolower(trim((string)($payload['type'] ?? 'rich')));
        $type = $type === 'photo' ? 'image' : $type;
        $type = in_array($type, ['link', 'image', 'video', 'rich'], true) ? $type : 'rich';
        $html = (string)($payload['html'] ?? '');
        $thumb = trim((string)($payload['thumbnail_url'] ?? ''));
        $width = is_numeric($payload['width'] ?? null) ? (int)$payload['width'] : null;
        $height = is_numeric($payload['height'] ?? null) ? (int)$payload['height'] : null;
        $thumbWidth = is_numeric($payload['thumbnail_width'] ?? null) ? (int)$payload['thumbnail_width'] : null;
        $thumbHeight = is_numeric($payload['thumbnail_height'] ?? null) ? (int)$payload['thumbnail_height'] : null;

        return [
            'title' => $title !== '' ? $title : $resolvedUrl,
            'description' => trim((string)($payload['description'] ?? '')),
            'url' => $resolvedUrl,
            'type' => $type,
            'image' => $thumb,
            'imageWidth' => $thumbWidth,
            'imageHeight' => $thumbHeight,
            'images' => $thumb !== '' ? [[
                'url' => $thumb,
                'width' => $thumbWidth,
                'height' => $thumbHeight,
                'size' => (int)($thumbWidth ?? 0) * (int)($thumbHeight ?? 0),
                'mime' => null,
            ]] : [],
            'code' => Template::raw($html),
            'width' => $width,
            'height' => $height,
            'aspectRatio' => ($width && $height) ? ($height / $width * 100) : null,
            'authorName' => trim((string)($payload['author_name'] ?? '')),
            'authorUrl' => trim((string)($payload['author_url'] ?? '')),
            'providerName' => trim((string)($payload['provider_name'] ?? $providerName)),
            'providerUrl' => trim((string)($payload['provider_url'] ?? $providerUrl)),
            'providerIcon' => '',
            'publishedTime' => null,
            'license' => null,
            'feeds' => [],
            'cms' => '',
            'favicon' => '',
            'keywords' => [],
            'language' => '',
            'languages' => [],
            'redirect' => '',
        ];
    }

    /**
     * Creates an "embedded video ready" array from an array matching the Craft 2 version of the plugin.
     *
     * @param array $legacy
     * @return array
     */
    private function _convertFromLegacy(array $legacy): array
    {
        $width = intval($legacy['width'] ?? 0);
        $height = intval($legacy['height'] ?? 0);
        $imageUrl = $legacy['thumbnailUrl'] ?? null;
        $imageWidth = intval($legacy['thumbnailWidth'] ?? 0);
        $imageHeight = intval($legacy['thumbnailHeight'] ?? 0);

        return [
            'title' => $legacy['title'] ?? null,
            'description' => $legacy['description'] ?? null,
            'url' => $legacy['url'] ?? null,
            'type' => $legacy['type'] ?? null,
            'images' => $imageUrl ? [
                [
                    'url' => $imageUrl,
                    'width' => $imageWidth,
                    'height' => $imageHeight,
                    'size' => $imageWidth * $imageHeight,
                    'mime' => null,
                ],
            ] : [],
            'image' => $imageUrl,
            'imageWidth' => $imageWidth,
            'imageHeight' => $imageHeight,
            'code' => Template::raw($legacy['html'] ?? $legacy['safeHtml'] ?? ''),
            'width' => $width,
            'height' => $height,
            'aspectRatio' => $width > 0 ? $height / $width * 100 : 0,
            'authorName' => $legacy['authorName'] ?? null,
            'authorUrl' => $legacy['authorUrl'] ?? null,
            'providerName' => $legacy['providerName'] ?? null,
            'providerUrl' => $legacy['providerUrl'] ?? null,
        ];
    }

    /**
     * Gets the contents of the given asset.
     *
     * If a cached copy of the embedded video exists, it will be loaded.  If a cached copy does not exist, one will be
     * created after loading the embedded video from its original location.
     *
     * @param Asset $asset
     * @return array of embedded video data
     */
    private function _getAssetContents(Asset $asset): array
    {
        $contents = Craft::$app->getCache()->getOrSet(
            $this->getCachedAssetKey($asset),
            static function() use ($asset) {
                return $asset->getContents();
            },
            0);

        try {
            $contents = Json::decode($contents);
        } catch (\Throwable $e) {
            throw new Exception('Tried to get the contents of a non-embedded video');
        }

        return $contents;
    }

    /**
     * Gets the cached key for the given asset.
     *
     * @since 2.5.0
     * @param Asset $asset
     * @return string the embedded video's cached key
     * @throws InvalidArgumentException if $asset is an unsaved Asset
     */
    public function getCachedAssetKey(Asset $asset): string
    {
        if ($asset->uid === null) {
            throw new InvalidArgumentException('Tried to get the cached key of an unsaved embedded video');
        }

        return 'embeddedvideos:' . $asset->uid;
    }

}
