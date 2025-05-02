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
     * Generate AI alt text for assets without alt text
     *
     * @return Response
     */
    public function actionGenerateAssetsWithoutAltText(): Response
    {
        // Require permissions to save assets
        $this->requirePermission('accessCp');
        
        $totalCount = 0;
        $processedCount = 0;
        $queuedCount = 0;
        $settings = AiAltText::getInstance()->getSettings();
        $allSites = Craft::$app->getSites()->getAllSites();
        
        try {
            // First, count how many assets we need to process
            foreach ($allSites as $site) {
                $assets = Asset::find()
                    ->kind(Asset::KIND_IMAGE)
                    ->siteId($site->id)
                    ->andWhere(['or', 
                        ['alt' => null],
                        ['alt' => '']
                    ])
                    ->count();
                
                $totalCount += $assets;
            }
            
            Craft::info('Total assets without alt text across all sites: ' . $totalCount, __METHOD__);
            
            // Now process each site's assets
            foreach ($allSites as $site) {
                // Process each site
                Craft::info('Processing assets for site: ' . $site->name . ' (ID: ' . $site->id . ')', __METHOD__);
                
                // Find all image assets without alt text for this site
                // Process in batches to avoid memory issues
                $offset = 0;
                $limit = 100;
                $hasMore = true;
                
                while ($hasMore) {
                    $assets = Asset::find()
                        ->kind(Asset::KIND_IMAGE)
                        ->siteId($site->id)
                        ->andWhere(['or', 
                            ['alt' => null],
                            ['alt' => '']
                        ])
                        ->offset($offset)
                        ->limit($limit)
                        ->all();
                    
                    $batchSize = count($assets);
                    $processedCount += $batchSize;
                    
                    if ($batchSize === 0) {
                        $hasMore = false;
                        continue;
                    }
                    
                    Craft::info("Processing batch of {$batchSize} assets for site {$site->name} (offset: {$offset})", __METHOD__);
                    
                    foreach ($assets as $asset) {
                        // Double-check that the asset doesn't have alt text (just in case)
                        if (!empty($asset->alt)) {
                            Craft::info('Skipping asset ' . $asset->id . ' because it already has alt text: ' . $asset->alt, __METHOD__);
                            continue;
                        }
                        
                        try {
                            // Log which asset we're queuing
                            Craft::info('Queuing alt text generation for asset: ' . $asset->id . ' (' . $asset->filename . ') in site ' . $site->name, __METHOD__);
                            
                            // Create a job for the asset - don't need to skip the check anymore
                            AiAltText::getInstance()->aiAltTextService->createJob($asset, false, $site->id);
                            $queuedCount++;
                        } catch (\Exception $e) {
                            Craft::error('Error queuing job for asset ' . $asset->id . ': ' . $e->getMessage(), __METHOD__);
                        }
                    }
                    
                    // Move to next batch
                    $offset += $limit;
                    
                    // Prevent PHP from timing out
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }
            
            // Set flash message
            Craft::$app->getSession()->setNotice(
                Craft::t('ai-alt-text', 'Queued alt text generation for {count} assets across all sites (out of {total} total assets without alt text).', [
                    'count' => $queuedCount,
                    'total' => $totalCount
                ])
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

    /**
     * Generate AI alt text for ALL assets
     *
     * @return Response
     */
    public function actionGenerateAllAssets(): Response
    {
        // Require permissions to save assets
        $this->requirePermission('accessCp');
        
        $totalCount = 0;
        $processedCount = 0;
        $queuedCount = 0;
        $settings = AiAltText::getInstance()->getSettings();
        $allSites = Craft::$app->getSites()->getAllSites();
        
        try {
            // First, count how many assets we need to process
            foreach ($allSites as $site) {
                $assets = Asset::find()
                    ->kind(Asset::KIND_IMAGE)
                    ->siteId($site->id)
                    ->count();
                
                $totalCount += $assets;
            }
            
            Craft::info('Total image assets across all sites: ' . $totalCount, __METHOD__);
            
            // Now process each site's assets
            foreach ($allSites as $site) {
                // Process each site
                Craft::info('Processing ALL assets for site: ' . $site->name . ' (ID: ' . $site->id . ')', __METHOD__);
                
                // Find all image assets for this site
                // Process in batches to avoid memory issues
                $offset = 0;
                $limit = 100;
                $hasMore = true;
                
                while ($hasMore) {
                    $assets = Asset::find()
                        ->kind(Asset::KIND_IMAGE)
                        ->siteId($site->id)
                        ->offset($offset)
                        ->limit($limit)
                        ->all();
                    
                    $batchSize = count($assets);
                    $processedCount += $batchSize;
                    
                    if ($batchSize === 0) {
                        $hasMore = false;
                        continue;
                    }
                    
                    Craft::info("Processing batch of {$batchSize} assets for site {$site->name} (offset: {$offset})", __METHOD__);
                    
                    foreach ($assets as $asset) {
                        try {
                            // Log which asset we're queuing
                            Craft::info('Queuing alt text generation for asset: ' . $asset->id . ' (' . $asset->filename . ') in site ' . $site->name, __METHOD__);
                            
                            // Set force regeneration to true to regenerate all assets
                            AiAltText::getInstance()->aiAltTextService->createJob($asset, false, $site->id, false, true);
                            $queuedCount++;
                        } catch (\Exception $e) {
                            Craft::error('Error queuing job for asset ' . $asset->id . ': ' . $e->getMessage(), __METHOD__);
                        }
                    }
                    
                    // Move to next batch
                    $offset += $limit;
                    
                    // Prevent PHP from timing out
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }
            
            // Set flash message
            Craft::$app->getSession()->setNotice(
                Craft::t('ai-alt-text', 'Queued alt text generation for {count} assets across all sites (out of {total} total image assets).', [
                    'count' => $queuedCount,
                    'total' => $totalCount
                ])
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
