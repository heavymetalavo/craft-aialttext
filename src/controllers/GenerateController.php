<?php

namespace heavymetalavo\craftaialttext\controllers;

use Craft;
use craft\web\Controller;
use craft\elements\Asset;
use yii\web\Response;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\jobs\GenerateAiAltText as GenerateAiAltTextJob;

/**
 * Generate Controller
 */
class GenerateController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Generate AI alt text for a single asset
     *
     * @return Response
     */
    public function actionSingleAsset(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $assetId = $this->request->getRequiredBodyParam('assetId');
        $siteId = $this->request->getRequiredBodyParam('siteId');

        // Get the asset
        $asset = Asset::find()->id($assetId)->siteId($siteId)->one();
        if (!$asset) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('ai-alt-text', 'Asset not found'),
            ]);
        }

        // Check permissions
        $this->requirePermission('saveAssets:' . $asset->getVolume()->uid);

        try {
            AiAltText::getInstance()->aiAltTextService->createJob($asset, true);

            // Return success
            return $this->asJson([
                'success' => true,
                'message' => Craft::t('ai-alt-text', 'Alt text generation has been queued'),
            ]);
        } catch (\Exception $e) {
            Craft::error('Error queueing alt text generation: ' . $e->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate AI alt text for all assets
     *
     * @return Response
     */
    public function actionGenerateAllAssets(): Response
    {
        // Require permissions to save assets
        $this->requirePermission('accessCp');
        
        // Get current site
        $currentSite = Craft::$app->getSites()->getCurrentSite();
        
        try {
            // Find all image assets without alt text
            $assets = Asset::find()
                ->kind(Asset::KIND_IMAGE)
                ->siteId($currentSite->id)
                ->andWhere(['or', 
                    ['alt' => null],
                    ['alt' => '']
                ])
                ->all();
            
            // Log asset count for debugging
            Craft::info('Found ' . count($assets) . ' assets without alt text to process', __METHOD__);
                
            $count = 0;
            $settings = AiAltText::getInstance()->getSettings();
            
            foreach ($assets as $asset) {
                // Double-check that the asset doesn't have alt text (just in case)
                if (!empty($asset->alt)) {
                    Craft::info('Skipping asset ' . $asset->id . ' because it already has alt text: ' . $asset->alt, __METHOD__);
                    continue;
                }
                
                // Log which asset we're queuing
                Craft::info('Queuing alt text generation for asset: ' . $asset->id . ' (' . $asset->filename . ')', __METHOD__);
                
                // Create a job for the asset
                AiAltText::getInstance()->aiAltTextService->createJob($asset, false, $currentSite->id);
                $count++;
            }
            
            // Set flash message
            Craft::$app->getSession()->setNotice(
                Craft::t('ai-alt-text', 'Queued alt text generation for {count} assets.', ['count' => $count])
            );
            
            // Redirect back to settings page
            return $this->redirect('settings/plugins/ai-alt-text');
        } catch (\Exception $e) {
            Craft::error('Error queueing alt text generation for all assets: ' . $e->getMessage(), __METHOD__);
            
            Craft::$app->getSession()->setError(
                Craft::t('ai-alt-text', 'Error: {message}', ['message' => $e->getMessage()])
            );
            
            return $this->redirect('settings/plugins/ai-alt-text');
        }
    }
}
