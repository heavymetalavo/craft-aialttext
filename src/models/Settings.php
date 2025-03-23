<?php

namespace heavymetalavo\craftaialttext\models;

use craft\base\Model;

/**
 * Plugin Settings Model
 * 
 * Defines the settings for the AI Alt Text Generator plugin.
 * This model handles the configuration options for OpenAI API integration
 * and alt text generation preferences.
 * 
 * @property string $openAiApiKey The OpenAI API key
 * @property string $openAiModel The OpenAI model to use (e.g., 'gpt-4', 'gpt-4-vision-preview', 'gpt-4-mini')
 * @property string $prompt The prompt template for generating alt text
 * @property string $openAiImageInputDetailLevel The detail level for image analysis
 */
class Settings extends Model
{
    /**
     * @var string The OpenAI API key
     */
    public string $openAiApiKey = '';

    /**
     * @var string The OpenAI model to use
     * 
     * Available options:
     * - gpt-4: Standard GPT-4 model
     * - gpt-4-vision-preview: GPT-4 with vision capabilities
     * - gpt-4-mini: Lighter version of GPT-4
     */
    public string $openAiModel = 'gpt-4';

    /**
     * @var string The prompt template for generating alt text
     */
    public string $prompt = 'Please provide a detailed description of this image that would be suitable as alt text. Focus on the visual elements, context, and purpose of the image.';

    /**
     * @var string The detail level for image analysis
     * 
     * Only used with vision-capable models (e.g., gpt-4-vision-preview)
     * Options:
     * - low: Faster, less detailed analysis
     * - high: More detailed analysis but slower
     */
    public string $openAiImageInputDetailLevel = 'high';

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
        ];
    }
}
