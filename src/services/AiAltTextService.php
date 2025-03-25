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
    public function generateAltText(Asset $asset): string
    {
        try {
            if (!$asset) {
                throw new Exception('Asset cannot be null');
            }

            if ($asset->kind !== Asset::KIND_IMAGE) {
                throw new Exception('Asset must be an image');
            }

            if (!$asset->getUrl()) {
                throw new Exception('Asset must have a URL');
            }

            $altText = $this->openAiService->generateAltText($asset);
            
            if (empty($altText)) {
                throw new Exception('Empty alt text generated for asset: ' . $asset->filename);
            }

            $asset->alt = $altText;
            if (!Craft::$app->elements->saveElement($asset)) {
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
     * - The asset has a URL
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

        if (!$asset->getUrl()) {
            throw new Exception('Asset must have a URL');
        }

        return true;
    }
}
