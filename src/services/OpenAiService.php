<?php

namespace heavymetalavo\craftaialttext\services;

use CraftCms\Cms\Support\Env;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\Settings;
use Illuminate\Container\Attributes\Singleton;

/**
 * OpenAI API Service
 *
 * Alt text through OpenAI's own Responses API, with the OpenAI settings. Everything else (building the request,
 * sending it, reading the response) is in ResponsesApiService.
 */
#[Singleton]
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
        $this->apiKey = Env::parse($settings->openAiApiKey);
        $this->model = Env::parse($settings->openAiModel);
    }

    /**
     * @inheritdoc
     */
    protected function imageDetailLevel(): string
    {
        // ?: not ?? - Env::parse() returns '' for an empty setting, never null, so ?? never fired
        // and an empty detail value was sent straight to the API.
        return Env::parse(AiAltText::settings()->openAiImageInputDetailLevel) ?: 'low';
    }

    /**
     * @inheritdoc
     */
    protected function reasoningEffort(): string
    {
        return (string) Env::parse(AiAltText::settings()->openAiReasoningEffort);
    }
}
