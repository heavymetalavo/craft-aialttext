<?php

namespace heavymetalavo\craftaialttext\services;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Enums\FileKind;
use CraftCms\Cms\Element\Enums\MenuItemType;
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
            $message = t('{filename} (ID: {id}) is not an image', ['filename' => $asset->filename, 'id' => $asset->id], 'ai-alt-text');
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
            description: t('Generating alt text for {filename} (ID: {id}{siteMessageSuffix})', [
                'filename' => $asset->filename,
                'id' => $asset->id,
                'siteMessageSuffix' => $hasPlusOneSite ? ", Site: $assetSiteId" : "",
            ], 'ai-alt-text'),
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
                description: t('Generating alt text for {filename} (ID: {id}{siteMessageSuffix})', [
                    'filename' => $asset->filename,
                    'id' => $asset->id,
                    'siteMessageSuffix' => $hasPlusOneSite ? ", Site: {$site->id}" : "",
                ], 'ai-alt-text'),
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
     * Determines if an asset is an SVG file.
     */
    public function isSvg(Asset $asset): bool
    {
        return $asset->getMimeType() === 'image/svg+xml';
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
