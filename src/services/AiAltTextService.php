<?php

namespace heavymetalavo\craftaialttext\services;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Enums\FileKind;
use CraftCms\Cms\Element\Enums\MenuItemType;
use CraftCms\Cms\Field\Enums\TranslationMethod;
use CraftCms\Cms\Element\Events\ElementActionMenuItemsResolving;
use CraftCms\Cms\Queue\Enums\JobStatus;
use CraftCms\Cms\Queue\JobProgress;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Facades\{Elements, HtmlStack, Images, InputNamespace, Sites};
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\jobs\GenerateAiAltText as GenerateAiAltTextJob;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function CraftCms\Cms\t;

/**
 * AI Alt Text Service
 *
 * Main service class for generating alt text using AI.
 * This service coordinates between the provider services and Craft CMS assets.
 */
#[Singleton]
class AiAltTextService
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
     * @param string[]|null $existingJobs Pending job descriptions to check against (see pendingJobDescriptions()), so bulk callers can fetch them once instead of per asset
     * @return bool Whether anything was generated or queued, false if the asset was skipped
     * @throws Exception
     */
    public function createJob(Asset $asset, $saveCurrentSiteOffQueue = false, $currentSiteId = null, $skipExistingJobCheck = false, $skipSaveTranslatedResultsToEachSiteSetting = false, ?array $existingJobs = null): bool
    {
        $assetSiteId = (int)($currentSiteId ?? $asset->siteId);
        $hasPlusOneSite = Sites::getAllSites()->count() > 1;

        // Check if there's already a job for this element
        if (!$skipExistingJobCheck) {
            $existingJobs ??= $this->pendingJobDescriptions();

            if ($this->hasPendingJob($existingJobs, $this->jobMarker($asset->id, $assetSiteId, $hasPlusOneSite))) {
                // Logged rather than flashed: callers report the skip themselves, and this also runs in queue jobs
                Log::info(AiAltText::t('{filename} (ID: {id}.{siteMessageSuffix}) is already being processed within an existing queued job. Please wait for the existing job to finish before attempting to process it again.', [
                    'filename' => $asset->filename,
                    'id' => $asset->id,
                    'siteMessageSuffix' => $hasPlusOneSite ? " Site: $assetSiteId." : "",
                ]));
                return false;
            }
        }

        if ($asset->kind !== FileKind::Image->value) {
            Log::info(AiAltText::t('{filename} (ID: {id}) is not an image', ['filename' => $asset->filename, 'id' => $asset->id]));
            return false;
        }

        // Skip SVG assets if SVG processing is off, or Craft's image driver can't read them
        if ($this->isSvg($asset) && !$this->canProcessSvgs()) {
            Log::debug("Skipping alt text generation for SVG asset {$asset->id} because SVG processing is off or Craft's image driver can't read SVGs.");
            return false;
        }

        $saveTranslatedResultsToEachSite = $skipSaveTranslatedResultsToEachSiteSetting
            ? false
            : AiAltText::settings()->saveTranslatedResultsToEachSite;

        // Sites that share alt text are generated once. The first set is the current site's, led by it.
        $siteSets = $this->sitesSharingAltText($asset, $assetSiteId);
        $sharedSiteIds = array_slice(array_shift($siteSets), 1);

        if ($saveCurrentSiteOffQueue) {
            $this->generateAltText($asset, $assetSiteId, $sharedSiteIds);

            if (!$saveTranslatedResultsToEachSite) {
                return true;
            }
        }

        // Queue the current site's job unless generateAltText() already handled it off queue
        // above, in which case queueing it here would generate (and pay for) it a second time.
        if (!$saveCurrentSiteOffQueue) {
            dispatch(new GenerateAiAltTextJob(
                assetId: $asset->id,
                siteId: $assetSiteId,
                sharedSiteIds: $sharedSiteIds,
                description: $this->jobDescription($asset, $assetSiteId, $hasPlusOneSite),
            ));
        }

        if (!$saveTranslatedResultsToEachSite) {
            return true;
        }

        // Queue a job for each set of *other* sites that share alt text, generated for the set's first site
        // and saved to the rest. The current site's set is always already handled above — either inline via
        // generateAltText() or by the dispatch above — so it must never be queued again here.
        foreach ($siteSets as $siteIds) {
            $siteId = array_shift($siteIds);

            // Skip sets that already have a pending job for this asset
            if ($existingJobs !== null && $this->hasPendingJob($existingJobs, $this->jobMarker($asset->id, $siteId, $hasPlusOneSite))) {
                continue;
            }

            dispatch(new GenerateAiAltTextJob(
                assetId: $asset->id,
                siteId: $siteId,
                sharedSiteIds: $siteIds,
                description: $this->jobDescription($asset, $siteId, $hasPlusOneSite),
            ));
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
     * @param string[]|null $existingJobs Pending job descriptions for the duplicate-job check
     * @return string `queued`, `copied`, or `skipped` when an earlier site sharing its alt text handles
     * it, a job is already queued, or the asset isn't eligible
     * @throws Exception
     */
    public function createBulkJob(Asset $asset, bool $includeExisting, bool $allSites, ?array $existingJobs = null): string
    {
        $siteIds = $this->siteIdsBySharedAltText($asset)[$this->sharedAltTextKey($asset)];

        if (!$includeExisting) {
            $siteAssets = collect(Asset::find()->id($asset->id)->siteId($siteIds)->all())->keyBy('siteId');
            // In site order, so the alt text copied is the first site's that has some
            $existingAltText = collect($siteIds)->map(fn(int $siteId) => $siteAssets[$siteId]->alt ?? null)->filter()->first();

            if ($existingAltText !== null) {
                foreach ($siteAssets as $siteAsset) {
                    if (empty($siteAsset->alt)) {
                        $siteAsset->alt = $existingAltText;

                        if (!Elements::saveElement($siteAsset, true, false)) {
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
        if ($asset->getVolume()->altTranslationMethod === TranslationMethod::None) {
            return [$this->sharedAltTextKey($asset) => Sites::getAllSites()->map(fn($site) => $site->id)->values()->all()];
        }

        $siteAssets = collect(Asset::find()->id($asset->id)->site('*')->all())->keyBy('siteId')->all();
        $siteIds = [];

        foreach (Sites::getAllSites() as $site) {
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
     * The descriptions of the jobs in the queue that haven't finished, failed or been cancelled, for the
     * duplicate-job check in createJob().
     *
     * @return string[]
     */
    public function pendingJobDescriptions(): array
    {
        return app(JobProgress::class)->getJobInfo()
            ->reject(fn($job) => in_array($job->status, [JobStatus::Failed, JobStatus::Cancelled], true))
            ->pluck('description')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Whether any of the pending job descriptions ends with the given marker. The untranslated marker is
     * always the end of the description (see jobDescription()), so a translation or a filename can't
     * affect the match, and "ID: 1" can't match "ID: 12".
     *
     * @param string[] $descriptions
     */
    private function hasPendingJob(array $descriptions, string $marker): bool
    {
        foreach ($descriptions as $description) {
            if (str_ends_with($description, $marker)) {
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
        return AiAltText::t('Generating alt text for {filename}', [
            'filename' => $asset->filename,
        ]) . $this->jobMarker($asset->id, $siteId, $multiSite);
    }

    /**
     * Whether createJob() queues jobs for sites other than the one it was called for, so callers
     * that generate the current site inline can say the rest is still pending.
     */
    public function queuesOtherSites(): bool
    {
        return Sites::isMultiSite()
            && AiAltText::settings()->saveTranslatedResultsToEachSite;
    }

    /**
     * Returns a message describing what's missing from the provider configuration, or null if
     * generation can proceed. A missing or unrecognised provider must never fall through to OpenAI.
     */
    public function getConfigurationError(): ?string
    {
        $settings = AiAltText::settings();
        $provider = Env::parse($settings->aiProvider);

        if ($provider === '' || $provider === null) {
            return AiAltText::t('No AI provider is configured. Choose one in the AI Alt Text plugin settings.');
        }

        if (!in_array($provider, ['openai', 'anthropic', 'langdock', 'openrouter'], true)) {
            return AiAltText::t('"{provider}" is not a supported AI provider. Choose OpenAI, Anthropic, Langdock or OpenRouter in the AI Alt Text plugin settings.', ['provider' => $provider]);
        }

        [$label, $apiKey] = match ($provider) {
            'anthropic' => ['Anthropic', $settings->anthropicApiKey],
            'langdock' => ['Langdock', $settings->langdockApiKey],
            'openai' => ['OpenAI', $settings->openAiApiKey],
            'openrouter' => ['OpenRouter', $settings->openRouterApiKey],
        };

        $apiKey = Env::parse($apiKey);

        if ($apiKey === '' || $apiKey === null) {
            return AiAltText::t('No API key is configured for the {provider} provider. Add one in the AI Alt Text plugin settings.', ['provider' => $label]);
        }

        if ($provider === 'langdock' && !in_array(Env::parse($settings->langdockModelAuthor), ['anthropic', 'openai'], true)) {
            return AiAltText::t('The Langdock Model Author must be Anthropic or OpenAI. Choose one in the AI Alt Text plugin settings.');
        }

        return null;
    }

    /**
     * Returns the service for the configured provider. Only call once getConfigurationError() is null.
     * An unrecognised provider throws an UnhandledMatchError rather than falling back to OpenAI.
     */
    private function getProviderService(): ApiService
    {
        $settings = AiAltText::settings();

        return match (Env::parse($settings->aiProvider)) {
            'anthropic' => app(AnthropicService::class),
            'langdock' => Env::parse($settings->langdockModelAuthor) === 'openai'
                ? app(LangdockOpenAiService::class)
                : app(LangdockAnthropicService::class),
            'openai' => app(OpenAiService::class),
            'openrouter' => app(OpenRouterService::class),
        };
    }

    /**
     * Generates alt text for an asset using AI, then saves it to the asset and the sites that share it.
     *
     * @param Asset $asset The asset to generate alt text for
     * @param int|null $siteId The site ID
     * @param int[] $sharedSiteIds Other sites that share the site's alt text, saved with the same result
     * @return string The generated alt text
     * @throws Exception If the asset is invalid or alt text generation fails
     */
    public function generateAltText(Asset $asset, ?int $siteId = null, array $sharedSiteIds = []): string
    {
        if ($asset->kind !== FileKind::Image->value) {
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
        // fails - a validation error, a veto from another plugin, a DB problem - they all keep their
        // previous alt text, rather than some sites having the new value and some not.
        DB::transaction(function() use ($asset, $altText, $sharedSiteIds) {
            $asset->alt = $altText;

            Log::info("Saving AI alt text for asset {$asset->id}");

            // Not propagated, so only the sites that share its alt text get it, saved below.
            if (!Elements::saveElement($asset, true, false)) {
                throw new Exception('Failed to save alt text for asset: ' . $asset->filename);
            }

            foreach ($sharedSiteIds as $sharedSiteId) {
                $sharedAsset = Asset::find()->id($asset->id)->siteId($sharedSiteId)->one();

                // Skip sites the asset has left since the job was queued
                if (!$sharedAsset) {
                    continue;
                }

                $sharedAsset->alt = $altText;

                if (!Elements::saveElement($sharedAsset, true, false)) {
                    throw new Exception("Failed to save alt text for asset: {$asset->filename} (site $sharedSiteId)");
                }
            }
        });

        Log::info('Successfully saved alt text for asset: ' . $asset->filename);
        return $altText;
    }

    /**
     * Whether SVGs get alt text: the Process SVGs setting is on and Craft uses the Imagick image driver, which
     * getSvgBase64() renders them with. GD can't read SVGs.
     */
    public function canProcessSvgs(): bool
    {
        return AiAltText::settings()->processSvgs && Images::getIsImagick();
    }

    /**
     * Determines if an asset is an SVG file.
     */
    public function isSvg(Asset $asset): bool
    {
        return $asset->getMimeType() === 'image/svg+xml';
    }

    /**
     * Determines if an asset is an AVIF file.
     */
    public function isAvif(Asset $asset): bool
    {
        return $asset->getMimeType() === 'image/avif';
    }

    /**
     * Determines if an asset is a HEIC/HEIF file.
     */
    public function isHeic(Asset $asset): bool
    {
        return in_array($asset->getMimeType(), ['image/heic', 'image/heif'], true);
    }

    /**
     * Adds a "Generate AI Alt Text" button to the per-asset action dropdown menu.
     */
    public function handleAssetActionMenuItems(ElementActionMenuItemsResolving $event): void
    {
        $asset = $event->element;

        if (!$asset instanceof Asset || $asset->kind !== 'image') {
            return;
        }

        // Derived from the asset and site rather than mt_rand(): a random ID has no collision
            // guarantee, and two assets on one page sharing an ID would wire a button to the
            // wrong asset.
            $customActionId = sprintf('action-generate-ai-alt-%s-%s', $asset->id, $asset->siteId);

        $event->items[] = [
            'type' => MenuItemType::Button,
            'id' => $customActionId,
            'icon' => 'language',
            'label' => t('Generate AI Alt Text', category: 'ai-alt-text'),
        ];

        HtmlStack::jsWithVars(fn ($id, $assetId, $siteId) => <<<JS
$('#' + $id).on('activate', () => {
  Craft.cp.displayNotice(Craft.t('ai-alt-text', 'Generating AI alt text…'));

  Craft.sendActionRequest('POST', 'ai-alt-text/generate/single-asset', {
    data: {
      assetId: $assetId,
      siteId: $siteId,
    }
  })
  .then((response) => {
    if (response.data.success) {
      Craft.cp.displayNotice(response.data.message);

      if (Craft.cp.elementIndex) {
        Craft.cp.elementIndex.updateElements();
        return;
      }

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
            InputNamespace::namespaceId($customActionId),
            $asset->id,
            $asset->siteId,
        ]);
    }
}
