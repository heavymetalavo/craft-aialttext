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

        // The same counts for each volume, added up across the sites like the all-sites row above
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $volumeAltTextCounts = [];

        foreach ($volumes as $volume) {
            $counts = ['with' => 0, 'without' => 0, 'excluded' => 0, 'coverage' => null];

            try {
                foreach ($sites as $site) {
                    $totalImageAssets = $service->withoutExcluded($service->withinScope(Asset::find()
                        ->kind(Asset::KIND_IMAGE)
                        ->siteId($site->id), $volume))
                        ->count();
                    $withAltCount = $service->withoutExcluded($service->withinScope(Asset::find()
                        ->kind(Asset::KIND_IMAGE)
                        ->siteId($site->id)
                        ->hasAlt(true), $volume))
                        ->count();

                    $counts['with'] += $withAltCount;
                    $counts['without'] += $totalImageAssets - $withAltCount;
                    $counts['excluded'] += $service->countExcluded($site->id, $volume);
                }

                $counted = $counts['with'] + $counts['without'];
                $counts['coverage'] = $counted > 0 ? ($counts['with'] / $counted * 100) : null;
            } catch (Exception $e) {
                Craft::error("Error counting assets for volume {$volume->handle}: " . $e->getMessage(), __METHOD__);
            }

            $counts['total'] = $counts['with'] + $counts['without'] + $counts['excluded'];
            $volumeAltTextCounts[$volume->id] = $counts;
        }

        return Craft::$app->getView()->renderTemplate(
            'ai-alt-text/_utility',
            [
                'totalAssetsWithAltTextForAllSites' => $totalAssetsWithAltTextForAllSites,
                'totalAssetsWithoutAltTextForAllSites' => $totalAssetsWithoutAltTextForAllSites,
                'coverageForAllSites' => $totalForAllSites > 0 ? ($totalAssetsWithAltTextForAllSites / $totalForAllSites * 100) : null,
                'sites' => $sites,
                'siteAltTextCounts' => $siteAltTextCounts,
                'totalExcludedForAllSites' => $totalExcludedForAllSites,
                'volumes' => $volumes,
                'volumeAltTextCounts' => $volumeAltTextCounts,
            ]
        );
    }
}
