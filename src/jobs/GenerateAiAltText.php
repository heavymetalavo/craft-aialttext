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
        // query for the asset
        $asset = Asset::find()->id($this->elementId)->one();

        // check if the asset exists
        if (!$asset) {
            throw new ElementNotFoundException("Asset not found: {$this->elementId}");
        }

        $altText = AiAltText::getInstance()->aiAltTextService->generateAltText($asset);

        if (!$altText) {
            throw new Exception("Failed to generate alt text for asset: {$this->elementId}");
        }

        // use the selected alt text field on the asset from plugin settings
        $asset->alt = $altText;

        // save element
        Craft::$app->getElements()->saveElement($asset);
    }

    protected function defaultDescription(): ?string
    {
        return "Generate alt text";
    }
}
