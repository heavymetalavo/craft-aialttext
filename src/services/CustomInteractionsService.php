<?php

namespace heavymetalavo\craftaialttext\services;

use craft\helpers\App;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * Custom Interactions API Service
 *
 * A gateway at a base URL of the site's choosing that takes Google's Interactions API request unchanged
 * (`POST {baseUrl}/interactions`), for the Custom endpoint provider with the Interactions API format. Images are always
 * sent as base64 in this API, as for GoogleService.
 */
class CustomInteractionsService extends InteractionsApiService
{
    protected string $providerLabel = 'Custom endpoint';

    /**
     * @inheritdoc
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = (string)App::parseEnv($settings->customApiKey);
        $this->model = (string)App::parseEnv($settings->customModel);
        $this->baseUrl = rtrim(trim((string)App::parseEnv($settings->customBaseUrl)), '/');
    }

    /**
     * The key is sent as Google takes it, in `x-goog-api-key`. It isn't also sent as a Bearer token, as for the Messages
     * API, because Google reads that as an OAuth token and refuses the request with a 401.
     *
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
