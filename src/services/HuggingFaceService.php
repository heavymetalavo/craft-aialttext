<?php

namespace heavymetalavo\craftaialttext\services;

use craft\elements\Asset;
use craft\helpers\App;
use Exception;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * Hugging Face API Service
 *
 * Hugging Face's Inference Providers router serves the Responses API (in beta) on its own host for open-weight models
 * run by providers such as Groq and Scaleway. A model is named `org/model`, with an optional `:provider` suffix to pick
 * the provider, or `:fastest` (the default) or `:cheapest`. Authentication is a Bearer Hugging Face token with the
 * "Make calls to Inference Providers" permission, and usage is billed at the providers' own prices.
 *
 * Its docs only show images sent as public URLs, and don't describe `detail`, `store` or its errors, see
 * https://huggingface.co/docs/inference-providers/guides/responses-api.
 */
class HuggingFaceService extends ResponsesApiService
{
    protected string $providerLabel = 'Hugging Face';

    /**
     * @inheritdoc
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = App::parseEnv($settings->huggingFaceApiKey);
        $this->model = App::parseEnv($settings->huggingFaceModel);
        $this->baseUrl = 'https://router.huggingface.co/v1';
    }

    /**
     * Images are always sent as base64, as OpenRouter's are: the retry with base64 that ResponsesApiService does when a
     * provider can't fetch the image URL depends on OpenAI's error `type`, which Hugging Face doesn't document.
     *
     * @inheritdoc
     * @throws Exception
     */
    public function generateAltText(Asset $asset, ?int $siteId = null, bool $forceBase64 = false): string
    {
        return parent::generateAltText($asset, $siteId, true);
    }

    /**
     * The models are open-weight models that don't document OpenAI's detail levels, so they're left to decide.
     *
     * @inheritdoc
     */
    protected function imageDetailLevel(): string
    {
        return 'auto';
    }

    /**
     * No reasoning setting is sent: the request model only sends one to OpenAI's reasoning models, and an empty effort
     * is left out, so each model uses its own default.
     *
     * @inheritdoc
     */
    protected function reasoningEffort(): string
    {
        return '';
    }
}
