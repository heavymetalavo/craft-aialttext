<?php

namespace heavymetalavo\craftaialttext\controllers;

use Craft;
use craft\base\Controller;
use craft\elements\Asset;
use craft\web\Response;
use Exception;
use heavymetalavo\craftaialttext\AiAltTextGenerator;

/**
 * Generate AI Alt Text Controller Action
 * 
 * Handles the generation of alt text for assets using AI.
 * This controller provides endpoints for generating alt text for single or multiple assets.
 */
class GenerateAiAltText extends Controller
{
    /**
     * Generates alt text for a single asset.
     * 
     * This action:
     * - Validates the asset ID
     * - Retrieves the asset
     * - Generates alt text using the AI service
     * - Returns the result as JSON
     * 
     * @return Response The JSON response
     */
    public function actionGenerate(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $assetId = Craft::$app->getRequest()->getRequiredParam('assetId');
        $asset = Asset::findOne(['id' => $assetId]);

        if (!$asset) {
            return $this->asJson([
                'success' => false,
                'error' => 'Asset not found',
            ]);
        }

        try {
            $success = AiAltTextGenerator::getInstance()->aiAltTextGeneratorService->generateAltText($asset);
            return $this->asJson([
                'success' => $success,
                'alt' => $asset->alt,
            ]);
        } catch (Exception $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generates alt text for multiple assets.
     * 
     * This action:
     * - Validates the asset IDs
     * - Retrieves the assets
     * - Generates alt text for each asset using the AI service
     * - Returns the results as JSON
     * 
     * @return Response The JSON response
     */
    public function actionGenerateMultiple(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $assetIds = Craft::$app->getRequest()->getRequiredParam('assetIds');
        $assets = Asset::findAll(['id' => $assetIds]);

        if (empty($assets)) {
            return $this->asJson([
                'success' => false,
                'error' => 'No assets found',
            ]);
        }

        $results = [];
        foreach ($assets as $asset) {
            try {
                $success = AiAltTextGenerator::getInstance()->aiAltTextGeneratorService->generateAltText($asset);
                $results[] = [
                    'id' => $asset->id,
                    'success' => $success,
                    'alt' => $asset->alt,
                ];
            } catch (Exception $e) {
                $results[] = [
                    'id' => $asset->id,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $this->asJson([
            'success' => true,
            'results' => $results,
        ]);
    }
} 