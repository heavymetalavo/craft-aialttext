<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\{App, Json};
use Exception;
use GuzzleHttp\Exception\RequestException;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\api\{AnthropicRequest, AnthropicResponse};

/**
 * Anthropic API Service
 *
 * Handles API interactions with the Anthropic Messages API to generate alt text.
 */
class AnthropicService extends ApiService
{
    private string $apiKey;
    private string $model;
    private string $detailLevel;
    private string $baseUrl = 'https://api.anthropic.com/v1/messages';
    private bool $hasFallbackRan = false;

    public function __construct()
    {
        parent::__construct();
        $plugin = AiAltText::getInstance();
        $this->apiKey = App::parseEnv($plugin->getSettings()->anthropicApiKey);
        $this->model = App::parseEnv($plugin->getSettings()->anthropicModel);
        $this->detailLevel = $plugin->getSettings()->anthropicImageDetailLevel;
    }

    /**
     * Generates alt text using the Anthropic Messages API
     */
    public function generateAltText(Asset $asset, ?int $siteId = null): string
    {
        $this->validateImageSupport($asset);

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

        // Output mime-type
        $transformMimeType = $asset->getMimeType($transformParams);
        
        if (!$this->isAcceptedMimeType($transformMimeType)) {
            throw new Exception("Asset transform produced unsupported MIME type: $transformMimeType. Supported formats are: " . implode(', ', self::ACCEPTED_MIME_TYPES));
        }
        
        $imageUrl = $asset->getUrl($transformParams, true);

        // If we have a URL, check if it's accessible remotely (resolve root-relative URLs to the site base; leave absolute URLs as-is)
        if (!empty($imageUrl)) {
            $imageUrl = $this->resolveAssetUrl($asset, $imageUrl);
            
            if (!$this->forceBase64 && !$this->isUrlAccessible($imageUrl)) {
                Craft::warning('Asset URL is not accessible locally: ' . $imageUrl, __METHOD__);
                $this->forceBase64 = true;
            }
        }

        $imageSource = null;

        // If no public URL is available, or URL is not accessible locally, or base64 is forced
        if ($this->forceBase64 || empty($imageUrl) || !$asset->getVolume()->getFs()->hasUrls) {
            $base64Image = $this->getAssetBase64String($asset, $imageUrl, $transformParams);
            $imageSource = [
                'type' => 'base64',
                'media_type' => $transformMimeType,
                'data' => $base64Image,
            ];
            $imageUrl = null; // Clear URL so it's not sent in the payload
        }

        return $this->sendRequest($imageUrl, $imageSource, $transformMimeType, $asset, $siteId);
    }

    private function sendRequest(?string $imageUrl, ?array $base64ImageSource, string $mimeType, Asset $asset, ?int $siteId): string
    {
        try {
            // Log the request intent for debugging
            Craft::info('Anthropic API request initiated for asset: ' . $asset->filename . ' with image URL: ' . $imageUrl, __METHOD__);

            $promptTemplate = App::parseEnv(AiAltText::getInstance()->getSettings()->prompt);

            $prompt = preg_replace_callback('/{asset\.(.*?)}/', function ($matches) use ($asset) {
                return $asset->{$matches[1]};
            }, $promptTemplate);

            $site = Craft::$app->getSites()->getSiteById($siteId);
            $prompt = preg_replace_callback('/{site\.(.*?)}/', function ($matches) use ($site) {
                return $site->{$matches[1]};
            }, $prompt);

            $requestModel = new AnthropicRequest();
            $requestModel->model = $this->model;
            $requestModel->setPrompt($prompt);
            if ($base64ImageSource) {
                $requestModel->setImageSource($base64ImageSource);
            } elseif ($imageUrl) {
                $requestModel->setImageUrl($imageUrl);
            }

            if (!$requestModel->validate()) {
                throw new Exception("Invalid Anthropic request: " . Json::encode($requestModel->getErrors()));
            }

            // Convert explicit request structure to array for the JSON payload
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
            Craft::error("Anthropic API Error: " . $errorResponse, __METHOD__);
            
            $responseModel = new AnthropicResponse();
            if ($responseModel->parseResponse($errorResponse) && $responseModel->hasError()) {
                throw new Exception("Anthropic API request failed parsed response: " . $responseModel->getErrorMessage());
            }

            // Try a fallback where if we have accessed the image before but the provider cannot access it, we can try again with the base64 encoded contents
            $decodedErrorResponse = Json::decode($errorResponse);
            if ($decodedErrorResponse['error']['type'] === 'invalid_request_error' && !$this->hasFallbackRan && !$base64ImageSource) {
                $this->hasFallbackRan = true;
                $this->forceBase64 = true;
                Craft::warning('Can access the asset URL, but the provider could not, forcing base64 fallback', __METHOD__);
                return $this->generateAltText($asset, $siteId);
            }

            throw new Exception("Anthropic API request failed error response: " . $errorResponse);
        }
    }
}
