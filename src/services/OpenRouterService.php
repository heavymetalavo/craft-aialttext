<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\App;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\api\OpenAiResponse;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * OpenRouter API Service
 *
 * OpenRouter serves the OpenAI Responses API on its own host for models from many vendors, which it names
 * with the vendor in front (`anthropic/claude-haiku-5.5`, `openai/gpt-6-luna`). Authentication is the same
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
     * OpenRouter reports a failure at the model's own provider as just "Provider returned error" and keeps the cause
     * (a temporary rate limit, an unsupported image, ...) in the error's metadata, so put the cause in the message.
     */
    protected function sendRequest(array $requestData): OpenAiResponse
    {
        $response = parent::sendRequest($requestData);
        $metadata = $response->error['details']['metadata'] ?? null;
        $raw = $metadata['raw'] ?? null;

        if ($response->hasError() && is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $cause = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['message'] ?? $raw) : $raw;
            $provider = $metadata['provider_name'] ?? null;

            $response->setError(
                $response->error['message'] . ': ' . mb_strimwidth((string) $cause, 0, 300, '…') . ($provider ? " ($provider)" : ''),
                $response->error['details']
            );
        }

        return $response;
    }

    /**
     * The parent words its errors as OpenAI's, which would read as an OpenAI problem, so name OpenRouter. Wrong-model
     * errors usually mean the name isn't an OpenRouter one, so say how it should look.
     */
    private function withOpenRouterWording(Exception $e): Exception
    {
        $message = preg_replace('/^OpenAI API (error|request failed)/', 'OpenRouter $1', $e->getMessage());

        if (stripos($message, 'model') !== false) {
            $message .= ' ' . Craft::t('ai-alt-text', "Check the model's name on OpenRouter, which starts with the vendor (for example `anthropic/claude-haiku-5.5` or `openai/gpt-6-luna`), and that the model accepts image input.");
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
