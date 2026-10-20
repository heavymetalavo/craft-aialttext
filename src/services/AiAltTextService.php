<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\enums\MenuItemType;
use craft\events\DefineMenuItemsEvent;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
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
     * Creates a job for the given element
     *
     * @param Asset $asset The asset to create a job for
     * @param bool $saveCurrentSiteOffQueue Whether to process the current site off queue
     * @param int|null $currentSiteId The current site ID
     * @param bool $skipExistingJobCheck Whether to skip the check for existing jobs (useful for bulk operations)
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

        // Skip SVG assets if SVG processing is disabled
        if ($this->isSvg($asset) && !AiAltText::getInstance()->getSettings()->processSvgs) {
            Craft::debug("Skipping alt text generation for SVG asset {$asset->id} because SVG processing is disabled.", __METHOD__);
            return false;
        }

        // Get the $saveTranslatedResultsToEachSite setting value
        $saveTranslatedResultsToEachSite = $skipSaveTranslatedResultsToEachSiteSetting ? false : AiAltText::getInstance()->settings->saveTranslatedResultsToEachSite;

        // Sites that share alt text are generated once. The first group is the current site's, led by it.
        $siteGroups = $this->groupSitesSharingAltText($asset, $assetSiteId);
        $sharedSiteIds = array_slice(array_shift($siteGroups), 1);

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

        // Queue a job for each group of *other* sites, generated for the group's first site and saved
        // to the rest. The current site's group is always already handled above — either inline via
        // generateAltText() or by the push above — so it must never be queued again here.
        foreach ($siteGroups as $siteIds) {
            $siteId = array_shift($siteIds);

            // Skip groups that already have a pending job for this asset
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
     * Handles an asset a bulk run has reached on one site, once for the sites that share its alt text
     * (see siteAssetsBySharedAltText()), so they cost at most one request between them:
     *
     * - In a run for assets without alt text, if a site in the group has some, it's copied to the
     *   group's empty sites instead of generating text that would overwrite it.
     * - Otherwise a job is queued for the group's first site in the run, which saves to the whole
     *   group, and the group's other sites skip the asset. In a run for one site, that's the site.
     *
     * @param Asset $asset The asset, on the site the run reached it on
     * @param bool $includeExisting Whether the run includes assets that already have alt text
     * @param bool $allSites Whether the run covers every site, rather than just the asset's
     * @param array|null $existingJobs Queue job info for the duplicate-job check
     * @return string `queued`, `copied`, or `skipped` when an earlier site in the group handles it,
     * a job is already queued, or the asset isn't eligible
     * @throws Exception
     */
    public function createBulkJob(Asset $asset, bool $includeExisting, bool $allSites, ?array $existingJobs = null): string
    {
        $group = $this->siteAssetsBySharedAltText($asset)[$this->sharedAltTextKey($asset)];
        $existingAltText = $includeExisting ? null : ArrayHelper::firstValue(array_filter(array_column($group, 'alt')));

        if ($existingAltText !== null) {
            foreach ($group as $siteAsset) {
                if (empty($siteAsset->alt)) {
                    $siteAsset->alt = $existingAltText;

                    if (!Craft::$app->elements->saveElement($siteAsset, true, false)) {
                        throw new Exception("Failed to copy alt text for asset: {$siteAsset->filename} (site {$siteAsset->siteId})");
                    }
                }
            }

            return 'copied';
        }

        if ($allSites && $group[0]->siteId !== $asset->siteId) {
            return 'skipped';
        }

        // The caller has already chosen the sites, so skip the saveTranslatedResultsToEachSite fan-out
        return $this->createJob($asset, false, $asset->siteId, false, true, $existingJobs) ? 'queued' : 'skipped';
    }

    /**
     * Groups the asset's sites by the alt text they share (see siteAssetsBySharedAltText()). The
     * current site's group comes first, led by the current site.
     *
     * @return int[][] Site IDs, grouped
     */
    private function groupSitesSharingAltText(Asset $asset, int $currentSiteId): array
    {
        $currentGroup = [$currentSiteId];
        $otherGroups = [];

        foreach ($this->siteAssetsBySharedAltText($asset) as $siteAssets) {
            $siteIds = array_column($siteAssets, 'siteId');

            if (in_array($currentSiteId, $siteIds, true)) {
                $currentGroup = [$currentSiteId, ...array_diff($siteIds, [$currentSiteId])];
            } else {
                $otherGroups[] = $siteIds;
            }
        }

        return [$currentGroup, ...$otherGroups];
    }

    /**
     * The asset in each of its sites, grouped by the alt text they share: the same key under the volume's
     * Alternative Text Translation Method, and the same language, so a result is never saved to a site in
     * another language (e.g. when the volume's alt text isn't translatable). Groups, and the sites in them,
     * are in site order, keyed by sharedAltTextKey().
     *
     * @return Asset[][]
     */
    private function siteAssetsBySharedAltText(Asset $asset): array
    {
        $siteAssets = ArrayHelper::index(Asset::find()->id($asset->id)->site('*')->all(), 'siteId');
        $groups = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (isset($siteAssets[$site->id])) {
                $groups[$this->sharedAltTextKey($siteAssets[$site->id])][] = $siteAssets[$site->id];
            }
        }

        return $groups;
    }

    /**
     * The key shared by the sites that share an asset's alt text, for the asset on one of them.
     */
    private function sharedAltTextKey(Asset $siteAsset): string
    {
        return $siteAsset->getAltTranslationKey() . '|' . $siteAsset->getSite()->language;
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

            // Not propagated, so sites in other languages never get it. The sites sharing it are saved below.
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
