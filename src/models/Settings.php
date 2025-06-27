<?php

namespace heavymetalavo\craftaialttext\models;

use craft\base\Model;

/**
 * Plugin Settings Model
 *
 * Defines the settings for the Ai Alt Text plugin.
 * This model handles the configuration options for OpenAI API integration
 * and alt text generation preferences.
 *
 * @property string $openAiApiKey The OpenAI API key
 * @property string $openAiModel The OpenAI model to use (e.g., 'gpt-4', 'gpt-4-vision-preview', 'gpt-4-mini')
 * @property string $prompt The prompt template for generating alt text
 * @property string $openAiImageInputDetailLevel The detail level for image analysis
 * @property bool $propagate Whether the asset should be saved across all of its supported sites, if enabled it could save the same initial alt text value across all sites.
 * @property bool $saveTranslatedResultsToEachSite Whether to save the translated result to each Site's Asset's translatable alt text field
 * @property string $translationPromptAppendage The prompt suffix for translated results
 * @property bool $generateForNewAssets Whether to generate alt text for new assets automatically
 */
class Settings extends Model
{
    /**
     * @var string The OpenAI API key
     */
    public string $openAiApiKey = '';

    /**
     * @var string The OpenAI model to use, must have vision capabilities
     */
    public string $openAiModel = 'gpt-4.1-nano';

    /**
     * @var string The prompt template for generating alt text
     */
    public string $prompt = 'Describe the image provided, make it suitable for an alt text description (roughly 150 characters maximum). Consider transparency within the image if supported by the file type, e.g. don\'t suggest it has a dark background if it is transparent. Do not add a prefix of any kind (e.g. alt text: AI content) so the value is suitable for the alt text attribute value of the image. Output in {site.language}';

    /**
     * @var string The detail level for image analysis
     *
     * Options:
     * - low: Faster, less detailed analysis on a 512x512 image, but cheaper
     * - high: More detailed analysis on higher quality image, more expensive
     * - auto: let the model decide
     */
    public string $openAiImageInputDetailLevel = 'low';

    /**
     * @var bool Whether the asset should be saved across all of its supported sites, if enabled it could save the same initial alt text value across all sites.
     */
    public bool $propagate = false;

    /**
     * @var bool Whether to save the translated result to each Site's Asset's translatable alt text field
     */
    public bool $saveTranslatedResultsToEachSite = false;

    /**
     * @var bool Whether to generate alt text for new assets automatically
     */
    public bool $generateForNewAssets = false;

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['openAiApiKey', 'openAiModel', 'prompt'], 'required'],
            ['openAiApiKey', 'string'],
            ['openAiModel', 'string'],
            ['prompt', 'string'],
            ['openAiImageInputDetailLevel', 'string'],
            ['openAiImageInputDetailLevel', 'in', 'range' => ['low', 'high']],
            ['propagate', 'boolean'],
            ['saveTranslatedResultsToEachSite', 'boolean'],
            ['generateForNewAssets', 'boolean'],
        ];
    }
}
