<?php

namespace heavymetalavo\craftaialttext\Commands;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Enums\FileKind;
use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Support\Facades\Sites;
use heavymetalavo\craftaialttext\services\AiAltTextService;
use Illuminate\Console\Command;

/**
 * Generate AI alt text for a single asset by ID.
 *
 * Usage: php artisan ai-alt-text:single {assetId} [--site-id=]
 */
class GenerateSingle extends Command
{
    use CraftCommand;

    protected $signature = 'ai-alt-text:single
        {assetId : The ID of the asset to process}
        {--site-id= : Site ID (defaults to primary site)}';

    protected $description = 'Generate AI alt text for a single asset';

    public function handle(AiAltTextService $service): int
    {
        $assetId = (int)$this->argument('assetId');
        $siteId = $this->option('site-id')
            ? (int)$this->option('site-id')
            : Sites::getCurrentSite()->id;

        $this->info("Generating AI alt text for single asset...");

        $asset = Asset::find()->id($assetId)->siteId($siteId)->one();
        if (!$asset) {
            $this->error("Asset with ID {$assetId} not found for site {$siteId}");
            return self::FAILURE;
        }

        if ($asset->kind !== FileKind::Image->value) {
            $this->error("Asset {$assetId} is not an image");
            return self::FAILURE;
        }

        $this->line("Processing: {$asset->filename} (ID: {$asset->id})");

        try {
            $service->createJob($asset, true, $siteId);
            $this->info('Alt text generation queued successfully');
            $this->comment('Check the queue status with: php artisan queue:monitor');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error queueing alt text generation: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
