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
        $additionalIssues = $this->collectAdditionalBuildScriptIssues($sourceRoot, $targetRoot);
        $allIssues = array_merge($diffs, $additionalIssues);
        $updatesAvailable = !empty($allIssues);

        $logChunks = [];
        $logChunks[] = '[cwd] ' . $targetRoot;
        $logChunks[] = '[source] ' . $sourceRoot;
        $logChunks[] = '[info] checking frontend build-script files';

        if ($updatesAvailable) {
            $logChunks[] = '[ok] updates available: ' . count($allIssues) . ' item(s)';
            $maxLines = 30;
            foreach (array_slice($allIssues, 0, $maxLines) as $diff) {
                $logChunks[] = sprintf('- [%s] %s', $diff['reason'], $diff['relativePath']);
            }
            if (count($allIssues) > $maxLines) {
                $logChunks[] = '[info] +' . (count($allIssues) - $maxLines) . ' more item(s)';
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
            'updatesCount' => count($allIssues),
            'logs' => implode("\n", $logChunks),
        ]);
    }

    public function actionApplyBuildScriptUpdates(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAdmin();

        return $this->asJson($this->applyBuildScriptUpdatesInternal());
    }

    public function runBuildScriptAutoSync(bool $alwaysFinalize = false): array
    {
        return $this->applyBuildScriptUpdatesInternal($alwaysFinalize);
    }

    private function applyBuildScriptUpdatesInternal(bool $alwaysFinalize = false): array
    {
        $targetRoot = $this->frontendPath();
        $sourceRoot = $this->pluginFrontendPath();

        if (!$targetRoot) {
            return [
                'success' => false,
                'message' => 'Frontend directory not found.',
            ];
        }
        if (!$sourceRoot) {
            return [
                'success' => false,
                'message' => 'Plugin frontend source directory not found.',
            ];
        }

        $diffs = $this->collectBuildScriptDiffs($sourceRoot, $targetRoot);
        $additionalIssues = $this->collectAdditionalBuildScriptIssues($sourceRoot, $targetRoot);
        $allIssues = array_merge($diffs, $additionalIssues);
        $logChunks = [];
        $logChunks[] = '[cwd] ' . $targetRoot;
        $logChunks[] = '[source] ' . $sourceRoot;

        if (empty($allIssues) && !$alwaysFinalize) {
            $logChunks[] = '[ok] no updates to apply';
            $logChunks[] = '[done] apply complete';
            return [
                'success' => true,
                'command' => 'apply build script updates',
                'cwd' => $targetRoot,
                'logs' => implode("\n", $logChunks),
            ];
        }

        $updated = 0;
        $failed = 0;
        if (!empty($allIssues)) {
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

            foreach ($additionalIssues as $issue) {
                if ($this->applyAdditionalBuildScriptIssue($issue, $sourceRoot, $targetRoot, $logChunks)) {
                    $updated++;
                } else {
                    $failed++;
                }
            }
        } else {
            $logChunks[] = '[ok] no file updates to apply';
        }

        $logChunks[] = '[info] updated files: ' . $updated;
        if ($failed > 0) {
            $logChunks[] = '[error] failed files: ' . $failed;
        }

        // Finalize by forcing a clean local install context + rebuild.
        $packageLockPath = $targetRoot . DIRECTORY_SEPARATOR . 'package-lock.json';
        if (is_file($packageLockPath)) {
            if (@unlink($packageLockPath)) {
                $logChunks[] = '[ok] removed: package-lock.json';
            } else {
                $failed++;
                $logChunks[] = '[error] failed to remove: package-lock.json';
            }
        }

        $nodeModulesPath = $targetRoot . DIRECTORY_SEPARATOR . 'node_modules';
        if (is_dir($nodeModulesPath)) {
            if ($this->deleteDirectory($nodeModulesPath)) {
                $logChunks[] = '[ok] removed: node_modules';
            } else {
                $failed++;
                $logChunks[] = '[error] failed to remove: node_modules';
            }
        }

        $isProd = $this->isProductionEnv();
        $installCommand = $isProd ? ['npm', 'install', '--omit=dev'] : ['npm', 'install'];
        $logChunks[] = '$ ' . $this->commandString($installCommand);
        $installResult = $this->runCommand($installCommand, $targetRoot);
        $installOutput = trim($installResult['stdout'] . "\n" . $installResult['stderr']);
        if ($installOutput !== '') {
            $logChunks[] = $installOutput;
        }
        if ($installResult['exitCode'] !== 0) {
            $failed++;
            $logChunks[] = '[error] npm install failed';
        } else {
            $logChunks[] = '[ok] npm install completed';
        }

        $script = $isProd ? 'prod' : 'dev';
        $command = ['npm', 'run', $script];
        $logChunks[] = '$ ' . $this->commandString($command);
        $result = $this->runCommand($command, $targetRoot);
        $commandOutput = trim($result['stdout'] . "\n" . $result['stderr']);
        if ($commandOutput !== '') {
            $logChunks[] = $commandOutput;
        }
        if ($result['exitCode'] !== 0) {
            $failed++;
            $logChunks[] = '[error] npm run ' . $script . ' failed';
        } else {
            $logChunks[] = '[ok] npm run ' . $script . ' completed';
        }

        $logChunks[] = '[done] apply complete';

        return [
            'success' => $failed === 0,
            'command' => 'apply build script updates',
            'cwd' => $targetRoot,
            'logs' => implode("\n", $logChunks),
        ];
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
            if (str_starts_with($relativePath, 'icons' . DIRECTORY_SEPARATOR)) {
                // Handled as a dedicated directory parity check.
                continue;
            }
            if ($relativePath === 'src' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'base' . DIRECTORY_SEPARATOR . 'bootstrap.scss') {
                // Handled as a dedicated bootstrap import check.
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

    private function collectAdditionalBuildScriptIssues(string $sourceRoot, string $targetRoot): array
    {
        $issues = [];

        // 1) Extra files in frontend root that do not exist in plugin frontend root.
        $sourceRootFiles = $this->listRootFiles($sourceRoot);
        $targetRootFiles = $this->listRootFiles($targetRoot);
        $rootFileExceptions = ['package-lock.json'];
        foreach ($targetRootFiles as $fileName) {
            if (in_array($fileName, $rootFileExceptions, true)) {
                continue;
            }
            if (!in_array($fileName, $sourceRootFiles, true)) {
                $issues[] = [
                    'relativePath' => $fileName,
                    'reason' => 'extra-root-file',
                    'kind' => 'remove-root-file',
                ];
            }
        }

        // 2) wpconfig directory should not exist.
        $wpconfigPath = $targetRoot . DIRECTORY_SEPARATOR . 'wpconfig';
        if (is_dir($wpconfigPath)) {
            $issues[] = [
                'relativePath' => 'wpconfig',
                'reason' => 'wpconfig-present',
                'kind' => 'remove-directory',
            ];
        }

        // 3) icons directory parity.
        $sourceIcons = $sourceRoot . DIRECTORY_SEPARATOR . 'icons';
        $targetIcons = $targetRoot . DIRECTORY_SEPARATOR . 'icons';
        if (!$this->directoriesAreSame($sourceIcons, $targetIcons)) {
            $issues[] = [
                'relativePath' => 'icons',
                'reason' => 'icons-mismatch',
                'kind' => 'sync-directory',
                'sourcePath' => $sourceIcons,
                'targetPath' => $targetIcons,
            ];
        }

        // 4) bootstrap.scss parity + mandatory imports.
        $bootstrapRelative = 'src/css/app/base/bootstrap.scss';
        $bootstrapSource = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $bootstrapRelative);
        $bootstrapTarget = $targetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $bootstrapRelative);
        $bootstrapIssue = $this->classifyBootstrapScssIssue($bootstrapTarget, $bootstrapSource);
        if ($bootstrapIssue !== null) {
            $issues[] = [
                'relativePath' => $bootstrapRelative,
                'reason' => $bootstrapIssue['reason'],
                'kind' => $bootstrapIssue['kind'],
                'sourcePath' => $bootstrapSource,
                'targetPath' => $bootstrapTarget,
            ];
        }

        // 5) src/index.js mandatory icon/bootstrap imports.
        $indexRelative = 'src/index.js';
        $indexTarget = $targetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $indexRelative);
        if (!$this->indexJsHasRequiredImports($indexTarget)) {
            $issues[] = [
                'relativePath' => $indexRelative,
                'reason' => 'index-imports-missing',
                'kind' => 'ensure-index-imports',
            ];
        }

        // 6) Replace legacy class names in frontend/assets files.
        $legacyRefs = $this->findLegacyAssetReferences($targetRoot . DIRECTORY_SEPARATOR . 'assets');
        foreach ($legacyRefs as $path) {
            $relativePath = ltrim(str_replace($targetRoot . DIRECTORY_SEPARATOR, '', $path), DIRECTORY_SEPARATOR);
            $issues[] = [
                'relativePath' => str_replace(DIRECTORY_SEPARATOR, '/', $relativePath),
                'reason' => 'legacy-asset-reference',
                'kind' => 'replace-legacy-references',
                'targetPath' => $path,
            ];
        }

        usort($issues, static fn(array $a, array $b): int => strcmp($a['relativePath'], $b['relativePath']));
        return $issues;
    }

    private function applyAdditionalBuildScriptIssue(array $issue, string $sourceRoot, string $targetRoot, array &$logChunks): bool
    {
        $kind = (string)($issue['kind'] ?? '');
        $relativePath = (string)($issue['relativePath'] ?? '');
        $targetPath = $targetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        switch ($kind) {
            case 'remove-root-file':
                if (is_file($targetPath) && !@unlink($targetPath)) {
                    $logChunks[] = '[error] failed to remove file: ' . $relativePath;
                    return false;
                }
                $logChunks[] = '[ok] removed file: ' . $relativePath;
                return true;

            case 'remove-directory':
                if (!$this->deleteDirectory($targetPath)) {
                    $logChunks[] = '[error] failed to remove directory: ' . $relativePath;
                    return false;
                }
                $logChunks[] = '[ok] removed directory: ' . $relativePath;
                return true;

            case 'sync-directory':
                $sourcePath = (string)($issue['sourcePath'] ?? '');
                if ($sourcePath === '' || !is_dir($sourcePath)) {
                    $logChunks[] = '[error] missing source directory: ' . $relativePath;
                    return false;
                }
                if (!$this->deleteDirectory($targetPath)) {
                    $logChunks[] = '[error] failed to reset directory: ' . $relativePath;
                    return false;
                }
                if (!$this->copyDirectory($sourcePath, $targetPath)) {
                    $logChunks[] = '[error] failed to copy directory: ' . $relativePath;
                    return false;
                }
                $logChunks[] = '[ok] synced directory: ' . $relativePath;
                return true;

            case 'copy-file':
                $sourcePath = (string)($issue['sourcePath'] ?? '');
                if ($sourcePath === '' || !is_file($sourcePath)) {
                    $logChunks[] = '[error] missing source file: ' . $relativePath;
                    return false;
                }
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                    $logChunks[] = '[error] failed to create directory: ' . $targetDir;
                    return false;
                }
                if (!@copy($sourcePath, $targetPath)) {
                    $logChunks[] = '[error] failed to update: ' . $relativePath;
                    return false;
                }
                $logChunks[] = '[ok] updated: ' . $relativePath;
                return true;

            case 'ensure-bootstrap-imports':
                if (!$this->ensureBootstrapTopImports($targetPath)) {
                    $logChunks[] = '[error] failed to update top imports: ' . $relativePath;
                    return false;
                }
                $logChunks[] = '[ok] updated top imports: ' . $relativePath;
                return true;

            case 'ensure-index-imports':
                if (!$this->ensureIndexImports($targetPath)) {
                    $logChunks[] = '[error] failed to update imports: ' . $relativePath;
                    return false;
                }
                $logChunks[] = '[ok] updated imports: ' . $relativePath;
                return true;

            case 'replace-legacy-references':
                $path = (string)($issue['targetPath'] ?? $targetPath);
                if (!$this->replaceLegacyReferencesInFile($path)) {
                    $logChunks[] = '[error] failed to replace legacy references: ' . $relativePath;
                    return false;
                }
                $logChunks[] = '[ok] updated legacy references: ' . $relativePath;
                return true;
        }

        return true;
    }

    private function listRootFiles(string $root): array
    {
        $items = @scandir($root);
        if ($items === false) {
            return [];
        }

        $files = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $root . DIRECTORY_SEPARATOR . $item;
            if (is_file($path)) {
                $files[] = $item;
            }
        }

        sort($files);
        return $files;
    }

    private function directoriesAreSame(string $sourceDir, string $targetDir): bool
    {
        if (!is_dir($sourceDir) || !is_dir($targetDir)) {
            return false;
        }

        return $this->directorySignature($sourceDir) === $this->directorySignature($targetDir);
    }

    private function directorySignature(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $signature = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $fullPath = $fileInfo->getPathname();
            $relative = ltrim(substr($fullPath, strlen($path)), DIRECTORY_SEPARATOR);
            $hash = @hash_file('sha256', $fullPath);
            $signature[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = $hash === false ? 'unreadable' : $hash;
        }
        ksort($signature);
        return $signature;
    }

    private function classifyBootstrapScssIssue(string $targetPath, string $sourcePath): ?array
    {
        if (!is_file($targetPath)) {
            if (!is_file($sourcePath)) {
                return [
                    'reason' => 'bootstrap-source-missing',
                    'kind' => 'copy-file',
                ];
            }
            return [
                'reason' => 'bootstrap-scss-missing',
                'kind' => 'copy-file',
            ];
        }

        $target = @file_get_contents($targetPath);
        if ($target === false) {
            return [
                'reason' => 'bootstrap-scss-unreadable',
                'kind' => 'copy-file',
            ];
        }

        if (!$this->bootstrapTopImportsPresent($target)) {
            return [
                'reason' => 'bootstrap-top-imports',
                'kind' => 'ensure-bootstrap-imports',
            ];
        }

        return null;
    }

    private function bootstrapTopImportsPresent(string $content): bool
    {
        $requiredImports = $this->requiredBootstrapImports();
        $firstChunk = implode("\n", array_slice(preg_split('/\r?\n/', $content) ?: [], 0, 30));
        foreach ($requiredImports as $importLine) {
            if (!str_contains($firstChunk, $importLine)) {
                return false;
            }
        }
        return true;
    }

    private function ensureBootstrapTopImports(string $bootstrapPath): bool
    {
        if (!is_file($bootstrapPath)) {
            return false;
        }

        $content = @file_get_contents($bootstrapPath);
        if ($content === false) {
            return false;
        }

        $required = $this->requiredBootstrapImports();
        $lines = preg_split('/\r?\n/', $content) ?: [];
        $cleanLines = $lines;

        // Remove existing required imports anywhere so we can reinsert in canonical place.
        $cleanLines = array_values(array_filter(
            $cleanLines,
            static fn(string $line): bool => !in_array(trim($line), $required, true)
        ));

        $configIndex = null;
        foreach ($cleanLines as $i => $line) {
            if (trim($line) === '// Configuration') {
                $configIndex = $i;
                break;
            }
        }

        if ($configIndex === null) {
            // Create section at top.
            $newLines = array_merge(['// Configuration'], $required, [''], $cleanLines);
        } else {
            // Insert directly under existing section header.
            array_splice($cleanLines, $configIndex + 1, 0, $required);
            $newLines = $cleanLines;
        }

        $newContent = implode("\n", $newLines);
        $newContent = preg_replace("/\n{3,}/", "\n\n", $newContent) ?? $newContent;
        if (!str_ends_with($newContent, "\n")) {
            $newContent .= "\n";
        }

        return @file_put_contents($bootstrapPath, $newContent) !== false;
    }

    private function requiredBootstrapImports(): array
    {
        return [
            '@import "bootstrap/scss/_functions.scss";',
            '@import "bootstrap/scss/_variables.scss";',
            '@import "bootstrap/scss/_maps";',
            '@import "bootstrap/scss/_utilities";',
        ];
    }

    private function indexJsHasRequiredImports(string $indexPath): bool
    {
        if (!is_file($indexPath)) {
            return false;
        }

        $content = @file_get_contents($indexPath);
        if ($content === false) {
            return false;
        }

        return str_contains($content, 'import "../icons/icons.scss";')
            && str_contains($content, 'import "./css/app/base/bootstrap.scss";');
    }

    private function ensureIndexImports(string $indexPath): bool
    {
        if (!is_file($indexPath)) {
            return false;
        }

        $content = @file_get_contents($indexPath);
        if ($content === false) {
            return false;
        }

        $required = [
            'import "../icons/icons.scss";',
            'import "./css/app/base/bootstrap.scss";',
        ];

        $lines = preg_split('/\r?\n/', $content) ?: [];
        $cleanLines = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if (in_array($trim, $required, true)) {
                continue;
            }
            $cleanLines[] = $line;
        }

        $insertIndex = 0;
        foreach ($cleanLines as $i => $line) {
            if (preg_match('/^\s*import\s+[\'"]\.\/css\/app\//', trim($line)) === 1) {
                $insertIndex = $i;
                break;
            }
            $insertIndex = $i + 1;
        }

        array_splice($cleanLines, $insertIndex, 0, $required);
        $newContent = implode("\n", $cleanLines);
        if (!str_ends_with($newContent, "\n")) {
            $newContent .= "\n";
        }

        return @file_put_contents($indexPath, $newContent) !== false;
    }

    private function findLegacyAssetReferences(string $assetsRoot): array
    {
        if (!is_dir($assetsRoot)) {
            return [];
        }

        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($assetsRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            $contents = @file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            if (
                str_contains($contents, 'FrontendCore')
                || str_contains($contents, 'WebpackAsset')
                || str_contains($contents, '$webpackName')
            ) {
                $paths[] = $path;
            }
        }

        sort($paths);
        return $paths;
    }

    private function replaceLegacyReferencesInFile(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        $updated = str_replace(
            ['FrontendCore', 'WebpackAsset', '$webpackName'],
            ['Frontend', 'FrontendAsset', '$frontendName'],
            $contents
        );

        if ($updated === $contents) {
            return true;
        }

        return @file_put_contents($path, $updated) !== false;
    }

    private function copyDirectory(string $source, string $target): bool
    {
        if (!is_dir($source)) {
            return false;
        }

        if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
            return false;
        }

        $items = scandir($source);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
            $targetPath = $target . DIRECTORY_SEPARATOR . $item;

            if (is_dir($sourcePath)) {
                if (!$this->copyDirectory($sourcePath, $targetPath)) {
                    return false;
                }
                continue;
            }

            if (!@copy($sourcePath, $targetPath)) {
                return false;
            }
        }

        return true;
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
