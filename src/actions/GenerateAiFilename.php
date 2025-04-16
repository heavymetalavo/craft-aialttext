<?php

namespace craft\aialttext\actions;

use Craft;
use craft\aialttext\services\OpenAiService;
use Exception;
use yii\base\Action;

class GenerateAiFilename extends Action
{
    /**
     * @inheritdoc
     */
    public function execute(): void
    {
        $settings = Craft::$app->getConfig()->getConfigFromFile('craft-aialttext');
        $openAiService = new OpenAiService();

        try {
            $result = $openAiService->generateTitleAndFilename(
                $this->asset,
                $settings['titlePrompt'] ?? '',
                $settings['filenamePrompt'] ?? ''
            );

            // Update the asset's title and filename
            $this->asset->title = $result['title'];
            $this->asset->filename = $result['filename'] . '.' . $this->asset->getExtension();

            if (!Craft::$app->getElements()->saveElement($this->asset)) {
                throw new Exception('Failed to save asset: ' . json_encode($this->asset->getErrors()));
            }

            $this->setSuccessMessage(Craft::t('craft-aialttext', 'Title and filename generated successfully.'));
        } catch (Exception $e) {
            $this->setErrorMessage(Craft::t('craft-aialttext', 'Failed to generate title and filename: {error}', [
                'error' => $e->getMessage()
            ]));
        }
    }
} 