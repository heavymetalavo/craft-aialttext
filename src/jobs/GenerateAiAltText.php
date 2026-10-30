<?php

namespace heavymetalavo\craftaialttext\jobs;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Queries\Exceptions\ElementNotFoundException;
use CraftCms\Cms\Queue\Job;
use heavymetalavo\craftaialttext\services\AiAltTextService;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generate Alt Text queue job
 */
class GenerateAiAltText extends Job
{
    /**
     * @var int[] Other sites that share the site's alt text, saved with the same result. A plain property with a
     * default rather than a promoted one, so a job queued before it existed still unserializes with it set.
     */
    public array $sharedSiteIds = [];

    public function __construct(
        public int $assetId,
        public int $siteId,
        array $sharedSiteIds = [],
        public ?string $description = null,
    ) {
        $this->sharedSiteIds = $sharedSiteIds;
    }

    /**
     * Prevent duplicate jobs for the same asset + site combination from running concurrently.
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping("{$this->assetId}-{$this->siteId}")];
    }

    /**
     * Errors are not caught so the job is marked as failed and can be retried.
     *
     * @throws ElementNotFoundException
     * @throws Throwable
     */
    public function handle(): void
    {
        $service = app(AiAltTextService::class);
        $asset = Asset::find()->id($this->assetId)->siteId($this->siteId)->one();

        if (!$asset) {
            throw new ElementNotFoundException("Asset not found: $this->assetId");
        }

        // Generates the alt text and saves the asset, or throws
        $altText = $service->generateAltText($asset, $this->siteId, $this->sharedSiteIds);

        Log::info("Successfully generated alt text for asset $this->assetId: " . $altText);
    }

    protected function defaultDescription(): string
    {
        return $this->description ?? 'Generate alt text';
    }
}
