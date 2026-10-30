<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\{Component, Field, NestedElementInterface};
use craft\elements\{Asset, Entry};
use craft\enums\MenuItemType;
use craft\events\DefineMenuItemsEvent;
use craft\helpers\{App, ArrayHelper};
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\jobs\GenerateAiAltText as GenerateAiAltTextJob;
use Throwable;

/**
 * AI Alt Text Service
 *
 * Main service class for generating alt text using AI.
 * This service coordinates between the provider services and Craft CMS assets.
 */
class AiAltTextService extends Component
{

    /**
     * Generates or queues alt text for an asset, once for each group of sites that share it (see
     * sitesSharingAltText()): the current site's group straight away or as a queued job, and, when
     * Save translated results for each site is on, a queued job for each other group.
     *
     * @param Asset $asset The asset to create a job for
     * @param bool $saveCurrentSiteOffQueue Whether to process the current site off queue
     * @param int|null $currentSiteId The current site ID
     * @param bool $skipExistingJobCheck Whether to skip the check for existing jobs (useful for bulk operations)
     * @param bool $skipSaveTranslatedResultsToEachSiteSetting Whether to only do the current site's group, whatever the setting
     * @param array|null $existingJobs Queue job info to check against, so bulk callers can fetch it once instead of per asset
     * @return bool Whether anything was generated or queued, false if the asset was skipped
     * @throws Exception
     */
    public function createJob(Asset $asset, $saveCurrentSiteOffQueue = false, $currentSiteId = null, $skipExistingJobCheck = false, $skipSaveTranslatedResultsToEachSiteSetting = false, ?array $existingJobs = null): bool
    {
        $queue = Craft::$app->getQueue();

        $assetSiteId = (int)($currentSiteId ?? $asset->siteId);

        // Check if there's already a job for this element
        if (!$skipExistingJobCheck) {
            $existingJobs ??= $queue->getJobInfo();
            $hasPlusOneSite = count(Craft::$app->getSites()->getAllSites()) > 1;
            $hasExistingJob = $this->hasPendingJob($existingJobs, $this->jobMarker($asset->id, $assetSiteId, $hasPlusOneSite));

            if ($hasExistingJob) {
                $message = Craft::t('ai-alt-text', '{filename} (ID: {id}.{siteMessageSuffix}) is already being processed within an existing queued job. Please wait for the existing job to finish before attempting to process it again.', [
                    'filename' => $asset->filename,
                    'id' => $asset->id,
                    'siteMessageSuffix' => $hasPlusOneSite ? " Site: $assetSiteId." : "",
                ]);
                
                // Logged rather than flashed: callers report the skip themselves, and this also runs in queue jobs
                Craft::info($message, __METHOD__);
                return false;
            }
        }

        if ($asset->kind !== Asset::KIND_IMAGE) {
            $message = Craft::t('ai-alt-text', '{filename} (ID: {id}) is not an image', [
                'filename' => $asset->filename,
                'id' => $asset->id,
            ]);
            
            Craft::info($message, __METHOD__);
            return false;
        }

        // Skip SVG assets if SVG processing is off, or Craft's image driver can't read them
        if ($this->isSvg($asset) && !$this->canProcessSvgs()) {
            Craft::debug("Skipping alt text generation for SVG asset {$asset->id} because SVG processing is off or Craft's image driver can't read SVGs.", __METHOD__);
            return false;
        }

        // Get the $saveTranslatedResultsToEachSite setting value
        $saveTranslatedResultsToEachSite = $skipSaveTranslatedResultsToEachSiteSetting ? false : AiAltText::getInstance()->settings->saveTranslatedResultsToEachSite;

        // Sites that share alt text are generated once. The first set is the current site's, led by it.
        $siteSets = $this->sitesSharingAltText($asset, $assetSiteId);
        $sharedSiteIds = array_slice(array_shift($siteSets), 1);

        // Check if we need to save the current site off queue
        if ($saveCurrentSiteOffQueue) {
            $this->generateAltText($asset, $assetSiteId, $sharedSiteIds);
    
            if (!$saveTranslatedResultsToEachSite) {
                return true;
            }
        }

        $hasPlusOneSite = count(Craft::$app->getSites()->getAllSites()) > 1;

        // Queue the current site's job — unless generateAltText() already handled it off queue
        // above, in which case queueing it here would generate it a second time.
        if (!$saveCurrentSiteOffQueue) {
            $queue->push(new GenerateAiAltTextJob([
                'description' => $this->jobDescription($asset, $assetSiteId, $hasPlusOneSite),
                'assetId' => $asset->id,
                'siteId' => $assetSiteId,
                'sharedSiteIds' => $sharedSiteIds,
            ]));
        }

        // return early if we're not saving translated results to each site
        if (!$saveTranslatedResultsToEachSite) {
            return true;
        }

        // Queue a job for each set of *other* sites that share alt text, generated for the set's first site
        // and saved to the rest. The current site's set is always already handled above — either inline via
        // generateAltText() or by the push above — so it must never be queued again here.
        foreach ($siteSets as $siteIds) {
            $siteId = array_shift($siteIds);

            // Skip sets that already have a pending job for this asset
            if ($existingJobs !== null && $this->hasPendingJob($existingJobs, $this->jobMarker($asset->id, $siteId, $hasPlusOneSite))) {
                continue;
            }

            $queue->push(new GenerateAiAltTextJob([
                'description' => $this->jobDescription($asset, $siteId, $hasPlusOneSite),
                'assetId' => $asset->id,
                'siteId' => $siteId,
                'sharedSiteIds' => $siteIds,
            ]));
        }

        return true;
    }

    /**
     * Handles an asset in a bulk run for its group of sites that share alt text (see siteIdsBySharedAltText()),
     * so the group costs one request at most:
     *
     * - In a run for missing alt text, alt text a site in the group already has is copied to the ones without.
     * - Otherwise one job is queued, generating for one site and saving to the group. In an all-sites run
     *   that's the group's first site, and the group's other sites are skipped.
     *
     * @param Asset $asset The asset, on the site the run reached it on
     * @param bool $includeExisting Whether the run includes assets that already have alt text
     * @param bool $allSites Whether the run covers every site, rather than just the asset's
     * @param array|null $existingJobs Queue job info for the duplicate-job check
     * @return string `queued`, `copied`, `excluded` by the Excluded assets settings (see exclusionReason()), or
     * `skipped` when an earlier site sharing its alt text handles it, a job is already queued, or the asset isn't eligible
     * @throws Exception
     */
    public function createBulkJob(Asset $asset, bool $includeExisting, bool $allSites, ?array $existingJobs = null): string
    {
        if (($reason = $this->exclusionReason($asset)) !== null) {
            Craft::info("Skipping {$asset->filename} (ID: {$asset->id}) in a bulk run: $reason", __METHOD__);
            return 'excluded';
        }

        $siteIds = $this->siteIdsBySharedAltText($asset)[$this->sharedAltTextKey($asset)];

        if (!$includeExisting) {
            $siteAssets = ArrayHelper::index(Asset::find()->id($asset->id)->siteId($siteIds)->all(), 'siteId');
            // In site order, so the alt text copied is the first site's that has some
            $existingAltText = ArrayHelper::firstValue(array_filter(array_map(fn(int $siteId) => $siteAssets[$siteId]->alt ?? null, $siteIds)));

            if ($existingAltText !== null) {
                foreach ($siteAssets as $siteAsset) {
                    if (empty($siteAsset->alt)) {
                        $siteAsset->alt = $existingAltText;

                        if (!Craft::$app->elements->saveElement($siteAsset, true, false)) {
                            throw new Exception("Failed to copy alt text for asset: {$siteAsset->filename} (site {$siteAsset->siteId})");
                        }
                    }
                }

                return 'copied';
            }
        }

        if ($allSites && $siteIds[0] !== $asset->siteId) {
            return 'skipped';
        }

        // The caller has already chosen the sites, so skip the saveTranslatedResultsToEachSite fan-out
        return $this->createJob($asset, false, $asset->siteId, false, true, $existingJobs) ? 'queued' : 'skipped';
    }

    /**
     * Why bulk and automatic generation skip an asset under the Excluded assets settings, or null if they don't.
     * Generating for chosen assets on request isn't affected.
     *
     * - Its volume is an excluded volume.
     * - Its folder is an excluded folder, or inside one. Folders are written `volumeHandle/path`, e.g. `images/icons`.
     * - It's related to entries in the excluded sections: any of them, or, with the match set to `all`, every one.
     *   An asset in a nested entry, such as one in a Matrix field, counts as related to the entry that owns it.
     *   A newly uploaded asset isn't related to any entry yet, so this only applies once it's used.
     *
     * @return string|null The reason, e.g. `its volume is excluded`
     */
    public function exclusionReason(Asset $asset): ?string
    {
        $settings = AiAltText::getInstance()->getSettings();
        $volume = $asset->getVolume();

        if (in_array($volume->uid, $settings->excludedVolumes, true)) {
            return "its volume ($volume->handle) is excluded";
        }

        // e.g. `images/icons/` for an asset in the icons folder of the images volume, and `images/` for its root
        $path = $volume->handle . '/' . ltrim($asset->getFolder()->path ?? '', '/');
        foreach (preg_split('/\R/', $settings->excludedFolders, -1, PREG_SPLIT_NO_EMPTY) as $folder) {
            $folder = trim(trim($folder), '/') . '/';
            if ($folder !== '/' && str_starts_with($path, $folder)) {
                return "its folder ($folder) is excluded";
            }
        }

        if (empty($settings->excludedSections)) {
            return null;
        }

        // The sections of the entries it's related to, on any site, by the entry that owns any nested ones
        $sectionUids = [];
        foreach (Entry::find()->relatedTo(['targetElement' => $asset->id])->site('*')->unique()->status(null)->all() as $entry) {
            while ($entry instanceof NestedElementInterface && ($owner = $entry->getOwner()) !== null) {
                $entry = $owner;
            }

            // An entry nested in something other than an entry, such as a global set, has no section
            $sectionUids[] = $entry instanceof Entry ? $entry->getSection()?->uid : null;
        }

        $excluded = array_intersect($sectionUids, $settings->excludedSections);

        if ($settings->excludedSectionsMatch === 'all'
            ? !empty($sectionUids) && count($excluded) === count($sectionUids)
            : !empty($excluded)
        ) {
            return 'it is used in an excluded section';
        }

        return null;
    }

    /**
     * The asset's sites, in sets that share alt text (see siteIdsBySharedAltText()). The current
     * site's set comes first, led by the current site.
     *
     * @return int[][] Site IDs
     */
    private function sitesSharingAltText(Asset $asset, int $currentSiteId): array
    {
        $currentSet = [$currentSiteId];
        $otherSets = [];

        foreach ($this->siteIdsBySharedAltText($asset) as $siteIds) {
            if (in_array($currentSiteId, $siteIds, true)) {
                $currentSet = [$currentSiteId, ...array_diff($siteIds, [$currentSiteId])];
            } else {
                $otherSets[] = $siteIds;
            }
        }

        return [$currentSet, ...$otherSets];
    }

    /**
     * The asset's site IDs, by the alt text they share: the same key under the volume's Alternative
     * Text Translation Method (see sharedAltTextKey()). Keyed by that, with the sites in site order, e.g.
     * `['en-GB' => [5], 'de-DE' => [4], 'fr-FR' => [6, 8]]` on a volume set to Translate for each language,
     * where sites 6 and 8 are both French.
     *
     * @return int[][]
     */
    private function siteIdsBySharedAltText(Asset $asset): array
    {
        // Not translatable gives every site the same key, so there's no need to load the asset on each site
        if ($asset->getVolume()->altTranslationMethod === Field::TRANSLATION_METHOD_NONE) {
            return [$this->sharedAltTextKey($asset) => array_map(fn($site) => $site->id, Craft::$app->getSites()->getAllSites())];
        }

        $siteAssets = ArrayHelper::index(Asset::find()->id($asset->id)->site('*')->all(), 'siteId');
        $siteIds = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            // A new asset is generated for as soon as it's saved, before Craft has propagated it to its
            // other sites, so a site it isn't on yet works out its key from a copy of it
            if (!isset($siteAssets[$site->id])) {
                $siteAssets[$site->id] = clone $asset;
                $siteAssets[$site->id]->siteId = $site->id;
            }

            $siteIds[$this->sharedAltTextKey($siteAssets[$site->id])][] = $site->id;
        }

        return $siteIds;
    }

    /**
     * The key shared by the sites that share an asset's alt text, for the asset on one of them: Craft's
     * alt translation key, so for example every site in a site group, or every site on a Not translatable
     * volume, shares one alt text, in the language of the site it's generated for.
     */
    private function sharedAltTextKey(Asset $siteAsset): string
    {
        return $siteAsset->getAltTranslationKey();
    }

    /**
     * Whether any unfailed job in the queue info ends with the given marker. The untranslated marker is
     * always the end of the description (see jobDescription()), so a translation or a filename can't
     * affect the match, and "ID: 1" can't match "ID: 12".
     */
    private function hasPendingJob(array $jobs, string $marker): bool
    {
        foreach ($jobs as $job) {
            if (isset($job['description']) && $job['status'] !== 4 && str_ends_with($job['description'], $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The untranslated, machine-matchable end of a queued job's description, which the
     * duplicate-job check in createJob() compares against. The site is only named on multisite installs.
     */
    private function jobMarker(int $assetId, int $siteId, bool $multiSite): string
    {
        return " (ID: $assetId." . ($multiSite ? " Site: $siteId." : '') . ')';
    }

    /**
     * A queued job's description: translated text, then the untranslated marker.
     */
    private function jobDescription(Asset $asset, int $siteId, bool $multiSite): string
    {
        return Craft::t('ai-alt-text', 'Generating alt text for {filename}', [
            'filename' => $asset->filename,
        ]) . $this->jobMarker($asset->id, $siteId, $multiSite);
    }

    /**
     * Whether createJob() queues jobs for sites other than the one it was called for, so callers
     * that generate the current site inline can say the rest is still pending.
     */
    public function queuesOtherSites(): bool
    {
        return Craft::$app->getIsMultiSite()
            && AiAltText::getInstance()->settings->saveTranslatedResultsToEachSite;
    }

    /**
     * Returns a message describing what's missing from the provider configuration, or null if
     * generation can proceed. A missing or unrecognised provider must never fall through to OpenAI.
     */
    public function getConfigurationError(): ?string
    {
        $settings = AiAltText::getInstance()->getSettings();
        $provider = App::parseEnv($settings->aiProvider);

        if ($provider === '' || $provider === null) {
            return Craft::t('ai-alt-text', 'No AI provider is configured. Choose one in the AI Alt Text plugin settings.');
        }

        if (!in_array($provider, ['openai', 'anthropic', 'langdock', 'openrouter'], true)) {
            return Craft::t('ai-alt-text', '"{provider}" is not a supported AI provider. Choose OpenAI, Anthropic, Langdock or OpenRouter in the AI Alt Text plugin settings.', ['provider' => $provider]);
        }

        [$label, $apiKey] = match ($provider) {
            'anthropic' => ['Anthropic', $settings->anthropicApiKey],
            'langdock' => ['Langdock', $settings->langdockApiKey],
            'openai' => ['OpenAI', $settings->openAiApiKey],
            'openrouter' => ['OpenRouter', $settings->openRouterApiKey],
        };

        $apiKey = App::parseEnv($apiKey);

        if ($apiKey === '' || $apiKey === null) {
            return Craft::t('ai-alt-text', 'No API key is configured for the {provider} provider. Add one in the AI Alt Text plugin settings.', ['provider' => $label]);
        }

        if ($provider === 'langdock' && !in_array(App::parseEnv($settings->langdockModelAuthor), ['anthropic', 'openai'], true)) {
            return Craft::t('ai-alt-text', 'The Langdock Model Author must be Anthropic or OpenAI. Choose one in the AI Alt Text plugin settings.');
        }

        return null;
    }

    /**
     * Returns the service for the configured provider. Only call once getConfigurationError() is null.
     * An unrecognised provider throws an UnhandledMatchError rather than falling back to OpenAI.
     */
    private function getProviderService(): ApiService
    {
        $plugin = AiAltText::getInstance();
        $settings = $plugin->getSettings();

        return match (App::parseEnv($settings->aiProvider)) {
            'anthropic' => $plugin->anthropicService,
            'langdock' => App::parseEnv($settings->langdockModelAuthor) === 'openai'
                ? $plugin->langdockOpenAiService
                : $plugin->langdockAnthropicService,
            'openai' => $plugin->openAiService,
            'openrouter' => $plugin->openRouterService,
        };
    }

    /**
     * Generates alt text for an asset using AI.
     *
     * This method:
     * - Validates the asset
     * - Generates alt text using the OpenAI service
     * - Returns the generated alt text
     *
     * @param Asset $asset The asset to generate alt text for
     * @param int|null $siteId The site ID
     * @param int[] $sharedSiteIds Other sites that share the site's alt text, saved with the same result
     * @return string The generated alt text
     * @throws Exception If the asset is invalid or alt text generation fails
     */
    public function generateAltText(Asset $asset, ?int $siteId = null, array $sharedSiteIds = []): string
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            throw new Exception('Asset must be an image');
        }

        $error = $this->getConfigurationError();

        if ($error !== null) {
            throw new Exception($error);
        }

        $altText = $this->getProviderService()->generateAltText($asset, $siteId);

        if (empty($altText)) {
            throw new Exception('Empty alt text generated for asset: ' . $asset->filename);
        }

        // The site and the sites sharing its alt text are saved in one transaction, so if any save
        // fails - a validation error, a beforeSave veto from another plugin, a DB problem - they all
        // keep their previous alt text, rather than some sites having the new value and some not.
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $asset->alt = $altText;

            Craft::info("Saving AI alt text for asset {$asset->id}", __METHOD__);

            // Not propagated, so only the sites that share its alt text get it, saved below.
            if (!Craft::$app->elements->saveElement($asset, true, false)) {
                throw new Exception('Failed to save alt text for asset: ' . $asset->filename);
            }

            foreach ($sharedSiteIds as $sharedSiteId) {
                $sharedAsset = Asset::find()->id($asset->id)->siteId($sharedSiteId)->one();

                // Skip sites the asset has left since the job was queued
                if (!$sharedAsset) {
                    continue;
                }

                $sharedAsset->alt = $altText;

                if (!Craft::$app->elements->saveElement($sharedAsset, true, false)) {
                    throw new Exception("Failed to save alt text for asset: {$asset->filename} (site $sharedSiteId)");
                }
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        Craft::info('Successfully saved alt text for asset: ' . $asset->filename, __METHOD__);
        return $altText;
    }

    /**
     * Whether SVGs get alt text: the Process SVGs setting is on and Craft uses the Imagick image driver, as GD
     * can't read SVGs.
     */
    public function canProcessSvgs(): bool
    {
        return AiAltText::getInstance()->getSettings()->processSvgs && Craft::$app->getImages()->getIsImagick();
    }

    /**
     * Determines if an asset is an SVG file.
     *
     * @param Asset $asset
     * @return bool
     */
    public function isSvg(Asset $asset): bool
    {
        return $asset->getMimeType() === 'image/svg+xml';
    }

    /**
     * Determines if an asset is an AVIF file.
     *
     * @param Asset $asset
     * @return bool
     */
    public function isAvif(Asset $asset): bool
    {
        return $asset->getMimeType() === 'image/avif';
    }

    /**
     * Determines if an asset is a HEIC/HEIF file.
     *
     * @param Asset $asset
     * @return bool
     */
    public function isHeic(Asset $asset): bool
    {
        return in_array($asset->getMimeType(), ['image/heic', 'image/heif'], true);
    }

    /**
     * Handles the definition of action menu items for assets.
     *
     * This method adds a "Generate AI Alt Text" action to the dropdown menu
     * for image assets.
     *
     * @param DefineMenuItemsEvent $event The event containing the menu items
     */
    public function handleAssetActionMenuItems(DefineMenuItemsEvent $event): void
    {
        /** @var Asset $asset */
        $asset = $event->sender;
        $view = Craft::$app->getView();

        // Check if this is an image asset
        if ($asset->kind === 'image') {
            // Add the "Generate AI Alt Text" action to the dropdown
            $customActionId = sprintf('action-generate-ai-alt-%s', mt_rand());
            $event->items[] = [
                'type' => MenuItemType::Button,
                'id' => $customActionId,
                'icon' => 'language', // Use a relevant icon
                'label' => Craft::t('ai-alt-text', 'Generate AI Alt Text'),
            ];

            // Register the JavaScript for the action
            $view->registerJsWithVars(fn($id, $assetId, $siteId) => <<<JS
$('#' + $id).on('activate', () => {
  // Show a loading spinner in the UI
  Craft.cp.displayNotice(Craft.t('ai-alt-text', 'Generating AI alt text...'));
  
  // Make an AJAX request to your controller action
  Craft.sendActionRequest('POST', 'ai-alt-text/generate/single-asset', {
    data: {
      assetId: $assetId,
      siteId: $siteId,
    }
  })
  .then((response) => {
    if (response.data.success) {
        Craft.cp.displayNotice(response.data.message);
      
      // Refresh the elements in the current view if possible
      if (Craft.cp.elementIndex) {
        Craft.cp.elementIndex.updateElements();
        return;
      } 
      
      // @todo find a way to update the visible content in the element editor

      // Refresh the single asset page, check if current url contains "assets/edit"
      if (window.location.href.includes("assets/edit")) {
        window.location.reload();
      }
      return;
    }
    throw new Error(response.data.message);
  })
  .catch((error) => {
    console.log('catch', JSON.stringify(error));
    Craft.cp.displayError(Craft.t('ai-alt-text', 'Failed to process alt text generation: ') + 
      (error?.message || 'Unknown error'));
  });
});
JS, [
                $view->namespaceInputId($customActionId),
                $asset->id,
                $asset->siteId,
            ]);
        }
    }

}
