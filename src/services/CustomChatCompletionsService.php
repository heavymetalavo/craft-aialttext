<?php

namespace heavymetalavo\craftaialttext\services;

use craft\elements\Asset;
use craft\helpers\App;
use Exception;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * Custom Chat Completions API Service
 *
 * A gateway or host at a base URL of the site's choosing that takes the Chat Completions API request unchanged
 * (`POST {baseUrl}/chat/completions`), for the Custom endpoint provider with the Chat Completions API format.
 * Authentication is a Bearer token.
 */
class CustomChatCompletionsService extends ChatCompletionsApiService
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
     * Images are always sent as base64: a gateway may not be able to fetch URLs, and one that can't may answer that it
     * sees no image, with no error, rather than failing, as Hugging Face's models did in testing.
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
     * Images are always base64 at the `low` detail level, so they're scaled to a conservative 1024px and 5MB, as a
     * gateway may forward them to a model with a 5MB limit, such as Claude.
     *
     * @inheritdoc
     */
    protected function visionTransformParams(Asset $asset): array
    {
        return $this->getVisionTransformParams($asset, maxLongEdge: 1024, maxFileSizeMb: 5);
    }
}
