<?php

namespace Inspire\Frontend\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\web\Controller;
use Throwable;
use yii\web\Response;

class UtilityController extends Controller
{
    /** @var array|int|bool */
    protected array|int|bool $allowAnonymous = ['session-info'];

    /**
     * This is based on
     * - vendor/craftcms/cms/src/controllers/UsersController.php
     * - actionSessionInfo()
     *
     * @return Response
     * @throws Throwable
     */
    public function actionSessionInfo(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $userSession = Craft::$app->getUser();
        $user = $userSession->getIdentity();
        $currentUrl = Craft::$app->getRequest()->getBodyParam('currentUrl');

        $return = [
            'isGuest' => $user === null,
            'timeout' => $userSession->getRemainingSessionTime(),
        ];

        if (Craft::$app->getConfig()->getGeneral()->enableCsrfProtection) {
            $return['csrfTokenName'] = Craft::$app->getConfig()->getGeneral()->csrfTokenName;
            $return['csrfTokenValue'] = $this->request->getCsrfToken();
        }

        if ($user) {
            Craft::$app->getUrlManager()->setMatchedElement(null);
            $matchedElement = Craft::$app->getElements()->getElementByUri(trim($currentUrl, '/'), null, true);
            if ($matchedElement instanceof ElementInterface and $matchedElement->canSave($user)) {
                $return['editUrl'] = $matchedElement->getCpEditUrl();
            }
        }

        return $this->asJson($return);
    }

    public function actionCheckNpmUpdates(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAdmin();

        $cwd = $this->frontendPath();
        if (!$cwd) {
            return $this->asJson([
                'success' => false,
                'message' => 'Frontend directory not found.',
            ]);
        }

        $omitDev = $this->isProductionEnv();
        $command = ['npm', 'outdated'];
        if ($omitDev) {
            $command[] = '--omit=dev';
        }

        $result = $this->runCommand($command, $cwd);
        if (!in_array($result['exitCode'], [0, 1], true)) {
            return $this->asJson([
                'success' => false,
                'message' => 'npm outdated failed.',
                'command' => $this->commandString($command),
                'cwd' => $cwd,
                'logs' => trim($result['stdout'] . "\n" . $result['stderr']),
            ]);
        }

        $updates = $this->parseOutdatedPackages($result['stdout']);

        $updatesAvailable = !empty($updates);
        ksort($updates);

        $logChunks = [];
        $logChunks[] = '[cwd] ' . $cwd;
        if ($omitDev) {
            $logChunks[] = '[env] production (omitting devDependencies)';
        }
        $logChunks[] = '$ ' . $this->commandString($command);
        if ($updatesAvailable) {
            $logChunks[] = '[ok] updates available: ' . count($updates) . ' package(s)';
            $maxLines = 12;
            $packageNames = array_keys($updates);
            foreach (array_slice($packageNames, 0, $maxLines) as $name) {
                $item = $updates[$name] ?? [];
                $current = $item['current'] ?? '?';
                $wanted = $item['wanted'] ?? '?';
                $latest = $item['latest'] ?? '?';
                $logChunks[] = sprintf('- %s: current %s, wanted %s, latest %s', $name, $current, $wanted, $latest);
            }
            if (count($updates) > $maxLines) {
                $logChunks[] = '[info] +' . (count($updates) - $maxLines) . ' more package(s)';
            }
        } else {
            $logChunks[] = '[ok] all packages are up to date';
        }
        $logChunks[] = '[done] check complete';

        return $this->asJson([
            'success' => true,
            'command' => $this->commandString($command),
            'cwd' => $cwd,
            'updatesAvailable' => $updatesAvailable,
            'updatesCount' => count($updates),
            'logs' => implode("\n", $logChunks),
        ]);
    }

    public function actionInstallNpmUpdates(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAdmin();

        $cwd = $this->frontendPath();
        if (!$cwd) {
            return $this->asJson([
                'success' => false,
                'message' => 'Frontend directory not found.',
                'cwd' => null,
            ]);
        }

        $command = ['npm', 'update'];
        if ($this->isProductionEnv()) {
            $command[] = '--omit=dev';
        }
        $commandString = $this->commandString($command);
        $result = $this->runCommand($command, $cwd);
        if ($result['exitCode'] !== 0) {
            return $this->asJson([
                'success' => false,
                'message' => 'npm update failed.',
                'command' => $commandString,
                'cwd' => $cwd,
                'logs' => trim($result['stdout'] . "\n" . $result['stderr']),
            ]);
        }

        $logs = trim($result['stdout'] . "\n" . $result['stderr']);
        if ($logs === '') {
            $logs = '[done] updates installed';
        }

        return $this->asJson([
            'success' => true,
            'command' => $commandString,
            'cwd' => $cwd,
            'logs' => $logs,
        ]);
    }

    public function actionReinstallPackages(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAdmin();

        $cwd = $this->frontendPath();
        if (!$cwd) {
            return $this->asJson([
                'success' => false,
                'message' => 'Frontend directory not found.',
                'cwd' => null,
            ]);
        }

        $omitDev = $this->isProductionEnv();
        $logs = [];
        $logs[] = '[cwd] ' . $cwd;
        if ($omitDev) {
            $logs[] = '[env] production (omitting devDependencies)';
        }

        $lockFile = $cwd . DIRECTORY_SEPARATOR . 'package-lock.json';
        if (is_file($lockFile)) {
            if (!@unlink($lockFile)) {
                return $this->asJson([
                    'success' => false,
                    'command' => 'npm install' . ($omitDev ? ' --omit=dev' : ''),
                    'cwd' => $cwd,
                    'logs' => implode("\n", array_merge($logs, ['[error] failed to delete package-lock.json'])),
                ]);
            }
            $logs[] = '[ok] deleted package-lock.json';
        } else {
            $logs[] = '[info] package-lock.json not found';
        }

        $nodeModulesDir = $cwd . DIRECTORY_SEPARATOR . 'node_modules';
        if (is_dir($nodeModulesDir)) {
            if (!$this->deleteDirectory($nodeModulesDir)) {
                return $this->asJson([
                    'success' => false,
                    'command' => 'npm install' . ($omitDev ? ' --omit=dev' : ''),
                    'cwd' => $cwd,
                    'logs' => implode("\n", array_merge($logs, ['[error] failed to delete node_modules directory'])),
                ]);
            }
            $logs[] = '[ok] deleted node_modules';
        } else {
            $logs[] = '[info] node_modules not found';
        }

        $command = ['npm', 'install'];
        if ($omitDev) {
            $command[] = '--omit=dev';
        }
        $commandString = $this->commandString($command);
        $result = $this->runCommand($command, $cwd);
        $installLogs = trim($result['stdout'] . "\n" . $result['stderr']);
        if ($installLogs !== '') {
            $logs[] = '$ ' . $commandString;
            $logs[] = $installLogs;
        } else {
            $logs[] = '$ ' . $commandString;
        }

        return $this->asJson([
            'success' => $result['exitCode'] === 0,
            'command' => $commandString,
            'cwd' => $cwd,
            'logs' => implode("\n", array_merge($logs, [$result['exitCode'] === 0 ? '[done] reinstall complete' : '[error] npm install failed'])),
        ]);
    }

    public function actionRebuildAssets(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAdmin();

        $cwd = $this->frontendPath();
        if (!$cwd) {
            return $this->asJson([
                'success' => false,
                'message' => 'Frontend directory not found.',
                'cwd' => null,
            ]);
        }

        $script = $this->isProductionEnv() ? 'prod' : 'dev';
        $command = ['npm', 'run', $script];
        $result = $this->runCommand($command, $cwd);
        $logs = trim($result['stdout'] . "\n" . $result['stderr']);

        return $this->asJson([
            'success' => $result['exitCode'] === 0,
            'command' => $this->commandString($command),
            'cwd' => $cwd,
            'logs' => $logs !== '' ? $logs : ($result['exitCode'] === 0 ? '[done] rebuild complete' : '[error] rebuild failed'),
        ]);
    }

    public function actionCheckBuildScriptUpdates(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAdmin();

        $targetRoot = $this->frontendPath();
        $sourceRoot = $this->pluginFrontendPath();

        if (!$targetRoot) {
            return $this->asJson([
                'success' => false,
                'message' => 'Frontend directory not found.',
            ]);
        }
        if (!$sourceRoot) {
            return $this->asJson([
                'success' => false,
                'message' => 'Plugin frontend source directory not found.',
            ]);
        }

        $diffs = $this->collectBuildScriptDiffs($sourceRoot, $targetRoot);
        $updatesAvailable = !empty($diffs);

        $logChunks = [];
        $logChunks[] = '[cwd] ' . $targetRoot;
        $logChunks[] = '[source] ' . $sourceRoot;
        $logChunks[] = '[info] checking frontend build-script files';

        if ($updatesAvailable) {
            $logChunks[] = '[ok] updates available: ' . count($diffs) . ' file(s)';
            $maxLines = 30;
            foreach (array_slice($diffs, 0, $maxLines) as $diff) {
                $logChunks[] = sprintf('- [%s] %s', $diff['reason'], $diff['relativePath']);
            }
            if (count($diffs) > $maxLines) {
                $logChunks[] = '[info] +' . (count($diffs) - $maxLines) . ' more file(s)';
            }
        } else {
            $logChunks[] = '[ok] build scripts are up to date';
        }
        $logChunks[] = '[done] check complete';

        return $this->asJson([
            'success' => true,
            'command' => 'check build script updates',
            'cwd' => $targetRoot,
            'updatesAvailable' => $updatesAvailable,
            'updatesCount' => count($diffs),
            'logs' => implode("\n", $logChunks),
        ]);
    }

    public function actionApplyBuildScriptUpdates(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAdmin();

        $targetRoot = $this->frontendPath();
        $sourceRoot = $this->pluginFrontendPath();

        if (!$targetRoot) {
            return $this->asJson([
                'success' => false,
                'message' => 'Frontend directory not found.',
            ]);
        }
        if (!$sourceRoot) {
            return $this->asJson([
                'success' => false,
                'message' => 'Plugin frontend source directory not found.',
            ]);
        }

        $diffs = $this->collectBuildScriptDiffs($sourceRoot, $targetRoot);
        $logChunks = [];
        $logChunks[] = '[cwd] ' . $targetRoot;
        $logChunks[] = '[source] ' . $sourceRoot;

        if (empty($diffs)) {
            $logChunks[] = '[ok] no updates to apply';
            $logChunks[] = '[done] apply complete';
            return $this->asJson([
                'success' => true,
                'command' => 'apply build script updates',
                'cwd' => $targetRoot,
                'logs' => implode("\n", $logChunks),
            ]);
        }

        $updated = 0;
        $failed = 0;
        foreach ($diffs as $diff) {
            $relativePath = $diff['relativePath'];
            $sourcePath = $sourceRoot . DIRECTORY_SEPARATOR . $relativePath;
            $targetPath = $targetRoot . DIRECTORY_SEPARATOR . $relativePath;
            $targetDir = dirname($targetPath);

            if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                $failed++;
                $logChunks[] = '[error] failed to create directory: ' . $targetDir;
                continue;
            }

            if ($relativePath === 'package.json') {
                $mergeResult = $this->mergeBuildScriptPackageJson($sourcePath, $targetPath);
                if (!$mergeResult['success']) {
                    $failed++;
                    $logChunks[] = '[error] failed to update: package.json (' . $mergeResult['message'] . ')';
                    continue;
                }

                $updated++;
                $logChunks[] = '[ok] updated: package.json';
                continue;
            }

            if (!@copy($sourcePath, $targetPath)) {
                $failed++;
                $logChunks[] = '[error] failed to update: ' . $relativePath;
                continue;
            }

            $updated++;
            $logChunks[] = '[ok] updated: ' . $relativePath;
        }

        $logChunks[] = '[info] updated files: ' . $updated;
        if ($failed > 0) {
            $logChunks[] = '[error] failed files: ' . $failed;
        }
        $logChunks[] = '[done] apply complete';

        return $this->asJson([
            'success' => $failed === 0,
            'command' => 'apply build script updates',
            'cwd' => $targetRoot,
            'logs' => implode("\n", $logChunks),
        ]);
    }

    public function actionCheckGitStatus(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAdmin();

        $cwd = $this->projectRootPath();
        if (!$cwd) {
            return $this->asJson([
                'success' => false,
                'message' => 'Project root directory not found.',
            ]);
        }

        $command = ['git', 'status', '--short'];
        $result = $this->runCommand($command, $cwd);
        if ($result['exitCode'] !== 0) {
            return $this->asJson([
                'success' => false,
                'message' => 'Unable to run git status.',
                'command' => $this->commandString($command),
                'cwd' => $cwd,
                'logs' => trim($result['stdout'] . "\n" . $result['stderr']),
            ]);
        }

        $statusLines = array_values(array_filter(
            preg_split('/\r?\n/', trim($result['stdout'])) ?: [],
            static fn(string $line): bool => trim($line) !== ''
        ));
        $updatesAvailable = !empty($statusLines);

        $logChunks = [];
        $logChunks[] = '[cwd] ' . $cwd;
        $logChunks[] = '$ ' . $this->commandString($command);
        if ($updatesAvailable) {
            $logChunks[] = '[ok] changes detected: ' . count($statusLines) . ' item(s)';
            foreach ($statusLines as $line) {
                $logChunks[] = $line;
            }
        } else {
            $logChunks[] = '[ok] working tree clean';
        }
        $logChunks[] = '[done] status check complete';

        return $this->asJson([
            'success' => true,
            'command' => $this->commandString($command),
            'cwd' => $cwd,
            'updatesAvailable' => $updatesAvailable,
            'updatesCount' => count($statusLines),
            'logs' => implode("\n", $logChunks),
        ]);
    }

    public function actionCommitAndPush(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAdmin();

        $cwd = $this->projectRootPath();
        if (!$cwd) {
            return $this->asJson([
                'success' => false,
                'message' => 'Project root directory not found.',
            ]);
        }

        $logChunks = [];
        $logChunks[] = '[cwd] ' . $cwd;

        $statusCheck = $this->runCommand(['git', 'status', '--porcelain'], $cwd);
        if ($statusCheck['exitCode'] !== 0) {
            return $this->asJson([
                'success' => false,
                'command' => 'git status --porcelain',
                'cwd' => $cwd,
                'logs' => trim($statusCheck['stdout'] . "\n" . $statusCheck['stderr']),
            ]);
        }

        if (trim($statusCheck['stdout']) === '') {
            $logChunks[] = '[ok] no changes to commit';
            $logChunks[] = '[done] commit and push complete';
            return $this->asJson([
                'success' => true,
                'command' => 'git status --porcelain',
                'cwd' => $cwd,
                'logs' => implode("\n", $logChunks),
            ]);
        }

        $commitMessage = $this->isLocalEnv()
            ? 'Updates from Local Admin'
            : 'Updates from Admin[skip ci]';

        $commands = [
            ['git', 'add', '-A'],
            ['git', 'commit', '-m', $commitMessage],
            ['git', 'push'],
        ];

        foreach ($commands as $command) {
            $commandString = $this->commandString($command);
            $logChunks[] = '$ ' . $commandString;
            $result = $this->runCommand($command, $cwd);

            $output = trim($result['stdout'] . "\n" . $result['stderr']);
            if ($output !== '') {
                $logChunks[] = $output;
            }

            if ($result['exitCode'] !== 0) {
                return $this->asJson([
                    'success' => false,
                    'command' => $commandString,
                    'cwd' => $cwd,
                    'logs' => implode("\n", array_merge($logChunks, ['[error] command failed'])),
                ]);
            }
        }

        $logChunks[] = '[done] commit and push complete';
        return $this->asJson([
            'success' => true,
            'command' => 'git add -A && git commit -m "' . $commitMessage . '" && git push',
            'cwd' => $cwd,
            'logs' => implode("\n", $logChunks),
        ]);
    }

    private function frontendPath(): ?string
    {
        $root = defined('CRAFT_BASE_PATH')
            ? CRAFT_BASE_PATH
            : Craft::getAlias('@root');
        $frontend = realpath($root . DIRECTORY_SEPARATOR . 'frontend');

        if (!$frontend || !is_dir($frontend) || !is_file($frontend . DIRECTORY_SEPARATOR . 'package.json')) {
            return null;
        }

        return $frontend;
    }

    private function pluginFrontendPath(): ?string
    {
        $path = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'frontend');
        if (!$path || !is_dir($path)) {
            return null;
        }

        return $path;
    }

    private function projectRootPath(): ?string
    {
        $root = defined('CRAFT_BASE_PATH')
            ? CRAFT_BASE_PATH
            : Craft::getAlias('@root');
        $path = realpath($root);
        if (!$path || !is_dir($path)) {
            return null;
        }

        return $path;
    }

    private function isProductionEnv(): bool
    {
        return (string)Craft::$app->config->env === 'production';
    }

    private function isLocalEnv(): bool
    {
        return (string)Craft::$app->config->env === 'local';
    }

    private function commandString(array $command): string
    {
        return implode(' ', array_map(static fn($part) => (string)$part, $command));
    }

    private function parseOutdatedPackages(string $stdout): array
    {
        $lines = preg_split('/\r?\n/', trim($stdout)) ?: [];
        $packages = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'Package') || str_starts_with($line, '[')) {
                continue;
            }

            if (preg_match('/^(@?[^\\s]+)\\s+(\\S+)\\s+(\\S+)\\s+(\\S+)/', $line, $m)) {
                $packages[$m[1]] = [
                    'current' => $m[2],
                    'wanted' => $m[3],
                    'latest' => $m[4],
                ];
            }
        }

        return $packages;
    }

    private function collectBuildScriptDiffs(string $sourceRoot, string $targetRoot): array
    {
        $diffs = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $sourcePath = $fileInfo->getPathname();
            $relativePath = ltrim(substr($sourcePath, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
            if ($relativePath === '') {
                continue;
            }

            $targetPath = $targetRoot . DIRECTORY_SEPARATOR . $relativePath;
            if (!is_file($targetPath)) {
                $diffs[] = [
                    'relativePath' => $relativePath,
                    'reason' => 'missing',
                ];
                continue;
            }

            if ($relativePath === 'package.json') {
                $packageDiff = $this->diffBuildScriptPackageJson($sourcePath, $targetPath);
                if ($packageDiff['hasDiff']) {
                    $diffs[] = [
                        'relativePath' => $relativePath,
                        'reason' => $packageDiff['reason'],
                    ];
                }
                continue;
            }

            $sourceHash = @hash_file('sha256', $sourcePath);
            $targetHash = @hash_file('sha256', $targetPath);
            if ($sourceHash === false || $targetHash === false) {
                $diffs[] = [
                    'relativePath' => $relativePath,
                    'reason' => 'unreadable',
                ];
                continue;
            }

            if ($sourceHash !== $targetHash) {
                $diffs[] = [
                    'relativePath' => $relativePath,
                    'reason' => 'changed',
                ];
            }
        }

        usort($diffs, static fn(array $a, array $b): int => strcmp($a['relativePath'], $b['relativePath']));
        return $diffs;
    }

    private function diffBuildScriptPackageJson(string $sourcePath, string $targetPath): array
    {
        $sourceData = $this->readJsonFile($sourcePath);
        $targetData = $this->readJsonFile($targetPath);
        if ($sourceData === null || $targetData === null) {
            return ['hasDiff' => true, 'reason' => 'unreadable'];
        }

        $sourceNonDeps = $sourceData;
        $targetNonDeps = $targetData;
        unset($sourceNonDeps['dependencies'], $targetNonDeps['dependencies'], $sourceNonDeps['remove'], $targetNonDeps['remove']);

        if ($sourceNonDeps != $targetNonDeps) {
            return ['hasDiff' => true, 'reason' => 'changed'];
        }

        $sourceDeps = (isset($sourceData['dependencies']) && is_array($sourceData['dependencies']))
            ? $sourceData['dependencies']
            : [];
        $targetDeps = (isset($targetData['dependencies']) && is_array($targetData['dependencies']))
            ? $targetData['dependencies']
            : [];

        foreach ($sourceDeps as $package => $version) {
            if (!array_key_exists($package, $targetDeps)) {
                return ['hasDiff' => true, 'reason' => 'deps-missing'];
            }

            if ((string)$targetDeps[$package] !== (string)$version) {
                return ['hasDiff' => true, 'reason' => 'deps-version'];
            }
        }

        $removePackages = $this->extractRemovePackages($sourceData['remove'] ?? null);
        foreach ($removePackages as $package) {
            if (array_key_exists($package, $targetDeps)) {
                return ['hasDiff' => true, 'reason' => 'deps-remove'];
            }
        }

        return ['hasDiff' => false, 'reason' => 'same'];
    }

    private function mergeBuildScriptPackageJson(string $sourcePath, string $targetPath): array
    {
        $sourceData = $this->readJsonFile($sourcePath);
        if ($sourceData === null) {
            return [
                'success' => false,
                'message' => 'source package.json is unreadable',
            ];
        }

        $targetData = $this->readJsonFile($targetPath);
        if ($targetData === null) {
            $targetData = [];
        }

        $merged = $targetData;
        foreach ($sourceData as $key => $value) {
            if ($key === 'dependencies' || $key === 'remove') {
                continue;
            }
            $merged[$key] = $value;
        }

        $sourceDeps = (isset($sourceData['dependencies']) && is_array($sourceData['dependencies']))
            ? $sourceData['dependencies']
            : [];
        $targetDeps = (isset($targetData['dependencies']) && is_array($targetData['dependencies']))
            ? $targetData['dependencies']
            : [];

        foreach ($sourceDeps as $package => $version) {
            $targetDeps[$package] = $version;
        }
        $removePackages = $this->extractRemovePackages($sourceData['remove'] ?? null);
        foreach ($removePackages as $package) {
            unset($targetDeps[$package]);
        }
        $merged['dependencies'] = $targetDeps;

        $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [
                'success' => false,
                'message' => 'failed to encode package.json',
            ];
        }

        $json .= "\n";
        if (@file_put_contents($targetPath, $json) === false) {
            return [
                'success' => false,
                'message' => 'failed to write package.json',
            ];
        }

        return ['success' => true, 'message' => 'ok'];
    }

    private function extractRemovePackages(mixed $removeNode): array
    {
        if (!is_array($removeNode)) {
            return [];
        }

        $packages = [];
        foreach ($removeNode as $key => $value) {
            if (is_string($key) && $key !== '' && !is_int($key)) {
                $packages[] = $key;
                continue;
            }

            if (is_string($value) && $value !== '') {
                $packages[] = $value;
            }
        }

        $packages = array_values(array_unique(array_map(
            static fn(string $package): string => trim($package),
            $packages
        )));

        return array_values(array_filter($packages, static fn(string $package): bool => $package !== ''));
    }

    private function readJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function deleteDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }

        $items = scandir($path);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                if (!$this->deleteDirectory($itemPath)) {
                    return false;
                }
                continue;
            }

            if (!@unlink($itemPath)) {
                return false;
            }
        }

        return @rmdir($path);
    }

    private function runCommand(array $command, string $cwd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return [
                'exitCode' => 1,
                'stdout' => '',
                'stderr' => 'Failed to start process.',
            ];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exitCode' => is_int($exitCode) ? $exitCode : 1,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
