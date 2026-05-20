<?php

namespace heavymetalavo\craftaialttext\Commands;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Support\Facades\Sites;
use Exception;
use heavymetalavo\craftaialttext\services\AiAltTextService;

/**
 * Shared asset-processing logic for GenerateMissing and GenerateAll commands.
 */
trait ProcessesAssets
{
    /**
     * Queue alt text generation jobs for assets across all (or a specific) site.
     *
     * @param AiAltTextService $service
     * @param bool $includeWithAltText When true, also re-queues assets that already have alt text
     */
    protected function processAssets(AiAltTextService $service, bool $includeWithAltText): int
    {
        $siteId = $this->option('site-id');
        $batchSize = (int)($this->option('batch-size') ?? 500);

        $sites = $siteId
            ? [Sites::getSiteById((int)$siteId)]
            : Sites::getAllSites()->all();

        if (empty($sites) || !$sites[0]) {
            $this->error("Invalid site ID: {$siteId}");
            return self::FAILURE;
        }

        $totalCount = 0;
        $queuedCount = 0;

        try {
            $this->line('Counting assets to process...');

            foreach ($sites as $site) {
                $query = Asset::find()
                    ->kind('image')
                    ->siteId($site->id);

                if (!$includeWithAltText) {
                    $query->andWhere(['or', ['alt' => null], ['alt' => '']]);
                }

                $count = $query->count();
                $totalCount += $count;

                if ($this->output->isVerbose()) {
                    $this->line("Site '{$site->name}': {$count} assets");
                }
            }

            if ($totalCount === 0) {
                $this->warn('No assets found to process.');
                return self::SUCCESS;
            }

            $this->line("Found {$totalCount} assets to process.");
            $this->line("Batch size: {$batchSize}");

            if (!$this->option('force') && $totalCount > 1000) {
                if (!$this->confirm("This will process {$totalCount} assets. Continue?")) {
                    $this->line('Operation cancelled.');
                    return self::SUCCESS;
                }
            }

            $bar = $this->output->createProgressBar($totalCount);
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
            $bar->start();

            foreach ($sites as $site) {
                if ($this->output->isVerbose()) {
                    $this->newLine();
                    $this->line("Processing site: {$site->name}");
                }

                $offset = 0;

                while (true) {
                    $query = Asset::find()
                        ->kind('image')
                        ->siteId($site->id)
                        ->offset($offset)
                        ->limit($batchSize);

                    if (!$includeWithAltText) {
                        $query->andWhere(['or', ['alt' => null], ['alt' => '']]);
                    }

                    $assetIds = $query->ids();

                    if (empty($assetIds)) {
                        break;
                    }

                    if ($this->output->isVerbose()) {
                        $this->newLine();
                        $this->line("Processing batch of " . count($assetIds) . " assets (offset: {$offset})");
                    }

                    foreach ($assetIds as $assetId) {
                        try {
                            $asset = Asset::find()->id($assetId)->siteId($site->id)->one();

                            if (!$asset) {
                                if ($this->output->isVerbose()) {
                                    $this->warn("Asset {$assetId} not found");
                                }
                                $bar->advance();
                                continue;
                            }

                            if (!$includeWithAltText && !empty($asset->alt)) {
                                if ($this->output->isVerbose()) {
                                    $this->line("Skipping {$asset->filename} (already has alt text)");
                                }
                                $bar->advance();
                                continue;
                            }

                            $service->createJob($asset, false, $site->id, false, true, true);
                            $queuedCount++;

                            if ($this->output->isVerbose()) {
                                $this->line("Queued: {$asset->filename} (ID: {$asset->id})");
                            }

                            unset($asset);

                        } catch (Exception $e) {
                            $this->error("Error processing asset {$assetId}: {$e->getMessage()}");
                        }

                        $bar->advance();
                    }

                    $offset += $batchSize;

                    unset($assetIds);
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }

                    if ($this->output->isVerbose()) {
                        $memoryUsage = memory_get_usage(true);
                        $memoryPeak = memory_get_peak_usage(true);
                        $this->newLine();
                        $this->line("Memory: " . round($memoryUsage / 1024 / 1024, 2) . "MB, Peak: " . round($memoryPeak / 1024 / 1024, 2) . "MB");
                    }
                }
            }

            $bar->finish();
            $this->newLine();
            $this->info("Successfully queued {$queuedCount} assets for alt text generation.");
            $this->comment('Monitor progress with: php artisan queue:monitor');
            $this->comment('Run the queue with: php artisan queue:work');

            return self::SUCCESS;

        } catch (Exception $e) {
            if (isset($bar)) {
                $bar->finish();
                $this->newLine();
            }
            $this->error("Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
