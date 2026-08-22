<?php

namespace heavymetalavo\craftaialttext\Commands;

use CraftCms\Cms\Console\CraftCommand;
use heavymetalavo\craftaialttext\services\AiAltTextService;
use Illuminate\Console\Command;

/**
 * Generate (or regenerate) AI alt text for ALL image assets.
 *
 * Usage: php artisan ai-alt-text:all [--site-id=] [--batch-size=] [--force]
 */
class GenerateAll extends Command
{
    use CraftCommand;
    use ProcessesAssets;

    protected $signature = 'ai-alt-text:all
        {--site-id= : Specific site ID to process (defaults to all sites)}
        {--batch-size=500 : Number of asset IDs to load per batch}
        {--force : Skip confirmation prompt}';

    protected $description = 'Generate AI alt text for ALL image assets (including those that already have alt text)';

    public function handle(AiAltTextService $service): int
    {
        $this->warn('This will regenerate alt text for ALL image assets...');

        if (!$this->option('force')) {
            if (!$this->confirm('This will regenerate alt text for ALL assets, including those that already have it. Continue?')) {
                $this->line('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        return $this->processAssets($service, includeWithAltText: true);
    }
}
