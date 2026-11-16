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
        // Refuse up front rather than queueing jobs that would each fail with the same error
        $configurationError = $service->getConfigurationError();

        if ($configurationError !== null) {
            $this->error($configurationError);
            return self::FAILURE;
        }

        $siteId = $this->option('site-id');
        $batchSize = (int)($this->option('batch-size') ?? 500);

        if ($batchSize < 1) {
            $this->error('Batch size must be at least 1.');
            return self::INVALID;
        }

        // getAllSites() returns a collection keyed by site ID, so the resulting array has no
        // index 0 — filter the single-site case instead of indexing into it.
        $sites = $siteId
            ? array_filter([Sites::getSiteById((int)$siteId)])
            : Sites::getAllSites()->all();

        if (empty($sites)) {
            $this->error("Invalid site ID: {$siteId}");
            return self::FAILURE;
        }

        $totalCount = 0;
        $queuedCount = 0;
        $copiedCount = 0;

        try {
            $this->line('Counting assets to process...');

            foreach ($sites as $site) {
                $query = Asset::find()
                    ->kind('image')
                    ->siteId($site->id);

                if (!$includeWithAltText) {
                    // hasAlt() reads the per-site alt value (falling back to the asset's own
                    // alt). A raw `alt` condition would hit the site-agnostic assets.alt column
                    // and silently skip assets that lack alt text for this particular site.
                    $query->hasAlt(false);
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
            $this->line("Reading assets in batches of {$batchSize}.");

            if (!$this->option('force') && $totalCount > 1000) {
                if (!$this->confirm("This will process {$totalCount} assets. Continue?")) {
                    $this->line('Operation cancelled.');
                    return self::SUCCESS;
                }
            }

            $bar = $this->output->createProgressBar($totalCount);
            $bar->setFormat(' Queueing assets: %current%/%max% [%bar%] %percent:3s%%');
            $bar->start();

            foreach ($sites as $site) {
                if ($this->output->isVerbose()) {
                    $this->newLine();
                    $this->line("Processing site: {$site->name}");
                }

                $lastId = 0;

                while (true) {
                    $query = Asset::find()
                        ->kind('image')
                        ->siteId($site->id)
                        ->orderBy(['elements.id' => SORT_ASC])
                        ->where('elements.id', '>', $lastId)
                        ->limit($batchSize);

                    if (!$includeWithAltText) {
                        $query->hasAlt(false);
                    }

                    $assetIds = $query->ids();

                    if (empty($assetIds)) {
                        break;
                    }

                    // Read the queue once per batch for the duplicate-job check, rather than once per asset
                    $existingJobs = $service->pendingJobDescriptions();

                    if ($this->output->isVerbose()) {
                        $this->newLine();
                        $this->line("Processing batch of " . count($assetIds) . " assets (after ID: {$lastId})");
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

                            // Queue the job once for the sites that share the asset's alt text, or copy it from one of them
                            $result = $service->createBulkJob($asset, $includeWithAltText, !$siteId, $existingJobs);

                            if ($result === 'queued') {
                                $queuedCount++;
                            } elseif ($result === 'copied') {
                                $copiedCount++;
                            }

                            if ($this->output->isVerbose()) {
                                $this->line(match ($result) {
                                    'queued' => "Queued: {$asset->filename} (ID: {$asset->id})",
                                    'copied' => "Copied: {$asset->filename} (ID: {$asset->id}) alt text from a site that shares it",
                                    default => "Skipped: {$asset->filename} (ID: {$asset->id}) is already queued, handled by an earlier site that shares its alt text, or not eligible",
                                });
                            }

                            unset($asset);

                        } catch (Exception $e) {
                            $this->error("Error processing asset {$assetId}: {$e->getMessage()}");
                        }

                        $bar->advance();
                    }

                    // Keyset paging: the hasAlt(false) set shrinks as queued jobs complete, so an offset would skip assets
                    $lastId = max($assetIds);

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

            if ($copiedCount > 0) {
                $this->info("Copied existing alt text to {$copiedCount} assets from sites that share it.");
            }

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
