<?php

namespace heavymetalavo\craftaialttext\Commands;

use CraftCms\Cms\Console\CraftCommand;
use heavymetalavo\craftaialttext\services\AiAltTextService;
use Illuminate\Console\Command;

/**
 * Generate AI alt text for assets that don't have alt text yet.
 *
 * Usage: php artisan ai-alt-text:missing [--site-id=] [--batch-size=] [--force]
 */
class GenerateMissing extends Command
{
    use CraftCommand;
    use ProcessesAssets;

    protected $signature = 'ai-alt-text:missing
        {--site-id= : Specific site ID to process (defaults to all sites)}
        {--batch-size=500 : Number of asset IDs to load per batch}
        {--force : Skip confirmation prompt even for large asset counts}';

    protected $description = 'Generate AI alt text for assets without existing alt text';

    public function handle(AiAltTextService $service): int
    {
        $this->info('Generating AI alt text for assets without existing alt text...');
        return $this->processAssets($service, includeWithAltText: false);
    }
}
