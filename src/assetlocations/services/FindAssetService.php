<?php
namespace Kdgraphics\BaseTemplate\assetlocations\services;

use Kdgraphics\BaseTemplate\BaseTemplate;

use Craft;
use craft\base\Component;

use craft\db\Query;
use craft\db\Table;

use craft\elements\Asset as AssetElement;
use craft\elements\Entry as EntryElement;

use verbb\supertable\elements\SuperTableBlockElement;

class FindAssetService extends Component
{
	// Public Methods
	// =========================================================================

	/**
	 * Check Asset Fields
	 * @description Check to see if the asset is used within any asset fields
	 *
	 * @param String $type
	 * @param Asset $asset
	 * @param Int $siteId
	 * @return ?object
	 */
	public function checkAssetFields(string $type, AssetElement $asset, int $siteId): ?object
	{
		$entries = (object) [];
		$relatedEntries = $type
			::find()
			->siteId($siteId)
			->status(null)
			->relatedTo(["targetElement" => $asset->id])
			->all();

		foreach ($relatedEntries as $entry) {
			if (!$entry instanceof EntryElement) {
				continue;
			}

			$key = $this->entryKey($entry);
			$next = $this->buildEntryLocation($entry, $asset);
			if (isset($entries->$key)) {
				$entries->$key = $this->mergeEntryLocation($entries->$key, $next);
			} else {
				$entries->$key = $next;
			}
		}

		return $entries;
	}

    /**
	 * Check Entries Element
	 * @description Check to see if the asset is used within any entries links
	 *
	 * @param String $id
	 * @param Int $siteId
	 * @return ?object
	 */
	public function checkEntriesElement(string $id, int $siteId): ?array
	{
		$linkEntry = EntryElement::find()
            ->id($id)
            ->siteId($siteId)
            ->all();

        return $linkEntry;
	}

	/**
	 * Check Super Table Fields
	 * @description Check to see if the asset is used within any super table fields
	 *
	 * @param String $type
	 * @param Asset $asset
	 * @return ?object
	 */
	public function checkSuperTableFields(string $type, AssetElement $asset, int $siteId): ?object
	{
		// Check the plugin is installed
		$table = Craft::$app->plugins->getPlugin("super-table", false);

		$tableEntries = (object) [];

		if (isset($table) && $table->isInstalled) {
			$relatedTable = SuperTableBlockElement::find()
				->siteId($siteId)
				->relatedTo(["targetElement" => $asset->id])
				->orderBy("id")
				->all();

			// Get the entry/category the block appears on
			foreach ($relatedTable as $item) {
				// Check for Matrix Blocks
				$results = (new Query())
					->from(Table::ELEMENTS)
					->where(["canonicalId" => $item->primaryOwnerId])
					->all();

				foreach ($results as $result) {
					if (str_contains($result["type"], "Matrix")) {
						$matrixQuery = new Query();
						$matrix = $matrixQuery
							->select("primaryOwnerId")
							->from(Table::MATRIXBLOCKS)
							->where(["id" => $result["id"]])
							->one();

						if ($matrix) {
							$elementQuery = new Query();
							$element = $elementQuery
								->select("canonicalId")
								->from(Table::ELEMENTS)
								->where(["id" => $matrix["primaryOwnerId"]])
								->one();

							if ($element) {
								$entry = $type
									::find()
									->siteId($siteId)
									->id($element["canonicalId"])
									->status(null)
									->one();

								if ($entry) {
									$key = $this->entryKey($entry);
									$next = $this->buildEntryLocation($entry, $asset);
									if (isset($tableEntries->$key)) {
										$tableEntries->$key = $this->mergeEntryLocation($tableEntries->$key, $next);
									} else {
										$tableEntries->$key = $next;
									}
								}
							}
						}
					}
				}

				// Elements
				$elements = (new Query())
					->from(Table::ELEMENTS)
					->where(["id" => $item->primaryOwnerId])
					->andWhere("canonicalId IS NULL")
					->all();

				foreach ($elements as $element) {
					$entry = $type
						::find()
						->siteId($siteId)
						->status(null)
						->id($element["id"])
						->one();

					if ($entry) {
						$key = $this->entryKey($entry);
						$next = $this->buildEntryLocation($entry, $asset);
						if (isset($tableEntries->$key)) {
							$tableEntries->$key = $this->mergeEntryLocation($tableEntries->$key, $next);
						} else {
							$tableEntries->$key = $next;
						}
					}
				}
			}
		}

		return $tableEntries;
	}

    /**
	 * Check Super Table Elements
	 * @description Check to see if the asset is used within any super table elements
	 *
	 * @param String $type
	 * @param Asset $asset
	 * @return ?object
	 */
	public function checkSuperTableElements(string $id, int $siteId): ?object
	{
		// Check the plugin is installed
		$table = Craft::$app->plugins->getPlugin("super-table", false);

		$tableEntries = (object) [];

		if (isset($table) && $table->isInstalled) {
			$relatedTable = SuperTableBlockElement::find()
                ->id($id)
				->siteId($siteId)
				->orderBy("id")
				->all();

			// Get the entry/category the block appears on
			foreach ($relatedTable as $item) {
				// Check for Matrix Blocks
				$results = (new Query())
					->from(Table::ELEMENTS)
					->where(["canonicalId" => $item->primaryOwnerId])
					->all();

				foreach ($results as $result) {
					if (str_contains($result["type"], "Matrix")) {
						$matrixQuery = new Query();
						$matrix = $matrixQuery
							->select("primaryOwnerId")
							->from(Table::MATRIXBLOCKS)
							->where(["id" => $result["id"]])
							->one();

						if ($matrix) {
							$elementQuery = new Query();
							$element = $elementQuery
								->select("canonicalId")
								->from(Table::ELEMENTS)
								->where(["id" => $matrix["primaryOwnerId"]])
								->one();

							if ($element) {
								$entry = EntryElement
									::find()
									->siteId($siteId)
									->id($element["canonicalId"])
									->status(null)
									->one();

								if ($entry) {
									$key = $this->entryKey($entry);
									$next = $this->buildEntryLocation($entry);
									if (isset($tableEntries->$key)) {
										$tableEntries->$key = $this->mergeEntryLocation($tableEntries->$key, $next);
									} else {
										$tableEntries->$key = $next;
									}
								}
							}
						}
					}
				}

				// Elements
				$elements = (new Query())
					->from(Table::ELEMENTS)
					->where(["id" => $item->primaryOwnerId])
					->andWhere("canonicalId IS NULL")
					->all();

				foreach ($elements as $element) {
					$entry = EntryElement
						::find()
						->siteId($siteId)
						->status(null)
						->id($element["id"])
						->one();

					if ($entry) {
						$key = $this->entryKey($entry);
						$next = $this->buildEntryLocation($entry);
						if (isset($tableEntries->$key)) {
							$tableEntries->$key = $this->mergeEntryLocation($tableEntries->$key, $next);
						} else {
							$tableEntries->$key = $next;
						}
					}
				}
			}
		}

		return $tableEntries;
	}

	private function entryKey(EntryElement $entry): string
	{
		return 'entry-' . $this->resolveRootEntryId($entry);
	}

	private function buildEntryLocation(EntryElement $entry, ?AssetElement $asset = null): object
	{
		$title = $this->resolveEntryTitle($entry);

		$assetEnabled = true;
		if ($asset instanceof AssetElement) {
			$assetEnabled = (bool)$asset->enabled && (bool)$asset->getEnabledForSite();
		}

		$entryEnabled = (bool)$entry->enabled && (bool)$entry->getEnabledForSite();
		$ownersEnabled = $this->areOwnershipElementsEnabled($entry);
		$isActive = $assetEnabled && $entryEnabled && $ownersEnabled && (string)$entry->status === 'live';

		$location = $this->resolveLocationLabel($entry);

		return (object)[
			'title' => $title,
			'cpUrl' => $this->resolveEntryCpUrl($entry),
			'url' => $this->resolveEntryUrl($entry),
			'status' => $entry->status,
			'section' => BaseTemplate::$plugin->assetLocationsSection->getSectionName($entry),
			'location' => $location,
			'locations' => $location ? [$location] : [],
			'isActive' => $isActive,
		];
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
