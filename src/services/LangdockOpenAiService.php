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
 * Langdock serves OpenAI's Responses API on its own host, with a region (always eu) in the URL. Authentication is
 * a Bearer token, and it doesn't store responses, so `store` is left out.
 */
class LangdockOpenAiService extends ResponsesApiService
{
    protected string $providerLabel = 'Langdock';

    /**
     * @inheritdoc
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = App::parseEnv($settings->langdockApiKey);
        $this->model = App::parseEnv($settings->langdockModel);
        // Langdock hosts its OpenAI models in the EU region
        $this->baseUrl = 'https://api.langdock.com/openai/eu/v1';
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
            throw $this->withModelAuthorHint($e);
        }
    }

    /**
     * Wrong-model errors usually mean the Model Author doesn't match the model, so say so. The hint is only
     * added once, since a failed attempt can be retried through this method.
     */
    private function withModelAuthorHint(Exception $e): Exception
    {
        $hint = Craft::t('ai-alt-text', 'Check the model name, and that the Langdock Model Author matches it: Anthropic for models such as Claude, OpenAI for models such as GPT.');

        if (stripos($e->getMessage(), 'model') === false || str_contains($e->getMessage(), $hint)) {
            return $e;
        }

        return new Exception($e->getMessage() . ' ' . $hint, (int) $e->getCode(), $e);
    }

    /**
     * @inheritdoc
     */
    protected function imageDetailLevel(): string
    {
        return App::parseEnv(AiAltText::getInstance()->getSettings()->langdockImageInputDetailLevel) ?: 'low';
    }

    /**
     * @inheritdoc
     */
    protected function reasoningEffort(): string
    {
        return (string) App::parseEnv(AiAltText::getInstance()->getSettings()->langdockReasoningEffort);
    }
}
