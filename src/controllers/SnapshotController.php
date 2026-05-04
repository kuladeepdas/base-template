<?php

namespace Kdgraphics\BaseTemplate\controllers;

use Craft;
use craft\web\Controller;
use Kdgraphics\BaseTemplate\BaseTemplate;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

class SnapshotController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionExport(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        $service = BaseTemplate::getInstance()->getSchemaSnapshot();
        $selectedTypes = array_keys($service->getSnapshotTypeLabels());

        $snapshot = $service->buildSnapshot();
        $payload = $service->filterSnapshotByTypes($snapshot, $selectedTypes);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new BadRequestHttpException('Unable to encode snapshot as JSON.');
        }

        $filename = sprintf('base-template-snapshot-%s.json', gmdate('Ymd-His'));
        return Craft::$app->getResponse()->sendContentAsFile($json, $filename, [
            'mimeType' => 'application/json',
        ]);
    }

    public function actionImport(): ?Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        $uploaded = UploadedFile::getInstanceByName('snapshotFile');
        if (!$uploaded) {
            Craft::$app->getSession()->setError('Upload a snapshot JSON file first.');
            return $this->redirectToPostedUrl();
        }

        $raw = @file_get_contents($uploaded->tempName);
        if (!is_string($raw) || trim($raw) === '') {
            Craft::$app->getSession()->setError('Snapshot file is empty.');
            return $this->redirectToPostedUrl();
        }

        try {
            $snapshot = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            Craft::$app->getSession()->setError('Invalid snapshot JSON file.');
            return $this->redirectToPostedUrl();
        }

        if (!is_array($snapshot)) {
            Craft::$app->getSession()->setError('Snapshot JSON must decode to an object.');
            return $this->redirectToPostedUrl();
        }

        $service = BaseTemplate::getInstance()->getSchemaSnapshot();
        $selectedTypes = array_keys($service->getSnapshotTypeLabels());

        $availableTypes = $service->detectAvailableTypes($snapshot);
        $selectedTypes = array_values(array_intersect($selectedTypes, $availableTypes));
        if (empty($selectedTypes)) {
            Craft::$app->getSession()->setError('Imported snapshot file does not include any supported snapshot data.');
            return $this->redirectToPostedUrl();
        }

        try {
            $result = $service->importSnapshotReplacingTypes($snapshot, $selectedTypes);
            $summary = sprintf(
                'Imported: %d type(s). ckeditor=%d, entrySources=%d, filesystems=%d, volumes=%d, fields=%d, entryTypes=%d, sections=%d.',
                count((array)($result['typesImported'] ?? [])),
                (int)($result['ckeditorConfigsImported'] ?? 0),
                (int)($result['entryElementSourcesImported'] ?? 0),
                (int)($result['filesystemsImported'] ?? 0),
                (int)($result['volumesImported'] ?? 0),
                (int)($result['fieldsImported'] ?? 0),
                (int)($result['entryTypesImported'] ?? 0),
                (int)($result['sectionsImported'] ?? 0),
            );
            Craft::$app->getSession()->setNotice($summary);
        } catch (\Throwable $e) {
            Craft::error('Base Template import failed: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getSession()->setError('Import failed: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl();
    }

    public function actionApplyDefault(): ?Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        $service = BaseTemplate::getInstance()->getSchemaSnapshot();
        $selectedTypes = array_keys($service->getSnapshotTypeLabels());

        $snapshot = $service->getBundledSnapshot();
        if (empty($snapshot)) {
            Craft::$app->getSession()->setError('Built-in snapshot not found.');
            return $this->redirectToPostedUrl();
        }

        try {
            $result = $service->importSnapshotReplacingTypes($snapshot, $selectedTypes);
            $summary = sprintf(
                'Applied default template: %d type(s). ckeditor=%d, entrySources=%d, filesystems=%d, volumes=%d, fields=%d, entryTypes=%d, sections=%d.',
                count((array)($result['typesImported'] ?? [])),
                (int)($result['ckeditorConfigsImported'] ?? 0),
                (int)($result['entryElementSourcesImported'] ?? 0),
                (int)($result['filesystemsImported'] ?? 0),
                (int)($result['volumesImported'] ?? 0),
                (int)($result['fieldsImported'] ?? 0),
                (int)($result['entryTypesImported'] ?? 0),
                (int)($result['sectionsImported'] ?? 0),
            );
            Craft::$app->getSession()->setNotice($summary);
        } catch (\Throwable $e) {
            Craft::error('Base Template apply default failed: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getSession()->setError('Apply failed: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl();
    }
}
