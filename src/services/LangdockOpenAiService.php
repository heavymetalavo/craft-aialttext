<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\App;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * Langdock OpenAI API Service
 *
 * Langdock serves the OpenAI Responses API on its own host, with a region in the URL. Authentication is
 * the same Bearer token OpenAI uses.
 */
class LangdockOpenAiService extends OpenAiService
{
    /**
     * Reads the Langdock settings in place of the OpenAI ones.
     *
     * @inheritdoc
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = App::parseEnv($settings->langdockApiKey);
        $this->model = App::parseEnv($settings->langdockModel);
        $this->baseUrl = 'https://api.langdock.com/openai/' . App::parseEnv($settings->langdockRegion) . '/v1';
    }

    /**
     * @inheritdoc
     * @throws Exception
     */
    public function generateAltText(Asset $asset, ?int $siteId = null, bool $forceBase64 = false): string
    {
        try {
            return parent::generateAltText($asset, $siteId, $forceBase64);
        } catch (Exception $e) {
            throw $this->withModelFamilyHint($e);
        }
    }

    /**
     * Wrong-model errors usually mean the Model Family doesn't match the model, so say so. The hint is only
     * added once, since a failed attempt can be retried through this method.
     */
    private function withModelFamilyHint(Exception $e): Exception
    {
        $hint = Craft::t('ai-alt-text', 'Check the model name, and that the Langdock Model Family matches it: Claude (Anthropic API) for Claude models, GPT (OpenAI API) for GPT models.');

        if (stripos($e->getMessage(), 'model') === false || str_contains($e->getMessage(), $hint)) {
            return $e;
        }

        return new Exception($e->getMessage() . ' ' . $hint, (int) $e->getCode(), $e);
    }

    /**
     * Uses the Langdock Image Detail Level setting, not the OpenAI one.
     *
     * @inheritdoc
     */
    protected function imageDetailLevel(): string
    {
        return App::parseEnv(AiAltText::getInstance()->getSettings()->langdockImageInputDetailLevel) ?: 'low';
    }

    /**
     * Uses the Langdock Reasoning Effort setting, not the OpenAI one.
     *
     * @inheritdoc
     */
    protected function reasoningEffort(): string
    {
        return (string) App::parseEnv(AiAltText::getInstance()->getSettings()->langdockReasoningEffort);
    }
}
