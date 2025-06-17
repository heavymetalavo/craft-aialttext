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
     * 
     * @param Asset $asset The asset to create a job for
     * @param bool $saveCurrentSiteOffQueue Whether to process the current site off queue
     * @param int|null $currentSiteId The current site ID
     * @param bool $skipExistingJobCheck Whether to skip the check for existing jobs (useful for bulk operations)
     * @param bool $forceRegeneration Whether to force regeneration even if alt text exists
     */
    public function createJob(Asset $asset, $saveCurrentSiteOffQueue = false, $currentSiteId = null, $skipExistingJobCheck = false, $forceRegeneration = false, $skipSaveTranslatedResultsToEachSiteSetting = false): void
    {
        $queue = Craft::$app->getQueue();

        $assetSiteId = $currentSiteId ?? $asset->siteId;

        // Check if there's already a job for this element
        if (!$skipExistingJobCheck) {
            $existingJobs = $queue->getJobInfo();
            $hasExistingJob = false;
            foreach ($existingJobs as $job) {
                // Only skip if both asset ID AND site ID match an existing job
                if (isset($job['description'])
                    && str_contains($job['description'], "ID: $asset->id")
                    && str_contains($job['description'], "Site: $assetSiteId")
                    && $job['status'] !== 4) {
                    $hasExistingJob = true;
                    break;
                }
            }

            $hasPlusOneSite = count(Craft::$app->getSites()->getAllSites()) > 1;

            if ($hasExistingJob) {
                $message = Craft::t('ai-alt-text', "$asset->filename (ID: $asset->id" . ($hasPlusOneSite ? ", Site: $assetSiteId" : "") . ") is already being processed within an existing queued job. Please wait for the existing job to finish before attempting to process it again.");
                
                // Only use session in web context
                if (Craft::$app->getRequest()->getIsConsoleRequest()) {
                    Craft::info($message, __METHOD__);
                } else {
                    Craft::$app->getSession()->setNotice($message);
                }
                return;
            }
        }

        if ($asset->kind !== Asset::KIND_IMAGE) {
            $message = Craft::t('ai-alt-text', "$asset->filename (ID: $asset->id) is not an image");
            
            // Only use session in web context
            if (Craft::$app->getRequest()->getIsConsoleRequest()) {
                Craft::info($message, __METHOD__);
            } else {
                Craft::$app->getSession()->setNotice($message);
            }
            return;
        }

        // Get the $saveTranslatedResultsToEachSite setting value
        $saveTranslatedResultsToEachSite = $skipSaveTranslatedResultsToEachSiteSetting ? false : AiAltText::getInstance()->settings->saveTranslatedResultsToEachSite;

        // Check if we need to save the current site off queue
        if ($saveCurrentSiteOffQueue) {
            $this->generateAltText($asset, $assetSiteId, $forceRegeneration);
    
            if (!$saveTranslatedResultsToEachSite) {
                return;
            }
        }

        $sites = Craft::$app->getSites()->getAllSites();
        $hasPlusOneSite = count($sites) > 1;

        // Save the current site on queue
        $queue->push(new GenerateAiAltTextJob([
            'description' => Craft::t('ai-alt-text', 'Generating alt text for {filename} (ID: {id}{siteMessageSuffix})', [
                'filename' => $asset->filename,
                'id' => $asset->id,
                'siteMessageSuffix' => $hasPlusOneSite ? ", Site: $assetSiteId" : "",
            ]),
            'assetId' => $asset->id,
            'siteId' => $assetSiteId,
            'forceRegeneration' => $forceRegeneration,
        ]));

        // return early if we're not saving translated results to each site
        if (!$saveTranslatedResultsToEachSite) {
            return;
        }

        // If we're saving results to each site and translated results for each site, we need to queue a job for each site
        foreach ($sites as $site) {
            // Skip the current site
            if ($saveCurrentSiteOffQueue && $site->id === $assetSiteId) {
                continue;
            }

            $queue->push(new GenerateAiAltTextJob([
                'description' => Craft::t('ai-alt-text', 'Generating alt text for {filename} (ID: {id}{siteMessageSuffix})', [
                    'filename' => $asset->filename,
                    'id' => $asset->id,
                    'siteMessageSuffix' => $hasPlusOneSite ? ", Site: $site->id" : "",
                ]),
                'assetId' => $asset->id,
                'siteId' => $site->id,
                'forceRegeneration' => $forceRegeneration,
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
     * @param int|null $siteId The site ID
     * @param bool $forceRegeneration Whether to force regeneration even if alt text exists
     * @return string The generated alt text
     * @throws Exception If the asset is invalid or alt text generation fails
     */
    public function generateAltText(Asset $asset, int $siteId = null, bool $forceRegeneration = false): string
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            throw new Exception('Asset must be an image');
        }

        $altText = $this->openAiService->generateAltText($asset, $siteId);

        if (empty($altText)) {
            throw new Exception('Empty alt text generated for asset: ' . $asset->filename);
        }

        $asset->alt = $altText;
        $propagate = AiAltText::getInstance()->getSettings()->propagate;
        if (!Craft::$app->elements->saveElement($asset, true, $propagate)) {
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
