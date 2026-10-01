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

    /**
     * Errors are not caught so Craft marks the job as failed and offers a retry.
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

        // Generates the alt text and saves the asset, or throws
        $altText = $plugin->aiAltTextService->generateAltText($asset, $this->siteId);

        Craft::info("Successfully generated alt text for asset $this->assetId: " . $altText, __METHOD__);
    }

    protected function defaultDescription(): ?string
    {
        return "Generate alt text";
    }
}
