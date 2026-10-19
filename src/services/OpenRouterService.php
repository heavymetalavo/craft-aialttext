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
 * OpenRouter serves the Responses API on its own host for models from many vendors, which it names with the vendor
 * in front (`anthropic/claude-haiku-5.5`, `openai/gpt-6-luna`). Authentication is a Bearer token, and its stateless
 * API leaves out `store`.
 */
class OpenRouterService extends ResponsesApiService
{
    protected string $providerLabel = 'OpenRouter';

    /**
     * @inheritdoc
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = App::parseEnv($settings->openRouterApiKey);
        $this->model = App::parseEnv($settings->openRouterModel);
        $this->baseUrl = 'https://openrouter.ai/api/v1';
    }

    /**
     * OpenRouter's errors carry no `type`, so the retry with base64 that ResponsesApiService does when a provider
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
     * Claude models (`anthropic/claude-…`, the `~anthropic/…` latest aliases, or a bare `claude-…` name, which OpenRouter also accepts) are scaled as AnthropicService scales them (long edge by detail level, 5MB, about 1,600 tokens), since
     * OpenAI's sizing sends them larger images than they need and costs about 15 to 20% more. The OpenRouter Image Detail
     * Level picks the size: `low` 500px, `auto` 1000px, `high` and `original` 1568px. Other models are scaled as OpenAI's are.
     *
     * @inheritdoc
     */
    protected function visionTransformParams(Asset $asset): array
    {
        if (!preg_match('#^~?(anthropic/)?claude#i', $this->model)) {
            return parent::visionTransformParams($asset);
        }

        $longEdge = match ($this->imageDetailLevel()) {
            'high', 'original' => 1568,
            'auto' => 1000,
            default => 500,
        };

        return $this->getVisionTransformParams($asset, maxLongEdge: $longEdge, maxFileSizeMb: 5, maxTokens: 1600);
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
     * Errors about the model itself (an unknown name, or one that can't read images) get a note on how OpenRouter
     * names models. Other errors that merely mention a model, such as an account data policy block, don't.
     */
    private function withOpenRouterWording(Exception $e): Exception
    {
        $message = $e->getMessage();

        if (preg_match('/not a valid model|invalid model|model .*(not found|does not exist)|no endpoints found/i', $message)) {
            $message .= ' ' . Craft::t('ai-alt-text', "Check the model's name on OpenRouter, which starts with the vendor (for example `anthropic/claude-haiku-5.5` or `openai/gpt-6-luna`), and that the model accepts image input.");
        }

        return $message === $e->getMessage() ? $e : new Exception($message, (int) $e->getCode(), $e);
    }

    /**
     * @inheritdoc
     */
    protected function imageDetailLevel(): string
    {
        return App::parseEnv(AiAltText::getInstance()->getSettings()->openRouterImageInputDetailLevel) ?: 'low';
    }

    /**
     * @inheritdoc
     */
    protected function reasoningEffort(): string
    {
        return (string) App::parseEnv(AiAltText::getInstance()->getSettings()->openRouterReasoningEffort);
    }
}
