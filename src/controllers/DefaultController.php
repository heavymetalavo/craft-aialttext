<?php

namespace heavymetalavo\craftaialttext\controllers;

use Craft;
use craft\web\Controller;
use craft\elements\Asset;
use yii\web\Response;
use heavymetalavo\craftaialttext\AiAltText;

/**
 * Default Controller
 */
class DefaultController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Index action - redirect to bulk actions
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        return $this->redirect('ai-alt-text/bulk-actions');
    }

    /**
     * Bulk actions page
     *
     * @return Response
     */
    public function actionBulkActions(): Response
    {
        $this->requirePermission('accessCp');
        
        // Get current site and sites data
        $data = $this->getAssetStatistics();
        
        // Render template
        return $this->renderTemplate('ai-alt-text/bulk-actions', $data);
    }

    /**
     * Settings page
     *
     * @return Response
     */
    public function actionSettings(): Response
    {
        $this->requirePermission('accessCp');
        
        // Get the plugin instance
        $plugin = AiAltText::getInstance();
        
        return $this->renderTemplate('ai-alt-text/_settings', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings()
        ]);
    }
    
    /**
     * Get asset statistics for all sites
     *
     * @return array
     */
    private function getAssetStatistics(): array
    {
        // Get current site
        $currentSite = Craft::$app->getSites()->getCurrentSite();
        $sites = Craft::$app->getSites()->getAllSites();
        
        // Initialize totals
        $totalAssetsWithAltTextForAllSites = 0;
        $totalAssetsWithoutAltTextForAllSites = 0;
        $siteAltTextCounts = [];
        
        // Manually count assets for each site to avoid problematic database queries
        foreach ($sites as $site) {
            $siteAltTextCounts[$site->id] = [
                'total' => 0,
                'with' => 0,
                'without' => 0
            ];

            try {
                // Use Craft's asset service to get all assets rather than direct SQL
                $assets = Asset::find()
                    ->kind(Asset::KIND_IMAGE)
                    ->siteId($site->id)
                    ->status(null)  // Get all assets regardless of status
                    ->all();
                
                $imageAssetCount = count($assets);
                $withAltCount = 0;
                $withoutAltCount = 0;
                
                // For each asset, check if it has alt text
                foreach ($assets as $asset) {
                    // Check alt text
                    $altText = $asset->alt;
                    if ($altText !== null && trim($altText) !== '') {
                        $withAltCount++;
                    } else {
                        $withoutAltCount++;
                    }
                }
                
                // Store counts for this site
                $siteAltTextCounts[$site->id] = [
                    'total' => $imageAssetCount,
                    'with' => $withAltCount,
                    'without' => $withoutAltCount
                ];
                
                // Add to totals
                $totalAssetsWithAltTextForAllSites += $withAltCount;
                $totalAssetsWithoutAltTextForAllSites += $withoutAltCount;
                
                Craft::info("Site {$site->name}: Total: {$imageAssetCount}, With Alt: {$withAltCount}, Without Alt: {$withoutAltCount}", __METHOD__);
            } catch (\Exception $e) {
                Craft::error("Error counting assets for site {$site->name}: " . $e->getMessage(), __METHOD__);
            }
        }
        
        // Return the data
        return [
            'plugin' => AiAltText::getInstance(),
            'settings' => AiAltText::getInstance()->getSettings(),
            'totalAssetsWithAltTextForAllSites' => $totalAssetsWithAltTextForAllSites,
            'totalAssetsWithoutAltTextForAllSites' => $totalAssetsWithoutAltTextForAllSites,
            'currentSite' => $currentSite,
            'sites' => $sites,
            'siteAltTextCounts' => $siteAltTextCounts,
        ];
    }
} 