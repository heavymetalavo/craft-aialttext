<?php

namespace heavymetalavo\craftaialttext\utilities;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Utility\Utility;
use Exception;
use Illuminate\Support\Facades\Log;

use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

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
        return t('AI Alt Text Bulk Actions', category: 'ai-alt-text');
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
    public static function icon(): ?string
    {
        // The mask, not icon.svg: control panel chrome expects a monochrome silhouette it can
        // colour via currentColor. icon.svg is the full-colour store artwork, which renders in
        // the nav as its own opaque dark tile.
        return dirname(__DIR__) . '/icon-mask.svg';
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $currentSite = Sites::getCurrentSite();
        $sites = Sites::getAllSites();

        $totalAssetsWithAltTextForAllSites = 0;
        $totalAssetsWithoutAltTextForAllSites = 0;
        $siteAltTextCounts = [];

        foreach ($sites as $site) {
            $siteAltTextCounts[$site->id] = [
                'total' => 0,
                'with' => 0,
                'without' => 0,
                'coverage' => null,
            ];

            try {
                $totalImageAssets = Asset::find()
                    ->kind('image')
                    ->siteId($site->id)
                    ->status(null)
                    ->count();

                $withAltCount = Asset::find()
                    ->kind('image')
                    ->siteId($site->id)
                    ->status(null)
                    ->hasAlt(true)
                    ->count();

                $withoutAltCount = $totalImageAssets - $withAltCount;

                $siteAltTextCounts[$site->id] = [
                    'total' => $totalImageAssets,
                    'with' => $withAltCount,
                    'without' => $withoutAltCount,
                    'coverage' => $totalImageAssets > 0 ? ($withAltCount / $totalImageAssets * 100) : null,
                ];

                $totalAssetsWithAltTextForAllSites += $withAltCount;
                $totalAssetsWithoutAltTextForAllSites += $withoutAltCount;
            } catch (Exception $e) {
                Log::error("Error counting assets for site {$site->name}: " . $e->getMessage());
            }
        }

        $totalForAllSites = $totalAssetsWithAltTextForAllSites + $totalAssetsWithoutAltTextForAllSites;

        return template('ai-alt-text/_utility', [
            'totalAssetsWithAltTextForAllSites' => $totalAssetsWithAltTextForAllSites,
            'totalAssetsWithoutAltTextForAllSites' => $totalAssetsWithoutAltTextForAllSites,
            'coverageForAllSites' => $totalForAllSites > 0 ? ($totalAssetsWithAltTextForAllSites / $totalForAllSites * 100) : null,
            'sites' => $sites,
            'siteAltTextCounts' => $siteAltTextCounts,
        ]);
    }
}
