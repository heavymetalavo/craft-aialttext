<?php
namespace heavymetalavo\craftaialttext\utilities;

use Craft;
use craft\base\Utility;
use craft\elements\Asset;
use Exception;

/**
 * AI Alt Text Bulk Actions Utility
 */
class AiAltTextUtility extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('ai-alt-text', 'AI Alt Text');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'ai-alt-text-bulk-actions';
    }

    /**
     * @inheritdoc
     */
    public static function iconPath(): ?string
    {
        return Craft::getAlias('@heavymetalavo/craftaialttext/icon.svg');
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $currentSite = Craft::$app->getSites()->getCurrentSite();
        $sites = Craft::$app->getSites()->getAllSites();
        
        $totalAssetsWithAltTextForAllSites = 0;
        $totalAssetsWithoutAltTextForAllSites = 0;
        $siteAltTextCounts = [];
        
        foreach ($sites as $site) {
            $siteAltTextCounts[$site->id] = [
                'total' => 0,
                'with' => 0,
                'without' => 0
            ];

            try {
                $totalImageAssets = Asset::find()
                    ->kind(Asset::KIND_IMAGE)
                    ->siteId($site->id)
                    ->status(null)
                    ->count();
                
                $withAltCount = Asset::find()
                    ->kind(Asset::KIND_IMAGE)
                    ->siteId($site->id)
                    ->status(null)
                    ->hasAlt(true)
                    ->count();
                
                $withoutAltCount = $totalImageAssets - $withAltCount;
                
                $siteAltTextCounts[$site->id] = [
                    'total' => $totalImageAssets,
                    'with' => $withAltCount,
                    'without' => $withoutAltCount
                ];
                
                $totalAssetsWithAltTextForAllSites += $withAltCount;
                $totalAssetsWithoutAltTextForAllSites += $withoutAltCount;
            } catch (Exception $e) {
                Craft::error("Error counting assets for site {$site->name}: " . $e->getMessage(), __METHOD__);
            }
        }
        
        return Craft::$app->getView()->renderTemplate(
            'ai-alt-text/_utility',
            [
                'totalAssetsWithAltTextForAllSites' => $totalAssetsWithAltTextForAllSites,
                'totalAssetsWithoutAltTextForAllSites' => $totalAssetsWithoutAltTextForAllSites,
                'sites' => $sites,
                'siteAltTextCounts' => $siteAltTextCounts,
            ]
        );
    }
}
