<?php

namespace heavymetalavo\craftaialttext\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
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
     * @var bool Force regeneration even if alt text already exists
     */
    public $force = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['siteId', 'batchSize', 'verbose', 'force']);
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
     * @param int $assetId The asset ID to process
     * @param int|null $siteId The site ID (optional, uses primary site if not specified)
     * @return int Exit code
     */
    public function actionSingle(int $assetId, int $siteId = null): int
    {
        $this->success("Generating AI alt text for single asset...");
        
        // Use provided siteId or fall back to the option or primary site
        $targetSiteId = $siteId ?? $this->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        
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
            
        } catch (\Exception $e) {
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
        $this->stdout(str_repeat("=", 50) . "\n");
        
        $sites = $this->siteId ? [Craft::$app->getSites()->getSiteById($this->siteId)] : Craft::$app->getSites()->getAllSites();
        
        if (!$sites[0]) {
            $this->failure("Invalid site ID: {$this->siteId}");
            return ExitCode::DATAERR;
        }
        
        $totalAssets = 0;
        $totalWithAlt = 0;
        $totalWithoutAlt = 0;
        
        foreach ($sites as $site) {
            // Count total image assets
            $siteTotal = Asset::find()
                ->kind(Asset::KIND_IMAGE)
                ->siteId($site->id)
                ->count();
            
            // Count assets with alt text
            $siteWithAlt = Asset::find()
                ->kind(Asset::KIND_IMAGE)
                ->siteId($site->id)
                ->where(['not', ['alt' => null]])
                ->andWhere(['not', ['alt' => '']])
                ->count();
            
            $siteWithoutAlt = $siteTotal - $siteWithAlt;
            
            // Display site stats
            $coverage = $siteTotal > 0 ? ($siteWithAlt / $siteTotal * 100) : 0;
            $this->stdout(sprintf(
                "Site: %-20s Total: %6d  With Alt: %6d  Missing: %6d  Coverage: %5.1f%%\n",
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
            $this->stdout(str_repeat("-", 50) . "\n");
            $totalCoverage = $totalAssets > 0 ? ($totalWithAlt / $totalAssets * 100) : 0;
            $this->stdout(sprintf(
                "TOTAL: %-15s Total: %6d  With Alt: %6d  Missing: %6d  Coverage: %5.1f%%\n",
                "All Sites",
                $totalAssets,
                $totalWithAlt,
                $totalWithoutAlt,
                $totalCoverage
            ));
        }
        
        // Provide recommendations
        if ($totalWithoutAlt > 0) {
            $this->tip("Run 'ai-alt-text-cli/missing' to generate alt text for {$totalWithoutAlt} assets without alt text");
        } else {
            $this->success("All assets have alt text! 🎉");
        }
        
        return ExitCode::OK;
    }

    /**
     * Process assets for alt text generation
     *
     * @param bool $includeWithAltText Whether to include assets that already have alt text
     * @return int Exit code
     */
    private function processAssets(bool $includeWithAltText): int
    {
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
                    ->siteId($site->id);
                
                if (!$includeWithAltText) {
                    $query->andWhere(['or', 
                        ['alt' => null],
                        ['alt' => '']
                    ]);
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
                        ->offset($offset)
                        ->limit($this->batchSize);
                    
                    if (!$includeWithAltText) {
                        $query->andWhere(['or', 
                            ['alt' => null],
                            ['alt' => '']
                        ]);
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
                            
                        } catch (\Exception $e) {
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
            
        } catch (\Exception $e) {
            Console::endProgress();
            $this->failure("\nError: {$e->getMessage()}");
            return ExitCode::SOFTWARE;
        }
    }
} 