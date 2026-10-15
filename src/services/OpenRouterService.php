<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\App;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * OpenRouter API Service
 *
 * OpenRouter serves the OpenAI Responses API on its own host for models from many vendors, which it names
 * with the vendor in front (`anthropic/claude-haiku-5.5`, `openai/gpt-5-nano`). Authentication is the same
 * Bearer token OpenAI uses.
 */
class OpenRouterService extends OpenAiService
{
    /**
     * Reads the OpenRouter settings in place of the OpenAI ones.
     *
     * @inheritdoc
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = App::parseEnv($settings->openRouterApiKey);
        $this->model = App::parseEnv($settings->openRouterModel);
        $this->baseUrl = 'https://openrouter.ai/api/v1';
    }

    /**
     * OpenRouter's errors carry no `type`, so the retry with base64 that OpenAiService does when a provider
     * can't fetch the image URL never triggers. Always sending base64 works for every model and provider.
     *
     * @inheritdoc
     * @throws Exception
     */
    public function generateAltText(Asset $asset, ?int $siteId = null, bool $forceBase64 = false): string
    {
        try {
            return parent::generateAltText($asset, $siteId, true);
        } catch (Exception $e) {
            throw $this->withOpenRouterWording($e);
        }
    }

    /**
     * The parent words its errors as OpenAI's, which would read as an OpenAI problem, so name OpenRouter. Wrong-model
     * errors usually mean the name isn't an OpenRouter one, so say how it should look.
     */
    private function withOpenRouterWording(Exception $e): Exception
    {
        $message = preg_replace('/^OpenAI API (error|request failed)/', 'OpenRouter $1', $e->getMessage());

        if (stripos($message, 'model') !== false) {
            $message .= ' ' . Craft::t('ai-alt-text', "Check the model's name on OpenRouter, which starts with the vendor (for example `anthropic/claude-haiku-5.5` or `openai/gpt-5-nano`), and that the model accepts image input.");
        }

        return $message === $e->getMessage() ? $e : new Exception($message, (int) $e->getCode(), $e);
    }

    /**
     * Uses the OpenRouter Image Detail Level setting, not the OpenAI one.
     *
     * @inheritdoc
     */
    protected function imageDetailLevel(): string
    {
        return App::parseEnv(AiAltText::getInstance()->getSettings()->openRouterImageInputDetailLevel) ?: 'low';
    }

    /**
     * Uses the OpenRouter Reasoning Effort setting, not the OpenAI one.
     *
     * @inheritdoc
     */
    protected function reasoningEffort(): string
    {
        return (string) App::parseEnv(AiAltText::getInstance()->getSettings()->openRouterReasoningEffort);
    }
}
