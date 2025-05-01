<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\jobs\GenerateAiAltText as GenerateAiAltTextJob;
use Exception;
use craft\events\DefineMenuItemsEvent;
use craft\enums\MenuItemType;

/**
 * AI Alt Text Service
 *
 * Main service class for generating alt text using AI.
 * This service coordinates between the OpenAI service and Craft CMS assets.
 *
 * @property OpenAiService $openAiService The OpenAI service instance
 */
class AiAltTextService extends Component
{
    private OpenAiService $openAiService;

    /**
     * Constructor
     *
     * Initializes the service with the OpenAI service instance.
     */
    public function __construct()
    {
        parent::__construct();
        $this->openAiService = new OpenAiService();
    }

    /**
     * Creates a job for the given element
     */
    public function createJob(Asset $asset, $saveCurrentSiteOffQueue = false): void
    {
        $queue = Craft::$app->getQueue();
        // Check if there's already a job for this element
        $existingJobs = $queue->getJobInfo();
        $hasExistingJob = false;
        foreach ($existingJobs as $job) {
            if (isset($job['description']) && str_contains($job['description'], "Asset: $asset->id")) {
                $hasExistingJob = true;
                break;
            }
        }

        if ($hasExistingJob) {
            Craft::$app->getSession()->setNotice(Craft::t('ai-alt-text', "$asset->filename (ID: $asset->id) is already being processed within an existing queued job. Please wait for the existing job to finish before attempting to process it again."));
            return;
        }

        if ($asset->kind !== Asset::KIND_IMAGE) {
            Craft::$app->getSession()->setNotice(Craft::t('ai-alt-text', "$asset->filename (ID: $asset->id) is not an image"));
            return;
        }

        $saveTranslatedResultsToEachSite = AiAltText::getInstance()->settings->saveTranslatedResultsToEachSite;

        // Check if we need to save the current site off queue
        if ($saveCurrentSiteOffQueue) {
            try {
                // Generate alt text - now returns a string and saves the asset if successful
                $altText = AiAltText::getInstance()->aiAltTextService->generateAltText($asset, $this->siteId);
    
                // Log the result
                if (!empty($altText)) {
                    Craft::info("Successfully generated alt text for asset $this->assetId: " . $altText, __METHOD__);
                } else {
                    Craft::warning("Failed to generate alt text for asset $this->assetId", __METHOD__);
                    // Set the description to indicate failure
                    $this->description = "Failed to generate alt text";
                }
            } catch (Exception $e) {
                Craft::error("Error in GenerateAiAltText job: " . $e->getMessage(), __METHOD__);
                // Set the description to indicate error
                $this->description = "Error: " . $e->getMessage();
            }
            
            if (!$saveTranslatedResultsToEachSite) {
                return;
            }
        }

        // If we're saving results to each site and translated results for each site, we need to queue a job for each site
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            // Skip the current site
            if ($saveCurrentSiteOffQueue && $site->id === $asset->siteId) {
                continue;
            }

            $queue->push(new GenerateAiAltTextJob([
                'description' => Craft::t('ai-alt-text', 'Generating alt text for {filename} (Asset: {id}, Site: {siteId})', [
                    'filename' => $asset->filename,
                    'id' => $asset->id,
                    'siteId' => $site->id,
                ]),
                'assetId' => $asset->id,
                'siteId' => $site->id,
            ]));
        }
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
     * @return string The generated alt text
     * @throws Exception If the asset is invalid or alt text generation fails
     */
    public function generateAltText(Asset $asset, int $siteId = null): string
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            throw new Exception('Asset must be an image');
        }

        if (AiAltText::getInstance()->getSettings()->preSaveAsset && empty($asset->alt)) {
            $asset->alt = '';
            if (!Craft::$app->elements->saveElement($asset)) {
                throw new Exception('Failed to pre-save asset: ' . $asset->filename);
            }
        }

        $altText = $this->openAiService->generateAltText($asset, $siteId);

        if (empty($altText)) {
            throw new Exception('Empty alt text generated for asset: ' . $asset->filename);
        }

        $asset->alt = $altText;
        if (!Craft::$app->elements->saveElement($asset, true)) {
            throw new Exception('Failed to save alt text for asset: ' . $asset->filename);
        }

        Craft::info('Successfully saved alt text for asset: ' . $asset->filename, __METHOD__);
        return $altText;

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
  Craft.cp.displayNotice(Craft.t('ai-alt-text', 'Queueing AI alt text generation...'));
  
  // Make an AJAX request to your controller action
  Craft.sendActionRequest('POST', 'ai-alt-text/generate/single-asset', {
    data: {
      assetId: $assetId,
      siteId: $siteId,
    }
  })
  .then((response) => {
    if (response.data.success) {
        Craft.cp.displayNotice(Craft.t('ai-alt-text', response.data.message));
      
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
    Craft.cp.displayError(Craft.t('ai-alt-text', 'Failed to queue alt text generation: ') + 
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
