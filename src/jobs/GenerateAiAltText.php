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
    public function __construct(
        public int $assetId,
        public int $siteId,
        public bool $forceRegeneration = false,
        public ?string $description = null,
    ) {}

    /**
     * Prevent duplicate jobs for the same asset + site combination from running concurrently.
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping("{$this->assetId}-{$this->siteId}")];
    }

    /**
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

        $altText = $service->generateAltText($asset, $this->siteId, $this->forceRegeneration);

        if (!empty($altText)) {
            Log::info("Successfully generated alt text for asset $this->assetId: " . $altText);
        } else {
            Log::warning("Failed to generate alt text for asset $this->assetId");
        }
    }

    protected function defaultDescription(): string
    {
        return $this->description ?? 'Generate alt text';
    }
}
