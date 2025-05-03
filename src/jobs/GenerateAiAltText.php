<?php

namespace heavymetalavo\craftaialttext\jobs;

use Craft;
use craft\elements\Asset;
use craft\errors\ElementNotFoundException;
use craft\queue\BaseJob;
use heavymetalavo\craftaialttext\AiAltText;
use Throwable;
use yii\base\Exception;

/**
 * Generate Alt Text queue job
 */
class GenerateAiAltText extends BaseJob
{
    public ?int $assetId = null;
    public ?int $siteId = null;
    public bool $forceRegeneration = false;

    /**
     * @throws ElementNotFoundException
     * @throws Exception
     * @throws Throwable
     */
    function execute($queue): void
    {
        try {
            // query for the asset
            $asset = Asset::find()->id($this->assetId)->siteId($this->siteId)->one();

            // check if the asset exists
            if (!$asset) {
                throw new ElementNotFoundException("Asset not found: $this->assetId");
            }

            // Generate alt text - now returns a string and saves the asset if successful
            $altText = AiAltText::getInstance()->aiAltTextService->generateAltText($asset, $this->siteId, $this->forceRegeneration);

            // Log the result
            if (!empty($altText)) {
                Craft::info("Successfully generated alt text for asset $this->assetId: " . $altText, __METHOD__);
            } else {
                Craft::warning("Failed to generate alt text for asset $this->assetId", __METHOD__);
                // Set the description to indicate failure
                $this->description = "Failed to generate alt text";
            }
        } catch (Exception $e) {
            Craft::error("Error in GenerateAiAltText job: " . $e->getMessage(), __METHOD__);
            // Set the description to indicate error
            $this->description = "Error: " . $e->getMessage();
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Generate alt text";
    }
}
