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
     */
    public function generateAltText(Asset $asset): string
    {
        try {
            if (!$this->validateAsset($asset)) {
                Craft::warning('Invalid asset for alt text generation: ' . ($asset ? $asset->id : 'null'), __METHOD__);
                return 'Image: ' . ($asset ? $asset->filename : 'unknown');
            }

            $altText = $this->openAiService->generateAltText($asset);
            
            // If we got a valid alt text, set it on the asset and save
            if (!empty($altText)) {
                $asset->alt = $altText;
                Craft::$app->elements->saveElement($asset);
            }

            return $altText;
        } catch (Exception $e) {
            Craft::error('Error generating alt text: ' . $e->getMessage(), __METHOD__);
            return 'Image: ' . ($asset ? $asset->filename : 'unknown');
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
     */
    private function validateAsset(Asset $asset): bool
    {
        if (!$asset) {
            return false;
        }

        if (!$asset->kind === Asset::KIND_IMAGE) {
            return false;
        }

        if (!$asset->getUrl()) {
            return false;
        }

        return true;
    }
}
