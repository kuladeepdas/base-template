<?php

namespace Inspire\Frontend\services;

use Craft;
use craft\base\Component;
use craft\services\ProjectConfig;

class SchemaSnapshotService extends Component
{
    private const SNAPSHOT_TYPES = [
        'ckeditorConfigs' => 'CKEditor Configs',
        'entryElementSources' => 'Entry Sidebar Sources',
        'filesystems' => 'Filesystems',
        'volumes' => 'Asset Volumes',
        'fields' => 'Fields',
        'entryTypes' => 'Entry Types',
        'sections' => 'Sections',
    ];

    public function getSnapshotTypeLabels(): array
    {
        return self::SNAPSHOT_TYPES;
    }

    public function normalizeSelectedTypes(array $types): array
    {
        $keys = array_keys(self::SNAPSHOT_TYPES);
        $types = array_values(array_unique(array_filter(array_map('strval', $types))));

        if (in_array('__all', $types, true) || in_array('all', $types, true)) {
            return $keys;
        }

        return array_values(array_filter($types, static fn(string $type) => in_array($type, $keys, true)));
    }

    public function detectAvailableTypes(array $snapshot): array
    {
        $snapshot = $this->sanitizeSnapshot($snapshot);
        $available = [];

        foreach (array_keys(self::SNAPSHOT_TYPES) as $type) {
            if (array_key_exists($type, $snapshot)) {
                $available[] = $type;
            }
        }

        return $available;
    }

    public function filterSnapshotByTypes(array $snapshot, array $types): array
    {
        $snapshot = $this->sanitizeSnapshot($snapshot);
        $types = $this->normalizeSelectedTypes($types);

        $filtered = [
            'version' => (int)($snapshot['version'] ?? 1),
            'capturedAt' => (string)($snapshot['capturedAt'] ?? gmdate('c')),
            'types' => $types,
        ];

        foreach ($types as $type) {
            $filtered[$type] = $snapshot[$type] ?? [];
        }

        return $filtered;
    }

    public function getBundledSnapshot(): array
    {
        return $this->loadBundledSnapshot();
    }

    public function buildSnapshot(): array
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $snapshot = [
            'version' => 1,
            'capturedAt' => gmdate('c'),
            'ckeditorConfigs' => $projectConfig->get('ckeditor.configs') ?? [],
            'entryElementSources' => $projectConfig->get(ProjectConfig::PATH_ELEMENT_SOURCES . '.craft\\elements\\Entry') ?? [],
            'filesystems' => $projectConfig->get(ProjectConfig::PATH_FS) ?? [],
            'volumes' => $projectConfig->get(ProjectConfig::PATH_VOLUMES) ?? [],
            'fields' => $projectConfig->get(ProjectConfig::PATH_FIELDS) ?? [],
            'entryTypes' => $projectConfig->get(ProjectConfig::PATH_ENTRY_TYPES) ?? [],
            'sections' => $projectConfig->get(ProjectConfig::PATH_SECTIONS) ?? [],
        ];

        return $this->sanitizeSnapshot($snapshot);
    }

    public function importSnapshotReplacingTypes(array $snapshot, array $types): array
    {
        $snapshot = $this->sanitizeSnapshot($snapshot);
        $types = $this->normalizeSelectedTypes($types);
        if (empty($types)) {
            return ['status' => 'noop'];
        }

        $dependencyErrors = $this->validateImportDependencies($snapshot, $types);
        if (!empty($dependencyErrors)) {
            throw new \RuntimeException(implode("\n", $dependencyErrors));
        }

        $projectConfig = Craft::$app->getProjectConfig();
        $currentConfig = $projectConfig->get();
        $incomingConfig = is_array($currentConfig) ? $currentConfig : [];

        $importedCkeditorConfigs = 0;
        $importedEntryElementSources = 0;
        $importedFilesystems = 0;
        $importedVolumes = 0;
        $importedFields = 0;
        $importedEntryTypes = 0;
        $importedSections = 0;

        if (in_array('ckeditorConfigs', $types, true)) {
            $configs = is_array($snapshot['ckeditorConfigs'] ?? null) ? $snapshot['ckeditorConfigs'] : [];
            $incomingConfig['ckeditor']['configs'] = $configs;
            $importedCkeditorConfigs = count($configs);
        }

        if (in_array('entryElementSources', $types, true)) {
            $sources = is_array($snapshot['entryElementSources'] ?? null) ? $snapshot['entryElementSources'] : [];
            $incomingConfig['elementSources']['craft\\elements\\Entry'] = $sources;
            $importedEntryElementSources = count($sources);
        }

        if (in_array('filesystems', $types, true)) {
            $filesystems = is_array($snapshot['filesystems'] ?? null) ? $snapshot['filesystems'] : [];
            $incomingConfig['fs'] = $filesystems;
            $importedFilesystems = count($filesystems);
        }

        if (in_array('volumes', $types, true)) {
            $volumes = is_array($snapshot['volumes'] ?? null) ? $snapshot['volumes'] : [];
            $incomingConfig['volumes'] = $volumes;
            $importedVolumes = count($volumes);
        }

        if (in_array('fields', $types, true)) {
            $fields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
            $incomingConfig['fields'] = $fields;
            $importedFields = count($fields);
        }

        $entryTypesForSections = is_array($incomingConfig['entryTypes'] ?? null) ? $incomingConfig['entryTypes'] : [];
        if (in_array('entryTypes', $types, true)) {
            $entryTypes = is_array($snapshot['entryTypes'] ?? null) ? $snapshot['entryTypes'] : [];
            $entryTypes = $this->sanitizeSnapshot(['entryTypes' => $entryTypes])['entryTypes'];
            $incomingConfig['entryTypes'] = $entryTypes;
            $importedEntryTypes = count($entryTypes);
            $entryTypesForSections = $entryTypes;
        }

        if (in_array('sections', $types, true)) {
            $sections = is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : [];
            $currentSites = is_array($incomingConfig['sites'] ?? null) ? $incomingConfig['sites'] : ($projectConfig->get(ProjectConfig::PATH_SITES) ?? []);
            foreach ($sections as $sectionUid => $sectionConfig) {
                $sectionConfig = $this->remapSectionSiteSettings($sectionConfig, $currentSites);
                $sections[$sectionUid] = $this->normalizeSectionEntryTypes($sectionConfig, $entryTypesForSections);
            }

            $incomingConfig['sections'] = $sections;
            $importedSections = count($sections);
        }

        $projectConfig->applyConfigChanges($incomingConfig);
        $projectConfig->regenerateExternalConfig();

        return [
            'status' => 'applied',
            'typesImported' => $types,
            'ckeditorConfigsImported' => $importedCkeditorConfigs,
            'entryElementSourcesImported' => $importedEntryElementSources,
            'filesystemsImported' => $importedFilesystems,
            'volumesImported' => $importedVolumes,
            'fieldsImported' => $importedFields,
            'entryTypesImported' => $importedEntryTypes,
            'sectionsImported' => $importedSections,
        ];
    }

    private function validateImportDependencies(array $snapshot, array $types): array
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $currentSites = $projectConfig->get(ProjectConfig::PATH_SITES) ?? [];
        $currentFilesystems = $projectConfig->get(ProjectConfig::PATH_FS) ?? [];
        $currentVolumes = $projectConfig->get(ProjectConfig::PATH_VOLUMES) ?? [];
        $currentFields = $projectConfig->get(ProjectConfig::PATH_FIELDS) ?? [];
        $currentEntryTypes = $projectConfig->get(ProjectConfig::PATH_ENTRY_TYPES) ?? [];
        $currentSections = $projectConfig->get(ProjectConfig::PATH_SECTIONS) ?? [];
        $currentEntrySources = $projectConfig->get(ProjectConfig::PATH_ELEMENT_SOURCES . '.craft\\elements\\Entry') ?? [];

        $targetSites = $currentSites;
        $targetFilesystems = in_array('filesystems', $types, true) ? ($snapshot['filesystems'] ?? []) : $currentFilesystems;
        $targetVolumes = in_array('volumes', $types, true) ? ($snapshot['volumes'] ?? []) : $currentVolumes;
        $targetFields = in_array('fields', $types, true) ? ($snapshot['fields'] ?? []) : $currentFields;
        $targetEntryTypes = in_array('entryTypes', $types, true) ? ($snapshot['entryTypes'] ?? []) : $currentEntryTypes;
        $targetSections = in_array('sections', $types, true) ? ($snapshot['sections'] ?? []) : $currentSections;
        $targetEntrySources = in_array('entryElementSources', $types, true) ? ($snapshot['entryElementSources'] ?? []) : $currentEntrySources;

        $targetEntryTypes = $this->sanitizeSnapshot(['entryTypes' => $targetEntryTypes])['entryTypes'];

        foreach ($targetSections as $sectionUid => $sectionConfig) {
            if (!is_array($sectionConfig)) {
                continue;
            }
            $sectionConfig = $this->remapSectionSiteSettings($sectionConfig, $targetSites);
            $targetSections[$sectionUid] = $this->normalizeSectionEntryTypes($sectionConfig, $targetEntryTypes);
        }

        $errors = [];

        $targetFsHandles = array_fill_keys(array_keys((array)$targetFilesystems), true);
        foreach ((array)$targetVolumes as $volumeUid => $volumeConfig) {
            if (!is_array($volumeConfig)) {
                continue;
            }
            $fsHandle = $volumeConfig['fs'] ?? null;
            if (is_string($fsHandle) && $fsHandle !== '' && !isset($targetFsHandles[$fsHandle])) {
                $errors[] = sprintf(
                    'Volume "%s" references missing filesystem handle "%s". Include "Filesystems" in selection or ensure it already exists.',
                    (string)($volumeConfig['name'] ?? $volumeUid),
                    $fsHandle
                );
            }
        }

        $targetFieldUids = array_fill_keys(array_keys($targetFields), true);
        foreach ($targetEntryTypes as $entryTypeUid => $entryTypeConfig) {
            if (!is_array($entryTypeConfig)) {
                continue;
            }
            $missingFieldUids = $this->entryTypeMissingFieldUids($entryTypeConfig, $targetFieldUids);
            if (!empty($missingFieldUids)) {
                $errors[] = sprintf(
                    'Entry type "%s" references missing field UID(s): %s. Include "Fields" in selection or ensure those fields already exist.',
                    (string)($entryTypeConfig['name'] ?? $entryTypeUid),
                    implode(', ', $missingFieldUids)
                );
            }
        }

        $targetEntryTypeUids = array_fill_keys(array_keys($targetEntryTypes), true);
        $targetSiteUids = array_fill_keys(array_keys($targetSites), true);
        foreach ($targetSections as $sectionUid => $sectionConfig) {
            if (!is_array($sectionConfig)) {
                continue;
            }

            $missingEntryTypeUids = [];
            foreach ((array)($sectionConfig['entryTypes'] ?? []) as $ref) {
                $entryTypeUid = $this->extractEntryTypeUid($ref);
                if ($entryTypeUid !== null && !isset($targetEntryTypeUids[$entryTypeUid])) {
                    $missingEntryTypeUids[] = $entryTypeUid;
                }
            }
            if (!empty($missingEntryTypeUids)) {
                $errors[] = sprintf(
                    'Section "%s" references missing entry type UID(s): %s. Include "Entry Types" in selection or ensure they already exist.',
                    (string)($sectionConfig['name'] ?? $sectionUid),
                    implode(', ', array_values(array_unique($missingEntryTypeUids)))
                );
            }

            $missingSiteUids = [];
            foreach ((array)($sectionConfig['siteSettings'] ?? []) as $siteUid => $siteConfig) {
                if (!isset($targetSiteUids[(string)$siteUid])) {
                    $missingSiteUids[] = (string)$siteUid;
                }
            }
            if (!empty($missingSiteUids)) {
                $errors[] = sprintf(
                    'Section "%s" references missing site UID(s): %s. Ensure those sites already exist.',
                    (string)($sectionConfig['name'] ?? $sectionUid),
                    implode(', ', array_values(array_unique($missingSiteUids)))
                );
            }
        }

        $targetSectionUids = array_fill_keys(array_keys($targetSections), true);
        foreach ($this->extractEntrySourceSectionUids((array)$targetEntrySources) as $sectionUid) {
            if (!isset($targetSectionUids[$sectionUid])) {
                $errors[] = sprintf(
                    'Entry sidebar source references missing section UID "%s". Include "Sections" in selection or ensure it already exists.',
                    $sectionUid
                );
            }
        }

        return array_values(array_unique($errors));
    }

    private function entryTypeMissingFieldUids(array $entryTypeConfig, array $existingFieldUids): array
    {
        $missing = [];
        $fieldLayouts = $entryTypeConfig['fieldLayouts'] ?? [];
        if (!is_array($fieldLayouts)) {
            return [];
        }

        foreach ($fieldLayouts as $fieldLayoutConfig) {
            if (!is_array($fieldLayoutConfig)) {
                continue;
            }
            foreach ((array)($fieldLayoutConfig['tabs'] ?? []) as $tabConfig) {
                if (!is_array($tabConfig)) {
                    continue;
                }
                foreach ((array)($tabConfig['elements'] ?? []) as $elementConfig) {
                    if (!is_array($elementConfig)) {
                        continue;
                    }
                    $fieldUid = $elementConfig['fieldUid'] ?? null;
                    if (is_string($fieldUid) && $fieldUid !== '' && !isset($existingFieldUids[$fieldUid])) {
                        $missing[] = $fieldUid;
                    }
                }
            }
        }

        return array_values(array_unique($missing));
    }

    private function extractEntrySourceSectionUids(array $sources): array
    {
        $sectionUids = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $key = (string)($source['key'] ?? '');
            if (str_starts_with($key, 'section:')) {
                $uid = substr($key, 8);
                if ($uid !== '') {
                    $sectionUids[] = $uid;
                }
            }
        }

        return array_values(array_unique($sectionUids));
    }

    public function applySnapshot(array $snapshot, bool $overwrite = false): array
    {
        $snapshot = $this->sanitizeSnapshot($snapshot);

        $projectConfig = Craft::$app->getProjectConfig();
        $currentSites = $projectConfig->get(ProjectConfig::PATH_SITES) ?? [];
        $currentCkeditorConfigs = $projectConfig->get('ckeditor.configs') ?? [];
        $currentEntryElementSources = $projectConfig->get(ProjectConfig::PATH_ELEMENT_SOURCES . '.craft\\elements\\Entry') ?? [];
        $currentFields = $projectConfig->get(ProjectConfig::PATH_FIELDS) ?? [];
        $currentEntryTypes = $projectConfig->get(ProjectConfig::PATH_ENTRY_TYPES) ?? [];
        $currentSections = $projectConfig->get(ProjectConfig::PATH_SECTIONS) ?? [];

        $addedCkeditorConfigs = 0;
        $updatedEntryElementSources = 0;
        $addedFields = 0;
        $addedSections = 0;
        $addedEntryTypes = 0;

        foreach (($snapshot['ckeditorConfigs'] ?? []) as $configUid => $config) {
            $exists = array_key_exists((string)$configUid, $currentCkeditorConfigs);
            if (!$overwrite && ($exists || $this->ckeditorConfigExistsByName($currentCkeditorConfigs, $config))) {
                continue;
            }

            $projectConfig->set(
                'ckeditor.configs.' . $configUid,
                $config,
                sprintf('Inspire Frontend: sync CKEditor config %s', $configUid)
            );
            $addedCkeditorConfigs++;
        }

        $snapshotEntryElementSources = $snapshot['entryElementSources'] ?? [];
        if (!empty($snapshotEntryElementSources) && ($overwrite || $snapshotEntryElementSources !== $currentEntryElementSources)) {
            $projectConfig->set(
                ProjectConfig::PATH_ELEMENT_SOURCES . '.craft\\elements\\Entry',
                $snapshotEntryElementSources,
                'Inspire Frontend: sync entry element sources'
            );
            $updatedEntryElementSources++;
        }

        foreach (($snapshot['fields'] ?? []) as $fieldUid => $fieldConfig) {
            $exists = array_key_exists((string)$fieldUid, $currentFields);
            if (!$overwrite && ($exists || $this->fieldExistsByHandle($currentFields, $fieldConfig))) {
                continue;
            }

            $projectConfig->set(
                ProjectConfig::PATH_FIELDS . '.' . $fieldUid,
                $fieldConfig,
                sprintf('Inspire Frontend: sync field %s', $fieldUid)
            );
            $addedFields++;
        }

        foreach (($snapshot['entryTypes'] ?? []) as $entryTypeUid => $entryTypeConfig) {
            if ($this->isDuplicateEntryTypeConfig($entryTypeConfig)) {
                continue;
            }

            $exists = array_key_exists((string)$entryTypeUid, $currentEntryTypes);
            if (!$overwrite && ($exists || $this->entryTypeExistsByHandle($currentEntryTypes, $entryTypeConfig))) {
                continue;
            }

            $projectConfig->set(
                ProjectConfig::PATH_ENTRY_TYPES . '.' . $entryTypeUid,
                $entryTypeConfig,
                sprintf('Inspire Frontend: sync entry type %s', $entryTypeUid)
            );
            $addedEntryTypes++;
            $currentEntryTypes[(string)$entryTypeUid] = $entryTypeConfig;
        }

        foreach (($snapshot['sections'] ?? []) as $sectionUid => $sectionConfig) {
            $sectionConfig = $this->remapSectionSiteSettings($sectionConfig, $currentSites);
            $sectionConfig = $this->normalizeSectionEntryTypes($sectionConfig, $currentEntryTypes);
            $sectionExists = array_key_exists((string)$sectionUid, $currentSections);
            $sectionExistsByHandle = $this->sectionExistsByHandle($currentSections, $sectionConfig);
            $targetSectionUid = (string)$sectionUid;
            if ($overwrite || (!$sectionExists && !$sectionExistsByHandle)) {
                $projectConfig->set(
                    ProjectConfig::PATH_SECTIONS . '.' . $sectionUid,
                    $sectionConfig,
                    sprintf('Inspire Frontend: sync section %s', $sectionUid)
                );
                $addedSections++;
                $currentSections[(string)$sectionUid] = $sectionConfig;
                continue;
            }

            if (!$sectionExists && $sectionExistsByHandle) {
                $targetSectionUid = (string)($this->findSectionUidByHandle($currentSections, $sectionConfig) ?? $sectionUid);
            }

            $existingSection = $currentSections[$targetSectionUid] ?? [];
            $existingRefs = is_array($existingSection['entryTypes'] ?? null) ? $existingSection['entryTypes'] : [];
            $newRefs = is_array($sectionConfig['entryTypes'] ?? null) ? $sectionConfig['entryTypes'] : [];
            $mergedRefs = $this->mergeSectionEntryTypeRefs($existingRefs, $newRefs);
            if ($mergedRefs !== $existingRefs) {
                $projectConfig->set(
                    ProjectConfig::PATH_SECTIONS . '.' . $targetSectionUid . '.entryTypes',
                    $mergedRefs,
                    sprintf('Inspire Frontend: sync section entry types %s', $targetSectionUid)
                );
                $currentSections[$targetSectionUid]['entryTypes'] = $mergedRefs;
            }
        }

        return [
            'ckeditorConfigsAddedOrUpdated' => $addedCkeditorConfigs,
            'entryElementSourcesAddedOrUpdated' => $updatedEntryElementSources,
            'fieldsAddedOrUpdated' => $addedFields,
            'sectionsAddedOrUpdated' => $addedSections,
            'entryTypesAddedOrUpdated' => $addedEntryTypes,
        ];
    }

    private function loadBundledSnapshot(): array
    {
        $paths = [
            dirname(__DIR__) . '/snapshot/schema.php',
            dirname(__DIR__, 2) . '/snapshot/schema.php',
        ];

        $path = null;
        foreach ($paths as $candidate) {
            if (is_file($candidate)) {
                $path = $candidate;
                break;
            }
        }

        if ($path === null) {
            return [];
        }

        $snapshot = require $path;
        if (!is_array($snapshot)) {
            return [];
        }

        return $this->sanitizeSnapshot($snapshot);
    }

    private function sanitizeSnapshot(array $snapshot): array
    {
        $sections = $snapshot['sections'] ?? [];
        $entryTypes = $snapshot['entryTypes'] ?? [];
        $seenHandles = [];

        foreach ($entryTypes as $entryTypeUid => $entryTypeConfig) {
            $handle = strtolower((string)($entryTypeConfig['handle'] ?? ''));
            if (
                $this->isDuplicateEntryTypeConfig($entryTypeConfig) ||
                ($handle !== '' && isset($seenHandles[$handle]))
            ) {
                unset($entryTypes[$entryTypeUid]);
                continue;
            }

            if ($handle !== '') {
                $seenHandles[$handle] = true;
            }
        }

        foreach ($sections as $sectionUid => $sectionConfig) {
            $sections[$sectionUid] = $this->normalizeSectionEntryTypes($sectionConfig, $entryTypes);
        }

        $snapshot['sections'] = is_array($sections) ? $sections : [];
        $snapshot['fields'] = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
        $snapshot['entryTypes'] = is_array($entryTypes) ? $entryTypes : [];
        $snapshot['entryElementSources'] = is_array($snapshot['entryElementSources'] ?? null) ? $snapshot['entryElementSources'] : [];
        $snapshot['filesystems'] = is_array($snapshot['filesystems'] ?? null) ? $snapshot['filesystems'] : [];
        $snapshot['volumes'] = is_array($snapshot['volumes'] ?? null) ? $snapshot['volumes'] : [];
        $snapshot['ckeditorConfigs'] = is_array($snapshot['ckeditorConfigs'] ?? null) ? $snapshot['ckeditorConfigs'] : [];
        return $snapshot;
    }

    private function normalizeSectionEntryTypes(array $sectionConfig, array $entryTypes): array
    {
        $refs = is_array($sectionConfig['entryTypes'] ?? null) ? $sectionConfig['entryTypes'] : [];
        $normalized = [];
        $seen = [];
        foreach ($refs as $ref) {
            $uid = $this->extractEntryTypeUid($ref);
            if ($uid === null || !isset($entryTypes[$uid]) || isset($seen[$uid])) {
                continue;
            }

            $normalized[] = ['uid' => $uid];
            $seen[$uid] = true;
        }

        $sectionConfig['entryTypes'] = $normalized;
        return $sectionConfig;
    }

    private function mergeSectionEntryTypeRefs(array $existingRefs, array $newRefs): array
    {
        $merged = [];
        $seen = [];

        foreach ([$existingRefs, $newRefs] as $refs) {
            foreach ($refs as $ref) {
                $uid = $this->extractEntryTypeUid($ref);
                if ($uid === null || isset($seen[$uid])) {
                    continue;
                }
                $merged[] = ['uid' => $uid];
                $seen[$uid] = true;
            }
        }

        return $merged;
    }

    private function extractEntryTypeUid(mixed $ref): ?string
    {
        if (is_array($ref)) {
            $uid = $ref['uid'] ?? null;
            if (is_string($uid) && $uid !== '') {
                return $uid;
            }
        } elseif (is_string($ref) && $ref !== '') {
            return $ref;
        }

        return null;
    }

    private function remapSectionSiteSettings(array $sectionConfig, array $currentSites): array
    {
        $siteSettings = $sectionConfig['siteSettings'] ?? null;
        if (!is_array($siteSettings) || empty($siteSettings) || empty($currentSites)) {
            return $sectionConfig;
        }

        $fallbackTargetUid = null;
        foreach ($currentSites as $targetUid => $targetSiteConfig) {
            $fallbackTargetUid ??= (string)$targetUid;
        }

        $fallbackSourceConfig = null;
        foreach ($siteSettings as $sourceConfig) {
            if (is_array($sourceConfig)) {
                $fallbackSourceConfig = $sourceConfig;
                break;
            }
        }

        $remapped = [];
        foreach ($currentSites as $targetUid => $targetSiteConfig) {
            $uid = (string)$targetUid;
            $siteConfig = $siteSettings[$uid] ?? $fallbackSourceConfig;
            if (!is_array($siteConfig) || isset($remapped[$uid])) {
                continue;
            }

            $remapped[$uid] = $siteConfig;
        }

        if (!empty($remapped)) {
            $sectionConfig['siteSettings'] = $remapped;
        } elseif ($fallbackTargetUid !== null && is_array($fallbackSourceConfig)) {
            $sectionConfig['siteSettings'] = [$fallbackTargetUid => $fallbackSourceConfig];
        }

        return $sectionConfig;
    }

    private function fieldExistsByHandle(array $fields, array $fieldConfig): bool
    {
        $target = strtolower((string)($fieldConfig['handle'] ?? ''));
        if ($target === '') {
            return false;
        }

        foreach ($fields as $config) {
            if (strtolower((string)($config['handle'] ?? '')) === $target) {
                return true;
            }
        }

        return false;
    }

    private function sectionExistsByHandle(array $sections, array $sectionConfig): bool
    {
        return $this->findSectionUidByHandle($sections, $sectionConfig) !== null;
    }

    private function findSectionUidByHandle(array $sections, array $sectionConfig): ?string
    {
        $target = strtolower((string)($sectionConfig['handle'] ?? ''));
        if ($target === '') {
            return null;
        }

        foreach ($sections as $uid => $config) {
            if (strtolower((string)($config['handle'] ?? '')) === $target) {
                return (string)$uid;
            }
        }

        return null;
    }

    private function entryTypeExistsByHandle(array $entryTypes, array $entryTypeConfig): bool
    {
        $target = strtolower((string)($entryTypeConfig['handle'] ?? ''));
        if ($target === '') {
            return false;
        }

        foreach ($entryTypes as $config) {
            if (strtolower((string)($config['handle'] ?? '')) === $target) {
                return true;
            }
        }

        return false;
    }

    private function ckeditorConfigExistsByName(array $configs, array $config): bool
    {
        $target = strtolower((string)($config['name'] ?? ''));
        if ($target === '') {
            return false;
        }

        foreach ($configs as $existing) {
            if (strtolower((string)($existing['name'] ?? '')) === $target) {
                return true;
            }
        }

        return false;
    }

    private function isDuplicateEntryTypeConfig(array $entryTypeConfig): bool
    {
        $name = strtolower(trim((string)($entryTypeConfig['name'] ?? '')));
        $handle = strtolower(trim((string)($entryTypeConfig['handle'] ?? '')));

        return
            preg_match('/\s+2$/', $name) === 1 ||
            preg_match('/2$/', $handle) === 1;
    }
}
