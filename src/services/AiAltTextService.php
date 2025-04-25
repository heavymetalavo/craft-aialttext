<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\Template;
use craft\web\View;
use heavymetalavo\craftaialttext\AiAltText;
use craft\helpers\App;
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
        if (!$asset) {
            throw new Exception('Asset cannot be null');
        }

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
        $runValidation = true;
        if (!Craft::$app->elements->saveElement($asset, $runValidation)) {
            throw new Exception('Failed to save alt text for asset: ' . $asset->filename);
        }

        Craft::info('Successfully saved alt text for asset: ' . $asset->filename, __METHOD__);
        return $altText;

    }
    }

    /**
     * Validates an asset for alt text generation.
     *
     * This method checks that:
     * - The asset is not null
     * - The asset is an image
     * - The asset has either a public URL or is accessible via file system
     *
     * @param Asset $asset The asset to validate
     * @return bool True if the asset is valid, false otherwise
     * @throws Exception If the asset is invalid
     */
    private function validateAsset(Asset $asset): bool
    {
        if (!$asset) {
            throw new Exception('Asset cannot be null');
        }

        if ($asset->kind !== Asset::KIND_IMAGE) {
            throw new Exception('Asset must be an image');
        }

        // Check for either public URL or file system access
        if (!$asset->getUrl() && !$asset->getPath()) {
            throw new Exception('Asset must have either a public URL or be accessible via file system');
        }

        return true;
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

    /**
     * Generates a filename for an asset using AI.
     * 
     * This method:
     * - Validates the asset
     * - Generates a filename using the OpenAI service
     * - Returns the generated filename
     * 
     * @param Asset $asset The asset to generate a filename for
     * @return string The generated filename (without extension)
     * @throws Exception If the asset is invalid or filename generation fails
     */
    public function generateFilename(Asset $asset): string
    {
        try {
            if (!$asset) {
                throw new Exception('Asset cannot be null');
            }

            if ($asset->kind !== Asset::KIND_IMAGE) {
                throw new Exception('Asset must be an image');
            }

            // Get the filename prompt from settings
            $prompt = AiAltText::getInstance()->getSettings()->filenamePrompt;
            
            // Generate the filename using OpenAI
            $filename = $this->openAiService->generateFilename($asset, $prompt);
            
            if (empty($filename)) {
                throw new Exception('Empty filename generated for asset: ' . $asset->filename);
            }

            // Clean the filename to ensure it's valid
            $filename = $this->cleanFilename($filename);

            Craft::info('Successfully generated filename for asset: ' . $asset->filename, __METHOD__);
            return $filename;

        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Cleans a filename to ensure it's valid and follows the desired format.
     * 
     * @param string $filename The filename to clean
     * @return string The cleaned filename
     */
    private function cleanFilename(string $filename): string
    {
        // Remove any file extension if present
        $filename = pathinfo($filename, PATHINFO_FILENAME);
        
        // Convert to lowercase
        $filename = strtolower($filename);
        
        // Replace spaces and underscores with hyphens
        $filename = preg_replace('/[\s_]+/', '-', $filename);
        
        // Remove any characters that aren't alphanumeric, hyphens, or dots
        $filename = preg_replace('/[^a-z0-9-]/', '', $filename);
        
        // Remove multiple consecutive hyphens
        $filename = preg_replace('/-+/', '-', $filename);
        
        // Trim hyphens from the beginning and end
        $filename = trim($filename, '-');
        
        return $filename;
    }
}
