<?php

namespace heavymetalavo\craftaialttext\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\Asset;
use craft\helpers\Json;
use yii\base\Exception;

/**
 * Generate AI Filename action
 *
 * @property-read string $triggerLabel The action's trigger label
 * @property-read string $confirmationMessage The action's confirmation message
 * @property-read string $successMessage The action's success message
 */
class GenerateAiFilename extends ElementAction
{
    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return Craft::t('ai-alt-text', 'Generate AI Filename');
    }

    /**
     * @inheritdoc
     */
    public function getConfirmationMessage(): ?string
    {
        return Craft::t('ai-alt-text', 'Are you sure you want to generate AI filenames for the selected assets?');
    }

    /**
     * @inheritdoc
     */
    public function getSuccessMessage(): ?string
    {
        return Craft::t('ai-alt-text', 'AI filenames generated successfully.');
    }

    /**
     * @inheritdoc
     */
    public function performAction(Asset $asset): bool
    {
        try {
            // Get the service
            $service = Craft::$app->getPlugins()->getPlugin('ai-alt-text')->aiAltTextService;

            // Generate the filename
            $filename = $service->generateFilename($asset);

            // Update the asset's filename
            $asset->newFilename = $filename . '.' . $asset->getExtension();
            if (!Craft::$app->elements->saveElement($asset)) {
                throw new Exception('Failed to save new filename for asset: ' . $asset->filename);
            }

            return true;
        } catch (Exception $e) {
            $this->setMessage($e->getMessage());
            return false;
        }
    }
} 