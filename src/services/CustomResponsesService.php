<?php

namespace heavymetalavo\craftaialttext\services;

use craft\elements\Asset;
use craft\helpers\App;
use Exception;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * Custom Responses API Service
 *
 * A gateway at a base URL of the site's choosing that takes OpenAI's Responses API request unchanged
 * (`POST {baseUrl}/responses`), for the Custom endpoint provider with the Responses API format. Authentication is a
 * Bearer token, and `store` is left out, as only OpenAI needs it.
 */
class CustomResponsesService extends ResponsesApiService
{
    protected string $providerLabel = 'Custom endpoint';

    /**
     * @inheritdoc
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = App::parseEnv($settings->customApiKey);
        $this->model = App::parseEnv($settings->customModel);
        $this->baseUrl = rtrim((string)App::parseEnv($settings->customBaseUrl), '/');
    }

    /**
     * Images are always sent as base64: a gateway may not be able to fetch URLs, and the retry with base64 depends on
     * OpenAI's error `type`, which a gateway may not send.
     *
     * @inheritdoc
     * @throws Exception
     */
    public function generateAltText(Asset $asset, ?int $siteId = null, bool $forceBase64 = false): string
    {
        return parent::generateAltText($asset, $siteId, true);
    }

    /**
     * @inheritdoc
     */
    protected function imageDetailLevel(): string
    {
        return 'low';
    }

    /**
     * No reasoning setting is sent, so each model uses its own default.
     *
     * @inheritdoc
     */
    protected function reasoningEffort(): string
    {
        return '';
    }
}
