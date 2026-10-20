<?php

namespace heavymetalavo\craftaialttext\services;

use craft\helpers\App;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * OpenAI API Service
 *
 * Alt text through OpenAI's own Responses API, with the OpenAI settings. Everything else (building the request,
 * sending it, reading the response) is in ResponsesApiService.
 */
class OpenAiService extends ResponsesApiService
{
    protected string $baseUrl = 'https://api.openai.com/v1';
    protected string $providerLabel = 'OpenAI';

    /**
     * @var bool OpenAI stores each Responses API request and response for at least 30 days unless told not to, so
     * ask it not to. The other hosts don't store them, so they are sent nothing.
     */
    protected bool $sendStoreFalse = true;

    /**
     * @inheritdoc
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = App::parseEnv($settings->openAiApiKey);
        $this->model = App::parseEnv($settings->openAiModel);
    }

    /**
     * @inheritdoc
     */
    protected function imageDetailLevel(): string
    {
        return App::parseEnv(AiAltText::getInstance()->getSettings()->openAiImageInputDetailLevel) ?: 'low';
    }

    /**
     * @inheritdoc
     */
    protected function reasoningEffort(): string
    {
        return (string) App::parseEnv(AiAltText::getInstance()->getSettings()->openAiReasoningEffort);
    }
}
