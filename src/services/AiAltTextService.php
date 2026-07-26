<?php

namespace heavymetalavo\craftaialttext\services;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Enums\FileKind;
use CraftCms\Cms\Element\Enums\MenuItemType;
use CraftCms\Cms\Field\Enums\TranslationMethod;
use CraftCms\Cms\Element\Events\ElementActionMenuItemsResolving;
use CraftCms\Cms\Support\Facades\{Elements, HtmlStack, InputNamespace, Sites};
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\jobs\GenerateAiAltText as GenerateAiAltTextJob;
use Illuminate\Container\Attributes\Singleton;
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
     * Creates a queued job for the given asset.
     *
     * @param Asset $asset The asset to create a job for
     * @param bool $saveCurrentSiteOffQueue Whether to process the current site synchronously before queuing
     * @param int|null $currentSiteId The site ID to target
     * @param bool $skipExistingJobCheck Unused — duplicate prevention is handled by WithoutOverlapping in the job
     * @param bool $forceRegeneration Whether to force regeneration even if alt text exists
     * @param bool $skipSaveTranslatedResultsToEachSiteSetting Skip the multi-site translation setting
     * @throws Exception
     */
    public function createJob(Asset $asset, $saveCurrentSiteOffQueue = false, $currentSiteId = null, $skipExistingJobCheck = false, $forceRegeneration = false, $skipSaveTranslatedResultsToEachSiteSetting = false): void
    {
        $assetSiteId = $currentSiteId ?? $asset->siteId;

        if ($asset->kind !== FileKind::Image->value) {
            $message = AiAltText::t('{filename} (ID: {id}) is not an image', ['filename' => $asset->filename, 'id' => $asset->id]);
            if (app()->runningInConsole()) {
                Log::info($message);
            } else {
                session()->flash('cp-notification-notice', [$message, ['icon' => 'info', 'iconLabel' => t('Notice')]]);
            }
            return;
        }

        // Skip SVG assets if SVG processing is disabled
        if ($this->isSvg($asset) && !AiAltText::settings()->processSvgs) {
            Log::debug("Skipping alt text generation for SVG asset {$asset->id} because SVG processing is disabled.");
            return;
        }

        $saveTranslatedResultsToEachSite = $skipSaveTranslatedResultsToEachSiteSetting
            ? false
            : AiAltText::settings()->saveTranslatedResultsToEachSite;

        // Generating per site is only meaningful where the volume's alt text can actually differ
        // per site. With the volume's Alternative Text Translation Method set to None every site
        // shares one value, so looping them would spend an API call each and leave whichever job
        // finished last as the winner. Generate once instead.
        //
        // The site isn't redirected to the primary one here. $siteId only selects the language the
        // prompt asks for; the value is written to whichever site row the passed-in asset was
        // loaded for. Forcing the primary language would produce, say, English text stored against
        // the French site's row — harder to explain than simply generating in the language of the
        // site the action was triggered from.
        if ($saveTranslatedResultsToEachSite && !$this->altTextCanVaryPerSite($asset)) {
            Log::debug("Alt text is shared across sites for {$asset->filename} (the volume's Alternative Text Translation Method is None), so generating once rather than once per site.");
            $saveTranslatedResultsToEachSite = false;
        }

        if ($saveCurrentSiteOffQueue) {
            $this->generateAltText($asset, $assetSiteId, $forceRegeneration);

            if (!$saveTranslatedResultsToEachSite) {
                return;
            }
        }

        $sites = Sites::getAllSites();
        $hasPlusOneSite = $sites->count() > 1;

        dispatch(new GenerateAiAltTextJob(
            assetId: $asset->id,
            siteId: $assetSiteId,
            forceRegeneration: $forceRegeneration,
            description: AiAltText::t('Generating alt text for {filename} (ID: {id}{siteMessageSuffix})', [
                'filename' => $asset->filename,
                'id' => $asset->id,
                'siteMessageSuffix' => $hasPlusOneSite ? ", Site: $assetSiteId" : "",
            ]),
        ));

        if (!$saveTranslatedResultsToEachSite) {
            return;
        }

        foreach ($sites as $site) {
            if ($saveCurrentSiteOffQueue && $site->id === $assetSiteId) {
                continue;
            }

            dispatch(new GenerateAiAltTextJob(
                assetId: $asset->id,
                siteId: $site->id,
                forceRegeneration: $forceRegeneration,
                description: AiAltText::t('Generating alt text for {filename} (ID: {id}{siteMessageSuffix})', [
                    'filename' => $asset->filename,
                    'id' => $asset->id,
                    'siteMessageSuffix' => $hasPlusOneSite ? ", Site: {$site->id}" : "",
                ]),
            ));
        }
    }

    /**
     * Generates alt text for an asset using AI, then saves it to the asset.
     *
     * @param Asset $asset The asset to generate alt text for
     * @param int|null $siteId The site ID
     * @param bool $forceRegeneration Whether to force regeneration even if alt text exists
     * @return string The generated alt text
     * @throws Exception If the asset is invalid or alt text generation fails
     */
    public function generateAltText(Asset $asset, ?int $siteId = null, bool $forceRegeneration = false): string
    {
        if ($asset->kind !== FileKind::Image->value) {
            throw new Exception('Asset must be an image');
        }

        $provider = \CraftCms\Cms\Support\Env::parse(AiAltText::settings()->aiProvider);

        if ($provider === 'anthropic') {
            $altText = app(AnthropicService::class)->generateAltText($asset, $siteId);
        } else {
            $altText = app(OpenAiService::class)->generateAltText($asset, $siteId);
        }

        if (empty($altText)) {
            throw new Exception('Empty alt text generated for asset: ' . $asset->filename);
        }

        $propagate = (bool) AiAltText::settings()->propagate;

        // Bug workaround: pre-save blank alt text to prevent propagation across sites when setting is false
        if (!$propagate) {
            $asset->alt = '';
            Log::debug("Performing preliminary save for asset {$asset->id} to establish site rows before setting alt text.");
            Elements::saveElement($asset, true, false);
        }

        $asset->alt = $altText;

        Log::info("Saving AI alt text for asset {$asset->id} with propagate=" . ($propagate ? 'true' : 'false'));

        if (!Elements::saveElement($asset, true, $propagate)) {
            throw new Exception('Failed to save alt text for asset: ' . $asset->filename);
        }

        Log::info('Successfully saved alt text for asset: ' . $asset->filename);
        return $altText;
    }

    /**
     * Whether alt text can hold a different value per site for this asset's volume.
     *
     * Craft keeps this on the volume as its Alternative Text Translation Method. `None` means one
     * value shared across every site; every other method (site, site group, language, custom)
     * allows it to vary, so per-site generation is worth doing.
     */
    private function altTextCanVaryPerSite(Asset $asset): bool
    {
        try {
            return $asset->getVolume()->altTranslationMethod !== TranslationMethod::None;
        } catch (\Throwable $e) {
            // Can't resolve the volume: behave as before rather than silently skipping sites.
            Log::debug("Couldn't read the alt translation method for {$asset->filename}, assuming alt text can vary per site: " . $e->getMessage());

            return true;
        }
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

        $customActionId = sprintf('action-generate-ai-alt-%s', mt_rand());

        $event->items[] = [
            'type' => MenuItemType::Button,
            'id' => $customActionId,
            'icon' => 'language',
            'label' => t('Generate AI Alt Text', category: 'ai-alt-text'),
        ];

        HtmlStack::jsWithVars(fn ($id, $assetId, $siteId) => <<<JS
$('#' + $id).on('activate', () => {
  Craft.cp.displayNotice(Craft.t('ai-alt-text', 'Queueing AI alt text generation...'));

  Craft.sendActionRequest('POST', 'ai-alt-text/generate/single-asset', {
    data: {
      assetId: $assetId,
      siteId: $siteId,
    }
  })
  .then((response) => {
    if (response.data.success) {
      Craft.cp.displayNotice(Craft.t('ai-alt-text', response.data.message));

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
    Craft.cp.displayError(Craft.t('ai-alt-text', 'Failed to queue alt text generation: ') +
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
