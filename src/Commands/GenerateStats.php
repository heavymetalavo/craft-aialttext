<?php

namespace heavymetalavo\craftaialttext\Commands;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Console\Command;

/**
 * Show statistics about assets and alt text coverage.
 *
 * Usage: php artisan ai-alt-text:stats [--site-id=]
 */
class GenerateStats extends Command
{
    use CraftCommand;

    protected $signature = 'ai-alt-text:stats
        {--site-id= : Specific site ID to check (defaults to all sites)}';

    protected $description = 'Show asset alt text coverage statistics';

    public function handle(): int
    {
        $this->info('Asset Alt Text Statistics');
        $this->line(str_repeat('=', 50));

        $siteId = $this->option('site-id');
        $sites = $siteId
            ? [Sites::getSiteById((int)$siteId)]
            : Sites::getAllSites()->all();

        if (empty($sites) || !$sites[0]) {
            $this->error("Invalid site ID: {$siteId}");
            return self::FAILURE;
        }

        $totalAssets = 0;
        $totalWithAlt = 0;
        $totalWithoutAlt = 0;

        foreach ($sites as $site) {
            $siteTotal = Asset::find()
                ->kind('image')
                ->siteId($site->id)
                ->count();

            $siteWithAlt = Asset::find()
                ->kind('image')
                ->siteId($site->id)
                ->where(['not', ['alt' => null]])
                ->andWhere(['not', ['alt' => '']])
                ->count();

            $siteWithoutAlt = $siteTotal - $siteWithAlt;
            $coverage = $siteTotal > 0 ? ($siteWithAlt / $siteTotal * 100) : 0;

            $this->line(sprintf(
                'Site: %-20s Total: %6d  With Alt: %6d  Missing: %6d  Coverage: %5.1f%%',
                $site->name,
                $siteTotal,
                $siteWithAlt,
                $siteWithoutAlt,
                $coverage
            ));

            $totalAssets += $siteTotal;
            $totalWithAlt += $siteWithAlt;
            $totalWithoutAlt += $siteWithoutAlt;
        }

        if (count($sites) > 1) {
            $this->line(str_repeat('-', 50));
            $totalCoverage = $totalAssets > 0 ? ($totalWithAlt / $totalAssets * 100) : 0;
            $this->line(sprintf(
                'TOTAL: %-15s Total: %6d  With Alt: %6d  Missing: %6d  Coverage: %5.1f%%',
                'All Sites',
                $totalAssets,
                $totalWithAlt,
                $totalWithoutAlt,
                $totalCoverage
            ));
        }

        if ($totalWithoutAlt > 0) {
            $this->comment("Run 'php artisan ai-alt-text:missing' to generate alt text for {$totalWithoutAlt} assets without alt text");
        } else {
            $this->info('All assets have alt text!');
        }

        return self::SUCCESS;
    }
}
