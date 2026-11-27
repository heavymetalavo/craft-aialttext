<?php

namespace heavymetalavo\craftaialttext\services;

use craft\helpers\App;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * Google API Service
 *
 * Gemini models through the Gemini API's Interactions API, with an API key from Google AI Studio. Everything
 * else (building the request, sending it, reading the response) is in InteractionsApiService.
 */
class GoogleService extends InteractionsApiService
{
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    protected string $providerLabel = 'Google';

    /**
     * @inheritdoc
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = App::parseEnv($settings->googleApiKey);
        $this->model = App::parseEnv($settings->googleModel);
    }

    /**
     * @inheritdoc
     */
    protected function requestHeaders(): array
    {
        return [
            'x-goog-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ];
    }
}
