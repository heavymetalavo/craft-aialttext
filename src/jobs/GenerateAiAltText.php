<?php

namespace heavymetalavo\craftaialttext\jobs;

use Craft;
use craft\elements\Asset;
use craft\errors\ElementNotFoundException;
use craft\queue\BaseJob;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use Throwable;

/**
 * Generate Alt Text queue job
 */
class GenerateAiAltText extends BaseJob
{
    public ?int $assetId = null;
    public ?int $siteId = null;
    public bool $forceRegeneration = false;

    /**
     * Failures are deliberately allowed to propagate. Craft's queue then records the job as failed
     * with the exception message, surfaces it in the control panel and offers a retry — which is
     * what an admin needs in order to act on a single image that couldn't be processed.
     *
     * A previous version caught everything here and assigned `$this->description` instead. That
     * could never surface anything: `BaseJob::getDescription()` is read when the job is *pushed*
     * and written to the queue row at that point, so assigning it during `execute()` only mutates
     * an object that is discarded straight afterwards. The result was a failure that wrote a log
     * line while the job itself reported as completed.
     *
     * This does not affect the base64 fallback for images the provider can't fetch by URL: that is
     * handled inside the provider services and completes before returning here.
     *
     * @throws ElementNotFoundException
     * @throws Exception
     * @throws Throwable
     */
    function execute($queue): void
    {
        // query for the asset
        $asset = Asset::find()->id($this->assetId)->siteId($this->siteId)->one();

        // check if the asset exists
        if (!$asset) {
            throw new ElementNotFoundException("Asset not found: $this->assetId");
        }

        $plugin = AiAltText::getInstance();

        // Generates the alt text and saves the asset, or throws. It never returns an empty string —
        // generateAltText() throws for that case — so there is no empty result to check for here.
        $altText = $plugin->aiAltTextService->generateAltText($asset, $this->siteId, $this->forceRegeneration);

        Craft::info("Successfully generated alt text for asset $this->assetId: " . $altText, __METHOD__);
    }

    protected function defaultDescription(): ?string
    {
        return "Generate alt text";
    }
}
