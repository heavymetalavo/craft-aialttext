<?php

namespace heavymetalavo\craftaialttext\services;

use craft\elements\Asset;
use craft\helpers\App;
use Exception;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * Custom Messages API Service
 *
 * A gateway at a base URL of the site's choosing that takes Anthropic's Messages API request unchanged
 * (`POST {baseUrl}/messages`), for the Custom endpoint provider with the Messages API format.
 */
class CustomMessagesService extends MessagesApiService
{
    protected string $providerLabel = 'Custom endpoint';

    /**
     * @inheritdoc
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = App::parseEnv($settings->customApiKey);
        $this->model = App::parseEnv($settings->customModel);
        $this->detailLevel = Settings::DEFAULT_ANTHROPIC_IMAGE_DETAIL_LEVEL;
        $this->baseUrl = rtrim((string)App::parseEnv($settings->customBaseUrl), '/');
    }

    /**
     * The key is sent both as Anthropic sends it (`x-api-key`) and as a Bearer token, as gateways differ (Langdock
     * takes only a Bearer token), with Anthropic's version header.
     *
     * @inheritdoc
     */
    protected function requestHeaders(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'Authorization' => 'Bearer ' . $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ];
    }

    /**
     * Images are always sent as base64: a gateway may not be able to fetch URLs, and some only take base64.
     *
     * @inheritdoc
     * @throws Exception
     */
    public function generateAltText(Asset $asset, ?int $siteId = null, bool $forceBase64 = false): string
    {
        return parent::generateAltText($asset, $siteId, true);
    }
}
