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
        $this->comment("Image assets only. Videos, PDFs and audio are excluded. Formats the provider can't yet process are still counted.");

        $siteId = $this->option('site-id');
        // getAllSites() returns a collection keyed by site ID, so the resulting array has no
        // index 0 — filter the single-site case instead of indexing into it.
        $sites = $siteId
            ? array_filter([Sites::getSiteById((int)$siteId)])
            : Sites::getAllSites()->all();

        if (empty($sites)) {
            $this->error("Invalid site ID: {$siteId}");
            return self::FAILURE;
        }

        $totalAssets = 0;
        $totalWithAlt = 0;
        $totalWithoutAlt = 0;
        $siteStats = [];

        foreach ($sites as $site) {
            $siteTotal = Asset::find()
                ->kind('image')
                ->siteId($site->id)
                ->status(null)
                ->count();

            // hasAlt() reads the per-site alt value (falling back to the asset's own alt), so
            // these counts match the utility's; a raw `alt` condition would hit the
            // site-agnostic assets.alt column and report the same figure for every site.
            $siteWithAlt = Asset::find()
                ->kind('image')
                ->siteId($site->id)
                ->status(null)
                ->hasAlt(true)
                ->count();

            $siteWithoutAlt = $siteTotal - $siteWithAlt;

            // Collected rather than printed here so the all-sites row can lead the table, as it
            // does in the utility
            $siteStats[] = [
                'name' => $site->name,
                'total' => $siteTotal,
                'with' => $siteWithAlt,
                'without' => $siteWithoutAlt,
                'coverage' => $siteTotal > 0 ? ($siteWithAlt / $siteTotal * 100) : 0,
            ];

            $totalAssets += $siteTotal;
            $totalWithAlt += $siteWithAlt;
            $totalWithoutAlt += $siteWithoutAlt;
        }

        $rows = [];

        // All-sites row leads the table, mirroring the utility
        if (count($sites) > 1) {
            $totalCoverage = $totalAssets > 0 ? ($totalWithAlt / $totalAssets * 100) : 0;
            $rows[] = [
                '<options=bold>All sites</>',
                $totalAssets,
                $totalWithAlt,
                $totalWithoutAlt,
                $this->coverageCell($totalCoverage),
            ];
        }

        foreach ($siteStats as $stats) {
            $rows[] = [
                $stats['name'],
                $stats['total'],
                $stats['with'],
                $stats['without'],
                $this->coverageCell($stats['coverage']),
            ];
        }

        $this->table(
            ['Site', 'Image assets', 'With alt', 'Missing', 'Coverage'],
            $rows
        );

        if ($totalWithoutAlt > 0) {
            $this->comment("Run 'php artisan ai-alt-text:missing' to generate alt text for {$totalWithoutAlt} assets without alt text");
        } else {
            $this->info('All assets have alt text! 🎉');
        }

        return self::SUCCESS;
    }

    /**
     * Renders coverage as a filled-circle glyph followed by the percentage, approximating the
     * control panel's progress ring.
     *
     * The glyph is quantised to quarters because those are the only circle fill glyphs available,
     * making it an at-a-glance accent for the exact figure beside it. Colour bands are coarser
     * than the utility's ring, which adds lime and teal steps the basic terminal palette can't
     * express. Symfony's style tags are stripped when the output isn't decorated (`--no-ansi`,
     * or a non-TTY), so piping to a file stays clean and the column still aligns.
     */
    private function coverageCell(float $coverage): string
    {
        $glyph = ['○', '◔', '◑', '◕', '●'][(int) round(min(max($coverage, 0), 100) / 25)];

        $color = match (true) {
            $coverage >= 90 => 'green',
            $coverage >= 50 => 'yellow',
            default => 'red',
        };

        return sprintf('<fg=%s>%s</> %5.1f%%', $color, $glyph, $coverage);
    }
}
