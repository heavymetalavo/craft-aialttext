<?php

namespace heavymetalavo\craftaialttext\services;

use craft\helpers\App;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * Langdock OpenAI API Service
 *
 * Langdock serves the OpenAI Responses API on its own host, with a region in the URL. Authentication is
 * the same Bearer token OpenAI uses.
 */
class LangdockOpenAiService extends OpenAiService
{
    /**
     * Reads the Langdock settings in place of the OpenAI ones.
     *
     * @inheritdoc
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = App::parseEnv($settings->langdockApiKey);
        $this->model = App::parseEnv($settings->langdockModel);
        $this->baseUrl = 'https://api.langdock.com/openai/' . App::parseEnv($settings->langdockRegion) . '/v1';
    }

    /**
     * Uses the Langdock Image Detail Level setting, not the OpenAI one.
     *
     * @inheritdoc
     */
    protected function imageDetailLevel(): string
    {
        return App::parseEnv(AiAltText::getInstance()->getSettings()->langdockImageInputDetailLevel) ?: 'low';
    }

    /**
     * Uses the Langdock Reasoning Effort setting, not the OpenAI one.
     *
     * @inheritdoc
     */
    protected function reasoningEffort(): string
    {
        return (string) App::parseEnv(AiAltText::getInstance()->getSettings()->langdockReasoningEffort);
    }
}
