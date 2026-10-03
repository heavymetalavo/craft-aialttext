<?php

namespace heavymetalavo\craftaialttext\services;

use craft\elements\Asset;
use craft\helpers\App;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
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
     * Reads the Langdock settings in place of the Anthropic ones. The constructor chain skips
     * AnthropicService::__construct(), which only reads the Anthropic settings, so that the component
     * config is still applied last, as in the other provider services.
     *
     * @param array $config Standard Yii component configuration.
     */
    public function __construct($config = [])
    {
        $settings = AiAltText::getInstance()->getSettings();
        $this->apiKey = App::parseEnv($settings->langdockApiKey);
        $this->model = App::parseEnv($settings->langdockModel);
        // Langdock has no image detail setting of its own, so use the plugin's default
        $this->detailLevel = (new Settings())->anthropicImageDetailLevel;
        $this->baseUrl = 'https://api.langdock.com/anthropic/' . App::parseEnv($settings->langdockRegion) . '/v1/messages';

        ApiService::__construct($config);
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
        return parent::generateAltText($asset, $siteId, true);
    }
}
