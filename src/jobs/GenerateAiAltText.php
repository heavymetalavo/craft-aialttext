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
    public ?int $elementId = null;

    /**
     * @throws ElementNotFoundException
     * @throws Exception
     * @throws Throwable
     */
    function execute($queue): void
    {
        try {
            // query for the asset
            $asset = Asset::find()->id($this->elementId)->one();

            // check if the asset exists
            if (!$asset) {
                throw new ElementNotFoundException("Asset not found: {$this->elementId}");
            }

            // Generate alt text - now returns a string and saves the asset
            $altText = AiAltText::getInstance()->aiAltTextService->generateAltText($asset);

            // Log the result
            Craft::info("Generated alt text for asset {$this->elementId}: " . $altText, __METHOD__);
        } catch (Exception $e) {
            Craft::error("Error in GenerateAiAltText job: " . $e->getMessage(), __METHOD__);
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Generate alt text";
    }
}
