<?php

namespace heavymetalavo\craftaialttext\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use yii\console\ExitCode;
use yii\helpers\BaseConsole;

/**
 * Generate AI Alt Text Console Commands
 * 
 * Provides CLI commands for bulk generating AI alt text for assets.
 * Optimized for handling large numbers of assets (50k+) with memory efficiency.
 */
class GenerateController extends Controller
{
    /**
     * @var int|null Specific site ID to process. If not specified, processes ALL sites.
     */
    public $siteId;
    
    /**
     * @var int Batch size for processing assets (recommended: 500 for 64MB memory limit)
     */
    public $batchSize = 500;
    
    /**
     * @var bool Show detailed progress information including memory usage
     */
    public $verbose = false;
    
    /**
     * @var bool Skip confirmation prompts (which assets get regenerated is determined by the
     * action: `all` includes assets that already have alt text, `missing` does not)
     */
    public $force = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        // Only the batch-processing actions read the batching, verbosity and confirmation options.
        // `single` and `stats` would otherwise advertise options they ignore in `--help`.
        if (in_array($actionID, ['missing', 'all'], true)) {
            return array_merge($options, ['siteId', 'batchSize', 'verbose', 'force']);
        }

        return array_merge($options, ['siteId']);
    }

    /**
     * @inheritdoc
     */
    public function optionAliases(): array
    {
        return [
            's' => 'siteId',
            'b' => 'batchSize',
            'v' => 'verbose',
            'f' => 'force',
        ];
    }

    /**
     * Generate AI alt text for a single asset
     *
     * This command generates alt text for a specific asset by its ID.
     * Useful for testing or processing individual assets.
     *
     * Pass --site-id to target a specific site, as with the other commands.
     *
     * @param int $assetId The asset ID to process
     * @return int Exit code
     */
    public function actionSingle(int $assetId): int
    {
        $this->success("Generating AI alt text for single asset...");

        // Fall back to the primary site when --site-id isn't given
        $targetSiteId = $this->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        
        // Get the asset
        $asset = Asset::find()->id($assetId)->siteId($targetSiteId)->one();
        if (!$asset) {
            $this->failure("Asset with ID {$assetId} not found for site {$targetSiteId}");
            return ExitCode::DATAERR;
        }
        
        if ($asset->kind !== Asset::KIND_IMAGE) {
            $this->failure("Asset {$assetId} is not an image");
            return ExitCode::DATAERR;
        }
        
        try {
            $this->note("Processing: {$asset->filename} (ID: {$asset->id})");
            
            AiAltText::getInstance()->aiAltTextService->createJob($asset, true, $targetSiteId);
            
            $this->success("Alt text generation queued successfully");
            $this->tip("Check the queue status with: ./craft queue/info");
            
            return ExitCode::OK;
            
        } catch (Exception $e) {
            $this->failure("Error queueing alt text generation: {$e->getMessage()}");
            return ExitCode::SOFTWARE;
        }
    }

    /**
     * Generate AI alt text for assets without existing alt text
     *
     * This command processes only assets that don't have alt text yet.
     * This is the recommended approach for most use cases as it avoids
     * regenerating alt text for assets that already have it.
     *
     * By default, processes ALL sites unless --site-id is specified.
     *
     * @return int Exit code
     */
    public function actionMissing(): int
    {
        $this->success("Generating AI alt text for assets without existing alt text...");
        
        return $this->processAssets(false);
    }

    /**
     * Generate AI alt text for ALL image assets
     *
     * This command processes ALL image assets, including those that already
     * have alt text. Use with caution as this will regenerate alt text for
     * assets that may already have manually crafted descriptions.
     *
     * By default, processes ALL sites unless --site-id is specified.
     *
     * @return int Exit code
     */
    public function actionAll(): int
    {
        $this->warning("This will regenerate alt text for ALL image assets...");
        
        if (!$this->force) {
            $this->note("This will regenerate alt text for ALL assets, including those that already have it.");
            if (!$this->confirm('Are you sure you want to continue?')) {
                $this->note("Operation cancelled.");
                return ExitCode::OK;
            }
        }
        
        return $this->processAssets(true);
    }

    /**
     * Show statistics about assets and alt text coverage
     *
     * This command displays a summary of your asset library showing
     * how many assets have alt text vs. how many are missing it.
     * Useful for understanding the scope before running bulk operations.
     *
     * By default, shows stats for ALL sites unless --site-id is specified.
     *
     * @return int Exit code
     */
    public function actionStats(): int
    {
        $this->success("Asset Alt Text Statistics");
        $this->note("Image assets only. Videos, PDFs and audio are excluded. Formats the provider can't yet process are still counted.");
        
        $sites = $this->siteId ? [Craft::$app->getSites()->getSiteById($this->siteId)] : Craft::$app->getSites()->getAllSites();
        
        if (!$sites[0]) {
            $this->failure("Invalid site ID: {$this->siteId}");
            return ExitCode::DATAERR;
        }
        
        $totalAssets = 0;
        $totalWithAlt = 0;
        $totalWithoutAlt = 0;
        $siteStats = [];
        
        foreach ($sites as $site) {
            // Count total image assets
            $siteTotal = Asset::find()
                ->kind(Asset::KIND_IMAGE)
                ->siteId($site->id)
                ->status(null)
                ->count();

            // Count assets with alt text. hasAlt() reads the per-site alt value (falling back to
            // the asset's own alt), so these counts match the utility's; a raw `alt` condition
            // would hit the site-agnostic assets.alt column and report the same figure per site.
            $siteWithAlt = Asset::find()
                ->kind(Asset::KIND_IMAGE)
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
        
        $headers = [
            'site' => 'Site',
            'total' => ['Image assets', 'align' => 'right'],
            'with' => ['With alt', 'align' => 'right'],
            'missing' => ['Missing', 'align' => 'right'],
            'coverage' => ['Coverage', 'align' => 'right'],
        ];

        $rows = [];

        // All-sites row leads the table, mirroring the utility
        if (count($sites) > 1) {
            $totalCoverage = $totalAssets > 0 ? ($totalWithAlt / $totalAssets * 100) : 0;
            $rows[] = [
                'site' => $this->ansiFormat('All sites', BaseConsole::BOLD),
                'total' => [(string)$totalAssets, 'align' => 'right'],
                'with' => [(string)$totalWithAlt, 'align' => 'right'],
                'missing' => [(string)$totalWithoutAlt, 'align' => 'right'],
                'coverage' => [$this->coverageCell($totalCoverage), 'align' => 'right'],
            ];
        }

        foreach ($siteStats as $stats) {
            $rows[] = [
                'site' => $stats['name'],
                'total' => [(string)$stats['total'], 'align' => 'right'],
                'with' => [(string)$stats['with'], 'align' => 'right'],
                'missing' => [(string)$stats['without'], 'align' => 'right'],
                'coverage' => [$this->coverageCell($stats['coverage']), 'align' => 'right'],
            ];
        }

        Console::table($headers, $rows);
        
        // Provide recommendations
        if ($totalWithoutAlt > 0) {
            $this->tip("Run 'ai-alt-text/generate/missing' to generate alt text for {$totalWithoutAlt} assets without alt text");
        } else {
            $this->success("All assets have alt text! 🎉");
        }
        
        return ExitCode::OK;
    }

    /**
     * Renders coverage as a filled-circle glyph followed by the percentage, approximating the
     * control panel's progress ring.
     *
     * The glyph is quantised to quarters because those are the only circle fill glyphs available,
     * making it an at-a-glance accent for the exact figure beside it. Colour bands are coarser than
     * the utility's ring, which adds lime and teal steps the basic terminal palette can't express.
     * ansiFormat() (rather than Console::ansiFormat()) respects `--color=0` and
     * non-TTY output, so piping to a file stays clean; Console::table() measures cells with the
     * escape codes stripped, so the column still aligns either way.
     *
     * @param float $coverage Coverage percentage
     * @return string
     */
    private function coverageCell(float $coverage): string
    {
        $glyph = ['○', '◔', '◑', '◕', '●'][(int) round(min(max($coverage, 0), 100) / 25)];

        $color = match (true) {
            $coverage >= 90 => BaseConsole::FG_GREEN,
            $coverage >= 50 => BaseConsole::FG_YELLOW,
            default => BaseConsole::FG_RED,
        };

        return sprintf('%s %5.1f%%', $this->ansiFormat($glyph, $color), $coverage);
    }

    /**
     * Process assets for alt text generation
     *
     * @param bool $includeWithAltText Whether to include assets that already have alt text
     * @return int Exit code
     */
    private function processAssets(bool $includeWithAltText): int
    {
        if ($this->batchSize < 1) {
            $this->failure("Batch size must be at least 1.");
            // USAGE (64): the command was invoked with a bad argument/option value, not a runtime data problem
            return ExitCode::USAGE;
        }

        $sites = $this->siteId ? [Craft::$app->getSites()->getSiteById($this->siteId)] : Craft::$app->getSites()->getAllSites();
        
        if (!$sites[0]) {
            $this->failure("Invalid site ID: {$this->siteId}");
            return ExitCode::DATAERR;
        }
        
        $totalCount = 0;
        $queuedCount = 0;
        
        try {
            // First, count total assets to process
            $this->note("Counting assets to process...");
            
            foreach ($sites as $site) {
                $query = Asset::find()
                    ->kind(Asset::KIND_IMAGE)
                    ->siteId($site->id)
                    ->status(null);
                
                if (!$includeWithAltText) {
                    $query->hasAlt(false);
                }

                $count = $query->count();
                $totalCount += $count;
                
                if ($this->verbose) {
                    $this->note("Site '{$site->name}': {$count} assets");
                }
            }
            
            if ($totalCount === 0) {
                $this->warning("No assets found to process.");
                return ExitCode::OK;
            }
            
            $this->note("Found {$totalCount} assets to process.");
            $this->note("Batch size: {$this->batchSize}");
            
            if (!$this->force && $totalCount > 1000) {
                if (!$this->confirm("This will process {$totalCount} assets. Continue?")) {
                    $this->note("Operation cancelled.");
                    return ExitCode::OK;
                }
            }
            
            // Initialize progress bar
            Console::startProgress(0, $totalCount, 'Processing assets: ');
            $processed = 0;
            
            foreach ($sites as $site) {
                if ($this->verbose) {
                    $this->note("\nProcessing site: {$site->name}");
                }
                
                $offset = 0;
                $hasMore = true;
                
                while ($hasMore) {
                    // Get asset IDs first (memory efficient)
                    $query = Asset::find()
                        ->kind(Asset::KIND_IMAGE)
                        ->siteId($site->id)
                        ->status(null)
                        ->orderBy(['elements.id' => SORT_ASC])
                        ->offset($offset)
                        ->limit($this->batchSize);
                    
                    if (!$includeWithAltText) {
                        $query->hasAlt(false);
                    }

                    $assetIds = $query->ids();
                    $batchSize = count($assetIds);
                    
                    if ($batchSize === 0) {
                        $hasMore = false;
                        continue;
                    }
                    
                    if ($this->verbose) {
                        $this->note("Processing batch of {$batchSize} assets (offset: {$offset})");
                    }
                    
                    // Process each asset individually to minimize memory usage
                    foreach ($assetIds as $assetId) {
                        try {
                            // Load single asset
                            $asset = Asset::find()->id($assetId)->siteId($site->id)->one();
                            
                            if (!$asset) {
                                if ($this->verbose) {
                                    $this->warning("Asset {$assetId} not found");
                                }
                                continue;
                            }
                            
                            // Skip if asset already has alt text and we're not including those
                            if (!$includeWithAltText && !empty($asset->alt)) {
                                if ($this->verbose) {
                                    $this->note("Skipping {$asset->filename} (already has alt text)");
                                }
                                continue;
                            }
                            
                            // Queue the job
                            AiAltText::getInstance()->aiAltTextService->createJob(
                                $asset, 
                                false, 
                                $site->id, 
                                false, 
                                true, 
                                true
                            );
                            
                            $queuedCount++;
                            
                            if ($this->verbose) {
                                $this->note("Queued: {$asset->filename} (ID: {$asset->id})");
                            }
                            
                            // Free memory
                            unset($asset);
                            
                        } catch (Exception $e) {
                            $this->failure("Error processing asset {$assetId}: {$e->getMessage()}");
                        }
                        
                        $processed++;
                        Console::updateProgress($processed, $totalCount);
                    }
                    
                    $offset += $this->batchSize;
                    
                    // Force garbage collection
                    unset($assetIds);
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                    
                    // Log memory usage if verbose
                    if ($this->verbose) {
                        $memoryUsage = memory_get_usage(true);
                        $memoryPeak = memory_get_peak_usage(true);
                        $this->note("Memory: " . round($memoryUsage / 1024 / 1024, 2) . "MB, Peak: " . round($memoryPeak / 1024 / 1024, 2) . "MB");
                    }
                }
            }
            
            Console::endProgress();
            $this->success("\n✓ Successfully queued {$queuedCount} assets for alt text generation.");
            $this->tip("Monitor progress with: ./craft queue/info");
            $this->tip("Run the queue with: ./craft queue/run");
            
            return ExitCode::OK;
            
        } catch (Exception $e) {
            Console::endProgress();
            $this->failure("\nError: {$e->getMessage()}");
            return ExitCode::SOFTWARE;
        }
    }
} 