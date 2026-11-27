<?php
namespace heavymetalavo\craftaialttext\utilities;

use Craft;
use craft\base\Utility;
use craft\elements\Asset;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\web\assets\utility\UtilityAsset;

/**
 * AI Alt Text Utility
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
        Craft::$app->getView()->registerAssetBundle(UtilityAsset::class);

        $currentSite = Craft::$app->getSites()->getCurrentSite();
        $sites = Craft::$app->getSites()->getAllSites();
        
        $service = AiAltText::getInstance()->aiAltTextService;
        $totalAssetsWithAltTextForAllSites = 0;
        $totalAssetsWithoutAltTextForAllSites = 0;
        $totalExcludedForAllSites = 0;
        $siteAltTextCounts = [];
        
        foreach ($sites as $site) {
            $siteAltTextCounts[$site->id] = [
                'total' => 0,
                'with' => 0,
                'without' => 0,
                'excluded' => 0,
                'coverage' => null,
            ];

            try {
                // Excluded assets are left out, as no alt text is generated for them
                $totalImageAssets = $service->withoutExcluded(Asset::find()
                    ->kind(Asset::KIND_IMAGE)
                    ->siteId($site->id))
                    ->count();
                
                $withAltCount = $service->withoutExcluded(Asset::find()
                    ->kind(Asset::KIND_IMAGE)
                    ->siteId($site->id)
                    ->hasAlt(true))
                    ->count();
                
                $withoutAltCount = $totalImageAssets - $withAltCount;
                $excludedCount = $service->countExcluded($site->id);
                
                $siteAltTextCounts[$site->id] = [
                    'total' => $totalImageAssets + $excludedCount,
                    'with' => $withAltCount,
                    'without' => $withoutAltCount,
                    'excluded' => $excludedCount,
                    'coverage' => $totalImageAssets > 0 ? ($withAltCount / $totalImageAssets * 100) : null,
                ];
                
                $totalAssetsWithAltTextForAllSites += $withAltCount;
                $totalAssetsWithoutAltTextForAllSites += $withoutAltCount;
                $totalExcludedForAllSites += $siteAltTextCounts[$site->id]['excluded'];
            } catch (Exception $e) {
                Craft::error("Error counting assets for site {$site->name}: " . $e->getMessage(), __METHOD__);
            }
        }
        
        $totalForAllSites = $totalAssetsWithAltTextForAllSites + $totalAssetsWithoutAltTextForAllSites;

        return Craft::$app->getView()->renderTemplate(
            'ai-alt-text/_utility',
            [
                'totalAssetsWithAltTextForAllSites' => $totalAssetsWithAltTextForAllSites,
                'totalAssetsWithoutAltTextForAllSites' => $totalAssetsWithoutAltTextForAllSites,
                'coverageForAllSites' => $totalForAllSites > 0 ? ($totalAssetsWithAltTextForAllSites / $totalForAllSites * 100) : null,
                'sites' => $sites,
                'siteAltTextCounts' => $siteAltTextCounts,
                'totalExcludedForAllSites' => $totalExcludedForAllSites,
            ]
        );
    }
}
