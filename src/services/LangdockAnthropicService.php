<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\App;
use Exception;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * Langdock Anthropic API Service
 *
 * Langdock serves the Anthropic Messages API on its own host. It differs from Anthropic's in three
 * ways: the URL carries a region, the API key is a Bearer token, and images must be sent as base64.
 */
class LangdockAnthropicService extends AnthropicService
{
    /**
     * Reads the Langdock settings in place of the Anthropic ones.
     *
     * @inheritdoc
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = App::parseEnv($settings->langdockApiKey);
        $this->model = App::parseEnv($settings->langdockModel);
        // Langdock has no image detail setting of its own, so use the plugin's default
        $this->detailLevel = Settings::DEFAULT_ANTHROPIC_IMAGE_DETAIL_LEVEL;
        $this->baseUrl = 'https://api.langdock.com/anthropic/' . App::parseEnv($settings->langdockRegion) . '/v1/messages';
    }

    /**
     * @inheritdoc
     */
    protected function requestHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'content-type' => 'application/json',
        ];
    }

    /**
     * Langdock only accepts base64 images (a URL source is rejected with a 400), so always force it.
     *
     * @inheritdoc
     * @throws Exception
     */
    public function generateAltText(Asset $asset, ?int $siteId = null, bool $forceBase64 = false): string
    {
        try {
            return parent::generateAltText($asset, $siteId, true);
        } catch (Exception $e) {
            throw $this->withModelFamilyHint($e);
        }
    }

    /**
     * Wrong-model errors usually mean the Model Family or Region doesn't suit the model, so say so. The hint is only
     * added once, since a failed attempt can be retried through this method.
     */
    private function withModelFamilyHint(Exception $e): Exception
    {
        $hint = Craft::t('ai-alt-text', 'Check the model name, and that the Langdock Model Family and Region suit it: Claude (Anthropic API) for Claude models, GPT (OpenAI API) for GPT models. Not every model is available in every region.');

        if (stripos($e->getMessage(), 'model') === false || str_contains($e->getMessage(), $hint)) {
            return $e;
        }

        return new Exception($e->getMessage() . ' ' . $hint, (int) $e->getCode(), $e);
    }
}
