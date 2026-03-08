<?php
namespace Inspire\Frontend\assetlocations\services;

use Inspire\Frontend\Frontend;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset as AssetElement;
use craft\elements\Entry as EntryElement;

class FindElementService extends Component
{
	// Public Methods
	// =========================================================================

	/**
	 * Get Entries
	 * @description Check to see if the asset is used within any elements
	 *
	 * @param String $type
	 * @param AssetElement $asset
	 * @return ?array
	 */
	public function getElements(string $type, AssetElement $asset, int $siteId): ?array
	{
		$assetEntries = [];

		// Find all Asset Fields within Entries where this asset is used
		$relatedEntries = Frontend::$plugin->assetLocationsFindAsset->checkAssetFields(
			$type,
			$asset,
			$siteId
		);

		$assetEntries = array_merge((array) $assetEntries, (array) $relatedEntries);


		// Get all Super Tables where this asset is used within Entries
		$tableEntries = Frontend::$plugin->assetLocationsFindAsset->checkSuperTableFields(
			$type,
			$asset,
			$siteId
		);
		$assetEntries = array_merge((array) $assetEntries, (array) $tableEntries);

		return $assetEntries;
	}

	/**
	 * Get Links
	 * @description Check to see if the asset is used within any links
	 *
	 * @param String $type
	 * @param AssetElement $asset
	 * @return ?array
	 */
	public function getLinks(AssetElement $asset, int $siteId): ?array
	{
		// Check the plugin is installed
		$links = Craft::$app->plugins->getPlugin('typedlinkfield', false);

		if ($links) {
			$entries = [];

			$linkQuery = new Query();
			$linkQuery = $linkQuery
				->select('elementId')
				->from('{{%lenz_linkfield}}')
				->where([
					'linkedId' => $asset->id,
					'siteId' => $siteId,
				])
				->all();

			foreach ($linkQuery as $link) {
				// Check Entries
				$linkEntry = Frontend::$plugin->assetLocationsFindAsset->checkEntriesElement(
					$link['elementId'],
					$siteId
				);

				if ((array) $linkEntry) {
					foreach ($linkEntry as $entry) {
						$title = $this->resolveEntryTitle($entry);

						$key = $this->entryKey($entry);
						$location = $this->resolveLocationLabel($entry);
						$next = (object) [
							'title' => $title,
							'cpUrl' => $this->resolveEntryCpUrl($entry),
							'url' => $this->resolveEntryUrl($entry),
							'status' => $entry->status,
							'section' => Frontend::$plugin->assetLocationsSection->getSectionName($entry),
							'location' => $location,
							'locations' => $location ? [$location] : [],
							'isActive' => (bool)$entry->enabled
								&& (bool)$entry->getEnabledForSite()
								&& $this->areOwnershipElementsEnabled($entry)
								&& (string)$entry->status === 'live',
						];

						if (isset($entries[$key])) {
							$entries[$key] = $this->mergeEntryLocation((object)$entries[$key], $next);
						} else {
							$entries[$key] = $next;
						}
					}
				}

				// Check Super Tables
				$superTableElement = Frontend::$plugin->assetLocationsFindAsset->checkSuperTableElements(
					$link['elementId'],
					$siteId
				);

				if ((array) $superTableElement) {
					$entries = array_merge((array) $entries, (array) $superTableElement);
				}
			}

			return $entries;
		} else {
			return [];
		}
	}

	private function entryKey(EntryElement $entry): string
	{
		return 'entry-' . $this->resolveRootEntryId($entry);
	}

	private function mergeEntryLocation(object $existing, object $incoming): object
	{
		$existingLocations = array_values(array_filter((array)($existing->locations ?? []), fn($v) => is_string($v) && trim($v) !== ''));
		$incomingLocations = array_values(array_filter((array)($incoming->locations ?? []), fn($v) => is_string($v) && trim($v) !== ''));
		$mergedLocations = array_values(array_unique(array_merge($existingLocations, $incomingLocations)));

		$existingTitle = trim((string)($existing->title ?? ''));
		$incomingTitle = trim((string)($incoming->title ?? ''));
		$title = (str_starts_with($existingTitle, 'Entry #') && $incomingTitle !== '') ? $incomingTitle : $existingTitle;
		if ($title === '') {
			$title = $incomingTitle;
		}

		$existingIsActive = (bool)($existing->isActive ?? true);
		$incomingIsActive = (bool)($incoming->isActive ?? true);

		return (object)[
			'title' => $title,
			'cpUrl' => $existing->cpUrl ?? $incoming->cpUrl ?? null,
			'url' => $existing->url ?? $incoming->url ?? null,
			'status' => $existing->status ?? $incoming->status ?? 'disabled',
			'section' => $existing->section ?? $incoming->section ?? null,
			'location' => $mergedLocations[0] ?? null,
			'locations' => $mergedLocations,
			'isActive' => $existingIsActive && $incomingIsActive,
		];
	}

	private function resolveEntryTitle(EntryElement $entry): string
	{
		$rootEntry = $this->resolveRootEntry($entry);
		if ($rootEntry instanceof EntryElement) {
			$candidates = [
				trim((string)$rootEntry->getUiLabel()),
				trim((string)($rootEntry->title ?? '')),
			];

			foreach ($candidates as $candidate) {
				if ($candidate !== '' && !str_starts_with($candidate, 'Entry #')) {
					return $candidate;
				}
			}
		}

		$candidates = [
			trim((string)$entry->getUiLabel()),
			trim((string)($entry->title ?? '')),
		];

		foreach ($candidates as $candidate) {
			if ($candidate !== '' && !str_starts_with($candidate, 'Entry #')) {
				return $candidate;
			}
		}

		$canonical = $entry->getCanonical();
		if ($canonical instanceof EntryElement) {
			$candidates = [
				trim((string)$canonical->getUiLabel()),
				trim((string)($canonical->title ?? '')),
			];

			foreach ($candidates as $candidate) {
				if ($candidate !== '' && !str_starts_with($candidate, 'Entry #')) {
					return $candidate;
				}
			}
		}

		if (!empty($entry->canonicalId) && (int)$entry->canonicalId !== (int)$entry->id) {
			$canonicalById = EntryElement::find()
				->id((int)$entry->canonicalId)
				->siteId($entry->siteId)
				->status(null)
				->one();

			if (!$canonicalById) {
				$canonicalById = EntryElement::find()
					->id((int)$entry->canonicalId)
					->status(null)
					->one();
			}

			if ($canonicalById instanceof EntryElement) {
				$candidates = [
					trim((string)$canonicalById->getUiLabel()),
					trim((string)($canonicalById->title ?? '')),
				];

				foreach ($candidates as $candidate) {
					if ($candidate !== '' && !str_starts_with($candidate, 'Entry #')) {
						return $candidate;
					}
				}
			}
		}

		return 'Entry #' . $entry->id;
	}

	private function resolveEntryUrl(EntryElement $entry): ?string
	{
		$url = null;
		$rootEntry = $this->resolveRootEntry($entry);
		if ($rootEntry instanceof EntryElement) {
			$url = $rootEntry->url ?: null;
		}

		$canonical = $entry->getCanonical();
		if (!$url && $canonical instanceof EntryElement) {
			$url = $canonical->url ?: null;
		}

		if (!$url && !empty($entry->canonicalId) && (int)$entry->canonicalId !== (int)$entry->id) {
			$canonicalById = EntryElement::find()
				->id((int)$entry->canonicalId)
				->siteId($entry->siteId)
				->status(null)
				->one();

			if (!$canonicalById) {
				$canonicalById = EntryElement::find()
					->id((int)$entry->canonicalId)
					->status(null)
					->one();
			}

			if ($canonicalById instanceof EntryElement) {
				$url = $canonicalById->url ?: null;
			}
		}

		if (!$url) {
			$url = $entry->url ?: null;
		}

		if (!$url) {
			return null;
		}

		$parts = parse_url($url);
		if (!$parts) {
			return $url;
		}

		$scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
		$host = $parts['host'] ?? '';
		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		$path = $parts['path'] ?? '';

		return $scheme . $host . $port . $path;
	}

	private function resolveEntryCpUrl(EntryElement $entry): ?string
	{
		$rootEntry = $this->resolveRootEntry($entry);
		if ($rootEntry instanceof EntryElement && $rootEntry->cpEditUrl) {
			return $rootEntry->cpEditUrl;
		}

		$canonical = $entry->getCanonical();
		if ($canonical instanceof EntryElement && $canonical->cpEditUrl) {
			return $canonical->cpEditUrl;
		}

		if (!empty($entry->canonicalId) && (int)$entry->canonicalId !== (int)$entry->id) {
			$canonicalById = EntryElement::find()
				->id((int)$entry->canonicalId)
				->siteId($entry->siteId)
				->status(null)
				->one();

			if (!$canonicalById) {
				$canonicalById = EntryElement::find()
					->id((int)$entry->canonicalId)
					->status(null)
					->one();
			}

			if ($canonicalById instanceof EntryElement && $canonicalById->cpEditUrl) {
				return $canonicalById->cpEditUrl;
			}
		}

		return $entry->cpEditUrl ?: null;
	}

	private function resolveRootEntry(EntryElement $entry): ?EntryElement
	{
		$rootId = $this->resolveRootEntryId($entry);

		$query = EntryElement::find()
			->id((int)$rootId)
			->siteId($entry->siteId)
			->status(null)
			->drafts(false)
			->provisionalDrafts(false)
			->revisions(false);

		$rootEntry = $query->one();
		if ($rootEntry instanceof EntryElement) {
			return $rootEntry;
		}

		$fallbackQuery = EntryElement::find()
			->id((int)$rootId)
			->status(null)
			->drafts(false)
			->provisionalDrafts(false)
			->revisions(false);

		$fallbackEntry = $fallbackQuery->one();
		return $fallbackEntry instanceof EntryElement ? $fallbackEntry : null;
	}

	private function resolveRootEntryId(EntryElement $entry): int
	{
		$entryId = (int)$entry->id;
		$rootId = (int)($entry->canonicalId ?: 0);

		if ($rootId <= 0) {
			$rootId = (int)(new Query())
				->select('canonicalId')
				->from(Table::ELEMENTS)
				->where(['id' => $entryId])
				->scalar();
		}

		if ($rootId <= 0) {
			$rootId = (int)(new Query())
				->select('canonicalId')
				->from(Table::DRAFTS)
				->where(['draftId' => $entryId])
				->scalar();
		}

		if ($rootId <= 0) {
			$rootId = (int)(new Query())
				->select('canonicalId')
				->from(Table::REVISIONS)
				->where(['revisionId' => $entryId])
				->scalar();
		}

		$ownerRootId = $this->resolveOwnerEntryId($entryId);
		if ($ownerRootId <= 0 && $rootId > 0 && $rootId !== $entryId) {
			$ownerRootId = $this->resolveOwnerEntryId($rootId);
		}
		if ($ownerRootId > 0) {
			$rootId = $ownerRootId;
		}

		return $rootId > 0 ? $rootId : $entryId;
	}

	private function resolveOwnerEntryId(int $elementId): int
	{
		$currentId = $elementId;
		$lastEntryOwnerId = 0;

		for ($i = 0; $i < 15; $i++) {
			$ownerId = (int)(new Query())
				->select('ownerId')
				->from(Table::ELEMENTS_OWNERS)
				->where(['elementId' => $currentId])
				->orderBy(['sortOrder' => SORT_ASC])
				->scalar();

			if ($ownerId <= 0) {
				break;
			}

			$ownerType = (string)(new Query())
				->select('type')
				->from(Table::ELEMENTS)
				->where(['id' => $ownerId])
				->scalar();

			if (str_contains($ownerType, '\\elements\\Entry')) {
				$lastEntryOwnerId = $ownerId;
			}

			$currentId = $ownerId;
		}

		return $lastEntryOwnerId;
	}

	private function resolveLocationLabel(EntryElement $entry): ?string
	{
		$path = $this->resolveOwnershipPath((int)$entry->id);
		if (count($path) < 2) {
			return null;
		}

		$rootEntryId = $this->resolveRootEntryId($entry);
		$pathCount = count($path);
		$pathRoot = (int)$path[$pathCount - 1];

		if ($pathRoot !== $rootEntryId) {
			return null;
		}

		$childId = (int)$path[$pathCount - 2];
		if ($childId <= 0 || $childId === $rootEntryId) {
			return null;
		}

		$child = Craft::$app->elements->getElementById($childId, null, $entry->siteId);
		if (!$child) {
			return null;
		}

		if ($child instanceof EntryElement) {
			$title = trim((string)$child->getUiLabel());
			if ($title !== '' && !str_starts_with($title, 'Entry #')) {
				return $title;
			}

			$title = trim((string)($child->title ?? ''));
			if ($title !== '' && !str_starts_with($title, 'Entry #')) {
				return $title;
			}

			$type = $child->getType();
			if ($type && !empty($type->name)) {
				return trim((string)$type->name);
			}
		}

		if (method_exists($child, 'getType')) {
			$type = $child->getType();
			if ($type && isset($type->name) && trim((string)$type->name) !== '') {
				return trim((string)$type->name);
			}
		}

		return null;
	}

	private function resolveOwnershipPath(int $sourceId): array
	{
		$path = [$sourceId];
		$currentId = $sourceId;

		for ($i = 0; $i < 15; $i++) {
			$ownerId = (int)(new Query())
				->select('ownerId')
				->from(Table::ELEMENTS_OWNERS)
				->where(['elementId' => $currentId])
				->orderBy(['sortOrder' => SORT_ASC])
				->scalar();

			if ($ownerId <= 0 || in_array($ownerId, $path, true)) {
				break;
			}

			$path[] = $ownerId;
			$currentId = $ownerId;
		}

		return $path;
	}

	private function areOwnershipElementsEnabled(EntryElement $entry): bool
	{
		$path = $this->resolveOwnershipPath((int)$entry->id);
		if (empty($path)) {
			return true;
		}

		foreach ($path as $elementId) {
			$enabled = (new Query())
				->select('enabled')
				->from(Table::ELEMENTS)
				->where(['id' => (int)$elementId])
				->scalar();

			if ($enabled !== null && (int)$enabled === 0) {
				return false;
			}

			$siteEnabled = (new Query())
				->select('enabled')
				->from(Table::ELEMENTS_SITES)
				->where([
					'elementId' => (int)$elementId,
					'siteId' => (int)$entry->siteId,
				])
				->scalar();

			if ($siteEnabled !== null && (int)$siteEnabled === 0) {
				return false;
			}
		}

		return true;
	}
}
