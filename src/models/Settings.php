<?php

namespace heavymetalavo\craftaialttext\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin Settings Model
 *
 * Defines the settings for the Ai Alt Text plugin: the AI provider and its
 * configuration, and alt text generation preferences.
 *
 * @property string $openAiApiKey The OpenAI API key
 * @property string $openAiModel The OpenAI model to use (e.g., 'gpt-6-luna')
 * @property string $prompt The prompt template for generating alt text
 * @property string $openAiImageInputDetailLevel The detail level for image analysis
 * @property bool $generateForNewAssets Whether to generate alt text for new assets automatically
 * @property bool $processSvgs Whether to generate alt text for SVGs
 * @property bool $saveTranslatedResultsToEachSite Whether to generate alt text for every site, not just the current site and the sites that share its alt text
 * @property string $aiProvider The API provider to use ('anthropic', 'openai', 'langdock', 'openrouter' or 'custom')
 * @property string $anthropicApiKey The Anthropic API key
 * @property string $anthropicModel The Anthropic Model
 * @property string $anthropicImageDetailLevel The Anthropic Image Detail Level ('low', 'medium', 'high')
 * @property string $openAiReasoningEffort The reasoning effort level for OpenAI models
 * @property string $langdockApiKey The Langdock API key
 * @property string $langdockModelAuthor The author of the Langdock model, which picks its API ('anthropic' for models such as Claude or 'openai' for models such as GPT)
 * @property string $langdockModel The Langdock model to use
 * @property string $langdockImageInputDetailLevel The image detail level for Langdock's OpenAI API
 * @property string $langdockReasoningEffort The reasoning effort level for Langdock's OpenAI API
 * @property string $openRouterApiKey The OpenRouter API key
 * @property string $openRouterModel The OpenRouter model to use, as named by OpenRouter
 * @property string $openRouterImageInputDetailLevel The image detail level for OpenRouter's Responses API
 * @property string $openRouterReasoningEffort The reasoning effort level for OpenRouter's Responses API
 * @property string $customBaseUrl The custom endpoint's base URL, e.g. `https://gateway.example.com/v1`
 * @property string $customApiFormat The request format the custom endpoint takes ('responses' or 'messages')
 * @property string $customApiKey The custom endpoint's API key
 * @property string $customModel The model to use, as the custom endpoint names it
 */
class Settings extends Model
{
    /**
     * @var string The default Anthropic image detail level, also used by Langdock's Anthropic API
     */
    public const DEFAULT_ANTHROPIC_IMAGE_DETAIL_LEVEL = 'low';

    /**
     * @var string The API provider to use
     */
    public string $aiProvider = '';

    /**
     * @var string The Anthropic API key
     */
    public string $anthropicApiKey = '';

    /**
     * @var string The Anthropic model to use
     */
    public string $anthropicModel = '';

    /**
     * @var string The Anthropic image detail level
     */
    public string $anthropicImageDetailLevel = self::DEFAULT_ANTHROPIC_IMAGE_DETAIL_LEVEL;

    /**
     * @var string The OpenAI API key
     */
    public string $openAiApiKey = '';

    /**
     * @var string The OpenAI model to use, must have vision capabilities
     */
    public string $openAiModel = '';

    /**
     * @var string The prompt template for generating alt text
     */
    public string $prompt = 'Describe the image provided (roughly 150 characters). The output MUST be suitable for use directly as an HTML alt attribute value. Consider transparency within the image if supported by the file type, e.g. don\'t suggest it has a dark background if it is transparent. When describing a person do not assume their gender. Do not add a prefix of any kind (e.g. "#", "alt text:", "An image of", "A photo of"). Do not wrap the output in quotes. Output in the language: {site.languageName} (BCP 47: {site.language})';

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
     * @var string The reasoning effort level for OpenAI reasoning models
     *
     * Options: none, minimal, low, medium, high, xhigh
     */
    public string $openAiReasoningEffort = 'low';

    /**
     * @var string The Langdock API key
     */
    public string $langdockApiKey = '';

    /**
     * @var string The author of the Langdock model, which decides the compatible API requests are sent to
     *
     * Options: anthropic (models such as Claude), openai (models such as GPT)
     */
    public string $langdockModelAuthor = 'anthropic';

    /**
     * @var string The Langdock model to use, must have vision capabilities
     */
    public string $langdockModel = '';

    /**
     * @var string The image detail level for Langdock's OpenAI API, ignored by its Anthropic API
     *
     * Options: low, high, auto
     */
    public string $langdockImageInputDetailLevel = 'low';

    /**
     * @var string The reasoning effort level for Langdock's OpenAI API, ignored by its Anthropic API
     *
     * Options: none, minimal, low, medium, high, xhigh
     */
    public string $langdockReasoningEffort = 'low';

    /**
     * @var string The OpenRouter API key
     */
    public string $openRouterApiKey = '';

    /**
     * @var string The OpenRouter model to use, as OpenRouter names it (e.g. `anthropic/claude-haiku-5.5` or
     * `openai/gpt-6-luna`), must have vision capabilities
     */
    public string $openRouterModel = '';

    /**
     * @var string The image detail level for OpenRouter's Responses API
     *
     * Options: low, high, auto, original
     */
    public string $openRouterImageInputDetailLevel = 'low';

    /**
     * @var string The reasoning effort level for OpenRouter's Responses API
     *
     * Options: none, minimal, low, medium, high, xhigh
     */
    public string $openRouterReasoningEffort = 'low';

    /**
     * @var string The base URL of a gateway that takes the Responses API or Messages API request unchanged, without the
     * `/responses` or `/messages` at the end, e.g. `https://gateway.example.com/v1`
     */
    public string $customBaseUrl = '';

    /**
     * @var string The request format the custom endpoint takes
     *
     * Options: responses (OpenAI's Responses API, as OpenAI, Langdock's GPT models and OpenRouter take it), messages
     * (Anthropic's Messages API, as Anthropic and Langdock's Claude models take it)
     */
    public string $customApiFormat = 'responses';

    /**
     * @var string The custom endpoint's API key
     */
    public string $customApiKey = '';

    /**
     * @var string The model to use, as the custom endpoint names it, must have vision capabilities
     */
    public string $customModel = '';

    /**
     * @var bool Whether to save the translated result to each Site's Asset's translatable alt text field
     */
    public bool $saveTranslatedResultsToEachSite = false;

    /**
     * @var bool Whether to generate alt text for new assets automatically
     */
    public bool $generateForNewAssets = true;

    /**
     * @var bool Whether to attempt generating alt text for SVG files
     */
    public bool $processSvgs = false;

    /**
     * @inheritdoc
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        // 5.2.0 stored the Langdock Model Author as `langdockModelFamily`. Still read that name, so a project config
        // that hasn't been migrated yet or a config file that still uses it keeps working. A config file overrides
        // the project config, so the old name wins when both are present.
        if (is_array($values) && isset($values['langdockModelFamily'])) {
            $values['langdockModelAuthor'] = $values['langdockModelFamily'];
            unset($values['langdockModelFamily']);
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['aiProvider', 'prompt'], 'required'],
            [
                ['aiProvider'],
                function($attribute) {
                    $val = App::parseEnv($this->$attribute);
                    if (!in_array($val, ['openai', 'anthropic', 'langdock', 'openrouter', 'custom'], true)) {
                        $this->addError($attribute, 'Invalid AI Provider configured.');
                    }
                }
            ],
            ['openAiApiKey', 'string'],
            ['openAiModel', 'string'],
            ['anthropicApiKey', 'string'],
            ['anthropicModel', 'string'],
            [
                ['anthropicImageDetailLevel'],
                function($attribute) {
                    $val = App::parseEnv($this->$attribute);
                    if (!in_array($val, ['low', 'medium', 'high', ''], true)) {
                        $this->addError($attribute, 'Invalid Anthropic Image Detail Level configured.');
                    }
                }
            ],

            ['prompt', 'string'],
            ['openAiImageInputDetailLevel', 'string'],
            [
                ['openAiImageInputDetailLevel'],
                function($attribute) {
                    $val = App::parseEnv($this->$attribute);
                    if (!in_array($val, ['low', 'high', 'original', 'auto', ''], true)) {
                        $this->addError($attribute, 'Invalid OpenAI Image Input Detail Level configured.');
                    }
                }
            ],
            ['openAiReasoningEffort', 'string'],
            [
                ['openAiReasoningEffort'],
                function($attribute) {
                    $val = App::parseEnv($this->$attribute);
                    if (!in_array($val, ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', ''], true)) {
                        $this->addError($attribute, 'Invalid OpenAI Reasoning Effort configured.');
                    }
                }
            ],
            [['langdockApiKey', 'langdockModel'], 'string'],
            [['langdockModelAuthor'], 'validateOneOf', 'params' => ['allowed' => ['anthropic', 'openai'], 'message' => 'Invalid Langdock Model Author configured.']],
            ['langdockImageInputDetailLevel', 'string'],
            [['langdockImageInputDetailLevel'], 'validateOneOf', 'params' => ['allowed' => ['low', 'high', 'auto', ''], 'message' => 'Invalid Langdock Image Input Detail Level configured.']],
            ['langdockReasoningEffort', 'string'],
            [['langdockReasoningEffort'], 'validateOneOf', 'params' => ['allowed' => ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', ''], 'message' => 'Invalid Langdock Reasoning Effort configured.']],
            [['openRouterApiKey', 'openRouterModel'], 'string'],
            ['openRouterImageInputDetailLevel', 'string'],
            [['openRouterImageInputDetailLevel'], 'validateOneOf', 'params' => ['allowed' => ['low', 'high', 'auto', 'original', ''], 'message' => 'Invalid OpenRouter Image Input Detail Level configured.']],
            ['openRouterReasoningEffort', 'string'],
            [['openRouterReasoningEffort'], 'validateOneOf', 'params' => ['allowed' => ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', ''], 'message' => 'Invalid OpenRouter Reasoning Effort configured.']],
            [['customBaseUrl', 'customApiKey', 'customModel'], 'string'],
            [['customApiFormat'], 'validateOneOf', 'params' => ['allowed' => ['responses', 'messages'], 'message' => 'Invalid Custom endpoint API format configured.']],
            ['saveTranslatedResultsToEachSite', 'boolean'],
            ['generateForNewAssets', 'boolean'],
            ['processSvgs', 'boolean'],
        ];
    }

    /**
     * Inline validator: the attribute, once any environment variable is resolved, must be one of `params['allowed']`.
     */
    public function validateOneOf(string $attribute, array $params): void
    {
        if (!in_array(App::parseEnv($this->$attribute), $params['allowed'], true)) {
            $this->addError($attribute, $params['message']);
        }
    }
}
