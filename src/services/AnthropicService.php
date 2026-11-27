<?php

namespace heavymetalavo\craftaialttext\services;

use craft\helpers\App;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * Anthropic API Service
 *
 * Anthropic's own Messages API, authenticated with an `x-api-key` header and an `anthropic-version`.
 */
class AnthropicService extends MessagesApiService
{
    protected string $baseUrl = 'https://api.anthropic.com/v1';
    protected string $providerLabel = 'Anthropic';

    /**
     * @inheritdoc
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = App::parseEnv($settings->anthropicApiKey);
        $this->model = App::parseEnv($settings->anthropicModel);
        $this->detailLevel = $settings->anthropicImageDetailLevel;
    }

    /**
     * @inheritdoc
     */
    protected function requestHeaders(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ];
    }
}
