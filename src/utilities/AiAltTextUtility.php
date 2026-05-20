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
        return t('AI Alt Text', category: 'ai-alt-text');
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
        return dirname(__DIR__) . '/icon.svg';
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
                ];

                $totalAssetsWithAltTextForAllSites += $withAltCount;
                $totalAssetsWithoutAltTextForAllSites += $withoutAltCount;
            } catch (Exception $e) {
                Log::error("Error counting assets for site {$site->name}: " . $e->getMessage());
            }
        }

        return template('ai-alt-text/_utility', [
            'totalAssetsWithAltTextForAllSites' => $totalAssetsWithAltTextForAllSites,
            'totalAssetsWithoutAltTextForAllSites' => $totalAssetsWithoutAltTextForAllSites,
            'sites' => $sites,
            'siteAltTextCounts' => $siteAltTextCounts,
        ]);
    }
}
