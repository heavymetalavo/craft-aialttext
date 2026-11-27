<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\{App, Json};
use Exception;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * Hugging Face API Service
 *
 * Hugging Face's Inference Providers router serves the Chat Completions API on its own host for open-weight models
 * run by inference providers such as DeepInfra and Together, using OpenRouter's routing to pick one per request and retry
 * on another if it fails. A model is named `org/model`, with an optional `:provider` suffix to pick the provider, or
 * `:fastest` or `:cheapest`; without one, the routing weighs price, speed and reliability. The Hugging Face Routing
 * setting holds that suffix. Authentication is a Bearer Hugging Face token with the "Make calls to Inference Providers"
 * permission, and usage is billed at the providers' own prices.
 *
 * It serves the Responses API too, but that is in beta and rejects a request body over about 100 KB (HTTP 413 with
 * `PayloadTooLargeError`), which a base64 image scaled for a vision model passes. Chat Completions took 16 MB when tried.
 */
class HuggingFaceService extends ChatCompletionsApiService
{
    protected string $providerLabel = 'Hugging Face';

    /**
     * @var string The router's public list of the models it serves, with each one's inference providers and prices, which needs no token
     */
    private const MODELS_URL = 'https://router.huggingface.co/v1/models';

    /**
     * @var string Bumped when the shape of the cached suggestions changes, so an old cache isn't read as the new one
     */
    private const SUGGESTIONS_CACHE_KEY = 'ai-alt-text:huggingface-vision-models:2';

    /**
     * Suggestions for the Hugging Face Model setting: the models the router serves that take images and give only text,
     * e.g. `Qwen/Qwen3.8-27B` with the hint `from $0.04/M input tokens, 16 inference providers`, the price being the
     * cheapest live inference provider's. Each also lists its inference providers, cheapest first, for the Hugging Face
     * Routing setting, e.g. `deepinfra` with the hint `DeepInfra, $0.098/M input tokens, 185 tokens/s`. Cached for a day,
     * as the settings page fetches them. If the router can't be reached there are none, and it's tried again after 10
     * minutes, so the page never waits more than 5 seconds for it.
     *
     * @return array{label: string, data: array{name: string, hint: string, providers: array{name: string, hint: string}[]}[]}[] In the format Craft's autosuggest field takes
     */
    public function visionModelSuggestions(): array
    {
        $cache = Craft::$app->getCache();
        $models = $cache->get(self::SUGGESTIONS_CACHE_KEY);

        if ($models === false) {
            try {
                $data = Json::decode((string)Craft::createGuzzleClient(['timeout' => 5])->get(self::MODELS_URL)->getBody())['data'] ?? [];
                $models = [];

                foreach ($data as $model) {
                    if (!is_string($model['id'] ?? null) || $model['id'] === '') {
                        continue;
                    }

                    if (!in_array('image', $model['architecture']['input_modalities'] ?? [], true) || ($model['architecture']['output_modalities'] ?? []) !== ['text']) {
                        continue;
                    }

                    $providers = array_filter($model['providers'] ?? [], fn($p) => ($p['status'] ?? 'live') === 'live');

                    if (!$providers) {
                        continue;
                    }

                    // Prices are already per million tokens
                    $prices = array_filter(array_map(fn($p) => (float)($p['pricing']['input'] ?? 0), $providers), fn($price) => $price > 0);
                    $price = $prices ? min($prices) : 0;
                    $count = count($providers);
                    $models[] = [
                        'name' => $model['id'],
                        'hint' => ($price > 0 ? ($count > 1 ? 'from ' : '') . $this->formatPrice($price) . '/M input tokens, ' : '')
                            . $count . ($count === 1 ? ' inference provider' : ' inference providers'),
                        'providers' => $this->providerSuggestions($providers),
                    ];
                }

                usort($models, fn(array $a, array $b) => strcasecmp($a['name'], $b['name']));
                $cache->set(self::SUGGESTIONS_CACHE_KEY, $models, 86400);
            } catch (\Throwable $e) {
                Craft::warning("Couldn't load Hugging Face's vision models for the model suggestions: " . $e->getMessage(), __METHOD__);
                $models = [];
                $cache->set(self::SUGGESTIONS_CACHE_KEY, $models, 600);
            }
        }

        return $models ? [['label' => Craft::t('ai-alt-text', 'Hugging Face vision models'), 'data' => $models]] : [];
    }

    /**
     * A model's inference providers as suggestions for the Hugging Face Routing setting, cheapest first, e.g. `deepinfra`
     * with the hint `DeepInfra, $0.098/M input tokens, 185 tokens/s`.
     *
     * @param array[] $providers The model's live providers from the router's models API
     * @return array{name: string, hint: string}[]
     */
    private function providerSuggestions(array $providers): array
    {
        $suggestions = [];

        foreach ($providers as $provider) {
            if (!is_string($provider['provider'] ?? null) || $provider['provider'] === '') {
                continue;
            }

            $price = (float)($provider['pricing']['input'] ?? 0);
            $throughput = (float)($provider['throughput'] ?? 0);
            $suggestions[] = [
                'price' => $price,
                'name' => $provider['provider'],
                'hint' => implode(', ', array_filter([
                    is_string($provider['provider_name'] ?? null) ? $provider['provider_name'] : $provider['provider'],
                    $price > 0 ? $this->formatPrice($price) . '/M input tokens' : (($provider['is_free'] ?? false) ? 'free' : null),
                    $throughput > 0 ? round($throughput) . ' tokens/s' : null,
                ])),
            ];
        }

        usort($suggestions, fn(array $a, array $b) => [$a['price'], $a['name']] <=> [$b['price'], $b['name']]);

        return array_map(fn(array $s) => ['name' => $s['name'], 'hint' => $s['hint']], $suggestions);
    }

    /**
     * A price per million tokens as dollars, without trailing zeros, e.g. `$0.098`.
     */
    private function formatPrice(float $price): string
    {
        return '$' . rtrim(rtrim(number_format($price, $price < 0.001 ? 6 : 3), '0'), '.');
    }

    /**
     * The model is named with the Hugging Face Routing setting as its `:` suffix, e.g. `deepseek-ai/DeepSeek-V4.1-Flash-1017:cheapest`.
     * A model that already has a suffix is used as it is.
     *
     * @inheritdoc
     */
    protected function applySettings(Settings $settings): void
    {
        $model = (string) App::parseEnv($settings->huggingFaceModel);
        $routing = ltrim(trim((string) App::parseEnv($settings->huggingFaceRouting)), ':');

        // An undefined environment variable parses to null, which would stop the settings page loading. getConfigurationError() reports a missing API key or model
        $this->apiKey = (string) App::parseEnv($settings->huggingFaceApiKey);
        $this->model = $model !== '' && $routing !== '' && !str_contains($model, ':') ? $model . ':' . $routing : $model;
        $this->baseUrl = 'https://router.huggingface.co/v1';
    }

    /**
     * Images are always sent as base64. Hugging Face doesn't say what its errors look like when an image URL can't be
     * fetched, and in testing some models (GLM-5.3-Flash) answered "I don't see any image" with HTTP 200 and no error
     * instead of failing, which would have been saved as the alt text.
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
}
