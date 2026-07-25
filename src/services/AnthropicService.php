<?php

namespace heavymetalavo\craftaialttext\services;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Json;
use Exception;
use GuzzleHttp\Exception\RequestException;
use heavymetalavo\craftaialttext\models\api\{AnthropicRequest, AnthropicResponse};
use Illuminate\Container\Attributes\Singleton;
use heavymetalavo\craftaialttext\AiAltText;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic API Service
 *
 * Handles API interactions with the Anthropic Messages API to generate alt text.
 */
#[Singleton]
class AnthropicService extends ApiService
{
    private string $apiKey;
    private string $model;
    private string $detailLevel;
    private string $baseUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct()
    {
        parent::__construct();
        $settings = AiAltText::settings();
        $this->apiKey = Env::parse($settings->anthropicApiKey);
        $this->model = Env::parse($settings->anthropicModel);
        $this->detailLevel = $settings->anthropicImageDetailLevel;
    }

    /**
     * Generates alt text using the Anthropic Messages API.
     *
     * @throws Exception
     */
    public function generateAltText(Asset $asset, ?int $siteId = null, bool $forceBase64 = false): string
    {
        if (!$this->validateImageSupport($asset)) {
            return '';
        }

        $targetDimension = match ($this->detailLevel) {
            'low' => 500,
            'medium' => 1000,
            'high' => 1568,
            default => 1000,
        };

        // Anthropic Vision: max long edge based on detail level, max 5MB payload, max ~1600 tokens
        $transformParams = $this->getVisionTransformParams($asset, maxLongEdge: $targetDimension, maxFileSizeMb: 5, maxTokens: 1600);

        if (!empty($transformParams)) {
            $asset->setTransform($transformParams);
        }

        $mimeType = $asset->getMimeType();

        if (!$this->isAcceptedMimeType($mimeType)) {
            throw new Exception("Asset transform produced unsupported MIME type: $mimeType. Supported formats are: " . implode(', ', self::ACCEPTED_MIME_TYPES));
        }

        $imageUrl = $asset->getUrl($transformParams, true);

        // If we have a URL, resolve root-relative URLs to the site base; leave absolute URLs as-is
        if (!empty($imageUrl)) {
            $imageUrl = $this->resolveAssetUrl($asset, $imageUrl);
        }

        $imageSource = null;

        // If no public URL is available, or base64 is forced
        if ($forceBase64 || empty($imageUrl) || !$asset->getVolume()->getFs()->hasUrls) {
            $base64Image = $this->getAssetBase64String($asset, $transformParams);
            $imageSource = [
                'type' => 'base64',
                'media_type' => $mimeType,
                'data' => $base64Image,
            ];
            $imageUrl = null;
        }

        return $this->sendRequest($imageUrl, $imageSource, $mimeType, $asset, $siteId, $forceBase64);
    }

    /**
     * @throws Exception
     */
    private function sendRequest(?string $imageUrl, ?array $base64ImageSource, string $mimeType, Asset $asset, ?int $siteId, bool $forceBase64 = false): string
    {
        try {
            Log::info('Anthropic API request initiated for asset: ' . $asset->filename . ' with image URL: ' . $imageUrl);

            $prompt = $this->resolvePrompt($asset, $siteId);

            $requestModel = new AnthropicRequest();
            $requestModel->model = $this->model;
            $requestModel->setSystem($prompt);
            $requestModel->setPrompt(self::GENERATE_TRIGGER);
            if ($base64ImageSource) {
                $requestModel->setImageSource($base64ImageSource);
            } elseif ($imageUrl) {
                $requestModel->setImageUrl($imageUrl);
            }

            if (!$requestModel->validate()) {
                throw new Exception("Invalid Anthropic request: " . Json::encode($requestModel->errors()->toArray()));
            }

            $requestArray = $requestModel->toArray();

            $response = $this->client->post($this->baseUrl, [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'json' => $requestArray,
            ]);

            $responseBody = (string)$response->getBody();

            $responseModel = new AnthropicResponse();
            if (!$responseModel->parseResponse($responseBody)) {
                throw new Exception("Anthropic API returned error: " . $responseModel->getErrorMessage());
            }

            return $responseModel->getText();

        } catch (RequestException $e) {
            $errorResponse = $e->hasResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();
            Log::error("Anthropic API Error: " . $errorResponse);

            $responseModel = new AnthropicResponse();
            if ($responseModel->parseResponse($errorResponse) && $responseModel->hasError()) {
                throw new Exception("Anthropic API request failed parsed response: " . $responseModel->getErrorMessage());
            }

            // Try a fallback where if we have accessed the image before but the provider cannot access it, we can try again with the base64 encoded contents
            try {
                $decodedErrorResponse = Json::decode($errorResponse);
            } catch (\Throwable $jsonError) {
                $decodedErrorResponse = null;
            }
            $errorType = $decodedErrorResponse['error']['type'] ?? null;
            if ($errorType === 'invalid_request_error' && !$forceBase64 && !$base64ImageSource) {
                Log::warning('Can access the asset URL, but the provider could not, forcing base64 fallback');
                return $this->generateAltText($asset, $siteId, true);
            }

            throw new Exception("Anthropic API request failed error response: " . $errorResponse);
        }
    }
}
