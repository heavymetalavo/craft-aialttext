<?php

namespace heavymetalavo\craftaialttext\services;

use craft\helpers\App;
use heavymetalavo\craftaialttext\AiAltText;

/**
 * Langdock OpenAI API Service
 *
 * Langdock serves the OpenAI Responses API on its own host, with a region in the URL. Authentication is
 * the same Bearer token OpenAI uses.
 */
class LangdockOpenAiService extends OpenAiService
{
    /**
     * Reads the Langdock settings in place of the OpenAI ones. The constructor chain skips
     * OpenAiService::__construct(), which only reads the OpenAI settings, so that the component
     * config is still applied last, as in the other provider services.
     *
     * @param array $config Standard Yii component configuration.
     */
    public function __construct($config = [])
    {
        $settings = AiAltText::getInstance()->getSettings();
        $this->apiKey = App::parseEnv($settings->langdockApiKey);
        $this->model = App::parseEnv($settings->langdockModel);
        $this->baseUrl = 'https://api.langdock.com/openai/' . App::parseEnv($settings->langdockRegion) . '/v1';

        ApiService::__construct($config);
    }

    /**
     * @inheritdoc
     */
    protected function imageDetailLevel(): string
    {
        return App::parseEnv(AiAltText::getInstance()->getSettings()->langdockImageInputDetailLevel) ?: 'low';
    }

    /**
     * @inheritdoc
     */
    protected function reasoningEffort(): string
    {
        return (string) App::parseEnv(AiAltText::getInstance()->getSettings()->langdockReasoningEffort);
    }
}
