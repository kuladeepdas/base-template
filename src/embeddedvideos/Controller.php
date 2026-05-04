<?php

namespace Kdgraphics\BaseTemplate\embeddedvideos;

use Craft;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\models\VolumeFolder;
use craft\web\Controller as BaseController;
use Kdgraphics\BaseTemplate\assetbundles\EmbeddedVideosPreviewAsset;
use Kdgraphics\BaseTemplate\embeddedvideos\Plugin as EmbeddedVideos;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Class Controller
 *
 * @package kdgraphics\base-template\embeddedvideos
 * @author KD Graphics
 * @author Benjamin Fleming
 * @since 1.0.0
 */
class Controller extends BaseController
{
    /**
     * Saves an embedded video as a Craft asset.
     *
     * @query string url The URL to create an embedded video from (required).
     * @query string targetType Whether `targetUid` belongs to a volume or a folder (required).
     * @query string targetUid The volume or folder UID to save the asset to (required).
     * @response JSON
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionSave(): Response
    {
        $this->requireAcceptsJson();

        $response = null;

        $requestService = Craft::$app->getRequest();

        $url = $requestService->getRequiredBodyParam('url');
        $targetType = $requestService->getRequiredBodyParam('targetType');
        $targetUid = $requestService->getBodyParam('targetUid');
        $targetId = $requestService->getBodyParam('targetId');

        if (!$targetId && !$targetUid) {
            throw new BadRequestHttpException('One of targetUid or targetId is required.');
        }

        $folderCriteria = [];

        if ($targetId) {
            $folderCriteria['id'] = $targetId;
        } elseif (str_ends_with($targetType, 'folder')) {
            $folderCriteria['uid'] = $targetUid;
        } else {
            $folderCriteria['volumeId'] = Db::idByUid(Table::VOLUMES, $targetUid);
            $folderCriteria['parentId'] = ':empty:';
        }

        $folder = $this->_findFolder($folderCriteria);
        try {
            $embeddedVideo = EmbeddedVideos::$plugin->methods->requestUrl($url);
        } catch (\Throwable $e) {
            return $this->asErrorJson($e->getMessage());
        }
        $asset = EmbeddedVideos::$plugin->methods->createAsset($embeddedVideo, $folder);
        $result = Craft::$app->getElements()->saveElement($asset);

        if (!$result) {
            $errors = $asset->getFirstErrors();
            $errorLabel = Craft::t('app', "Failed to save the Asset:");
            $response = $this->asErrorJson($errorLabel . implode(";\n", $errors));
        } else {
            $response = $this->asJson([
                'success' => true,
                'payload' => [
                    'assetId' => $asset->id,
                    'folderUid' => $folder->uid,
                ],
            ]);
        }

        return $response;
    }
    
    /**
     * Replaces an embedded video.
     *
     * @query string url The URL to create an embedded video from (required).
     * @query string targetType Whether `targetUid` belongs to a volume or a folder (required).
     * @query string targetUid The volume or folder UID to save the asset to (required).
     * @query int assetId The asset ID to replace (required).
     * @response JSON
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionReplace(): Response
    {
        $this->requireAcceptsJson();

        $response = null;

        $assetsService = Craft::$app->getAssets();
        $elementsService = Craft::$app->getElements();
        $requestService = Craft::$app->getRequest();

        $url = $requestService->getRequiredParam('url');
        $targetType = $requestService->getRequiredBodyParam('targetType');
        $targetUid = $requestService->getBodyParam('targetUid');
        $targetId = $requestService->getBodyParam('targetId');
        $assetId = $requestService->getRequiredParam('assetId');

        if (!$targetId && !$targetUid) {
            throw new BadRequestHttpException('One of targetUid or targetId is required.');
        }

        $assetToReplace = null;

        if ($assetId && !$assetToReplace = $assetsService->getAssetById($assetId)) {
            throw new NotFoundHttpException('Asset not found.');
        }

        $folderCriteria = [];

        if ($targetId) {
            $folderCriteria['id'] = $targetId;
        } elseif (str_ends_with($targetType, 'folder')) {
            $folderCriteria['uid'] = $targetUid;
        } else {
            $folderCriteria['volumeId'] = Db::idByUid(Table::VOLUMES, $targetUid);
            $folderCriteria['parentId'] = ':empty:';
        }

        $folder = $this->_findFolder($folderCriteria);
        try {
            $embeddedVideo = EmbeddedVideos::$plugin->methods->requestUrl($url);
        } catch (\Throwable $e) {
            return $this->asErrorJson($e->getMessage());
        }
        $asset = EmbeddedVideos::$plugin->methods->createAsset($embeddedVideo, $folder);
        $result = $elementsService->saveElement($asset);

        $tempPath = $asset->getCopyOfFile();
        $assetToReplace->title = $asset->title;
        $assetToReplace->newFilename = $asset->filename;
        $assetsService->replaceAssetFile($assetToReplace, $tempPath, $assetToReplace->newFilename);
        $elementsService->deleteElement($asset);

        if (!$result) {
            $errors = $asset->getFirstErrors();
            $errorLabel = Craft::t('app', "Failed to save the Asset:");
            $response = $this->asErrorJson($errorLabel . implode(";\n", $errors));
        } else {
            // Replace the old cached data for the embedded video
            Craft::$app->getCache()->set(
                EmbeddedVideos::$plugin->methods->getCachedAssetKey($assetToReplace),
                Json::encode($embeddedVideo->jsonSerialize(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );

            $response = $this->asJson([
                'success' => true,
                'payload' => [
                    'assetId' => $asset->id,
                    'folderUid' => $folder->uid,
                ],
            ]);
        }

        return $response;
    }

    /**
     * Renders a preview of the embedded video.
     *
     * @query string url The URL to create an embedded video from.
     * @query string assetId The asset ID to load the embedded video from.
     * @query string callback The name of a global Javascript function to be called when the preview is loaded.
     * @response HTML
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws \Twig\Error\LoaderError
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function actionPreview(): Response
    {
        $assetsService = Craft::$app->getAssets();
        $requestService = Craft::$app->getRequest();
        $viewService = Craft::$app->getView();
        $viewService->registerAssetBundle(EmbeddedVideosPreviewAsset::class);

        $url = $requestService->getParam('url');
        $assetId = $requestService->getParam('assetId');
        $callback = $requestService->getParam('callback');
        $showContent = (bool)$requestService->getParam('showContent', true);

        if ($url) {
            try {
                // Show preview even if whitelist-enforcement is enabled, saving is blocked in actionSave/actionReplace.
                $embeddedVideo = EmbeddedVideos::$plugin->methods->requestUrl($url, true, false);
            } catch (\Throwable $e) {
                $message = Html::encode($e->getMessage());
                $warning = '<div id="warning" class="readable"><p>' . $message . '</p></div>';
                $script = '';
                if ($callback) {
                    $callbackJs = Json::htmlEncode((string)$callback);
                    $script = "<script>if(window.parent&&window.parent.EmbeddedVideosPreviewMap){const cb=window.parent.EmbeddedVideosPreviewMap.get($callbackJs);if(typeof cb==='function'){window.requestAnimationFrame(cb);}}</script>";
                }
                return $this->asRaw($warning . $script);
            }
        } else {
            if ($assetId) {
                $asset = $assetsService->getAssetById($assetId);

                if (!$asset) {
                    throw new BadRequestHttpException("Could not find asset with ID: $assetId");
                }

                $embeddedVideo = EmbeddedVideos::$plugin->methods->getEmbeddedVideo($asset);

                if (!$embeddedVideo) {
                    throw new BadRequestHttpException("Could not find embedded video from asset $assetId ($asset->filename)");
                }
            } else {
                throw new BadRequestHttpException("URL or asset ID are missing from the request");
            }
        }

        $template = $viewService->renderTemplate('base-template/embedded-videos/_preview', [
            'embeddedVideo' => $embeddedVideo,
            'callback' => $callback,
            'showContent' => $showContent,
        ]);

        $response = $this->asRaw($template);
        $headers = $response->getHeaders();
        $headers->set('content-type', 'text/html; charset=utf-8');

        return $response;
    }

    private function _findFolder(array $criteria): VolumeFolder
    {
        $assetsService = Craft::$app->getAssets();
        $folder = $assetsService->findFolder($criteria);

        if (!$folder) {
            throw new BadRequestHttpException('The target folder provided for uploading is not valid');
        }

        $userTempFolder = !$folder->volumeId ? $assetsService->getUserTemporaryUploadFolder() : null;

        if (!$userTempFolder || $folder->id != $userTempFolder->id) {
            $volume = Craft::$app->getVolumes()->getVolumeById($folder->volumeId);
            $this->requirePermission('saveAssets:' . $volume->uid);
        }

        return $folder;
    }
}
