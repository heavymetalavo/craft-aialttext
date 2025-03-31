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
use heavymetalavo\craftaialttext\models\api\OpenAiRequest;
use heavymetalavo\craftaialttext\models\api\OpenAiResponse;

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
        try {
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

            // Try to get the URL first, if not available use base64
            $imageUrl = $asset->getUrl();
            if (!$imageUrl) {
                // Get the file path and convert to base64
                $path = $asset->getPath();
                if (!$path) {
                    throw new Exception('Asset must have either a public URL or be accessible via file system');
                }
                
                $imageData = file_get_contents($path);
                if ($imageData === false) {
                    throw new Exception('Failed to read image file');
                }
                
                $imageUrl = 'data:' . $asset->mimeType . ';base64,' . base64_encode($imageData);
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

        } catch (Exception $e) {
            throw $e;
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
}
