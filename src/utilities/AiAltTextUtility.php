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
        // Alt text is global on Craft 4 (one column on the assets table), so per-site
        // counts are identical — count once rather than per site.
        $totalAssetsWithAltTextForAllSites = 0;
        $totalAssetsWithoutAltTextForAllSites = 0;

        try {
            $totalImageAssets = Asset::find()
                ->kind(Asset::KIND_IMAGE)
                ->status(null)
                ->count();

            $totalAssetsWithAltTextForAllSites = Asset::find()
                ->kind(Asset::KIND_IMAGE)
                ->status(null)
                ->hasAlt(true)
                ->count();

            $totalAssetsWithoutAltTextForAllSites = $totalImageAssets - $totalAssetsWithAltTextForAllSites;
        } catch (Exception $e) {
            Craft::error("Error counting assets: " . $e->getMessage(), __METHOD__);
        }

        return Craft::$app->getView()->renderTemplate(
            'ai-alt-text/_utility',
            [
                'totalAssetsWithAltTextForAllSites' => $totalAssetsWithAltTextForAllSites,
                'totalAssetsWithoutAltTextForAllSites' => $totalAssetsWithoutAltTextForAllSites,
            ]
        );
    }
}
