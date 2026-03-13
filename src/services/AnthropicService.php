<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\Json;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\api\AnthropicRequest;
use heavymetalavo\craftaialttext\models\api\AnthropicResponse;

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

    public function __construct()
    {
        parent::__construct();
        $this->apiKey = App::parseEnv(AiAltText::getInstance()->getSettings()->anthropicApiKey);
        $this->model = App::parseEnv(AiAltText::getInstance()->getSettings()->anthropicModel);
        $this->detailLevel = AiAltText::getInstance()->getSettings()->anthropicImageDetailLevel;
    }



    /**
     * Generates alt text using the Anthropic Messages API
     */
    public function generateAltText(Asset $asset, ?int $siteId = null): string
    {
        $this->validateImageSupport($asset);

        $targetDimension = match ($this->detailLevel) {
            'very_low' => 300,
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

        // If we have a URL, check if it's accessible remotely
        if (!empty($imageUrl)) {
            if (!$this->isUrlAccessible($imageUrl)) {
                Craft::warning('Asset URL is not accessible remotely: ' . $imageUrl, __METHOD__);
                $imageUrl = null; // Reset to null to trigger base64 encoding
            }
        }

        $imageSource = null;

        // If no public URL is available or URL is not accessible, fall back to base64 encoding
        if (empty($imageUrl) || !$asset->getVolume()->getFs()->hasUrls) {
            $base64Image = $this->getAssetBase64String($asset, $imageUrl, $transformParams);
            $imageSource = [
                'type' => 'base64',
                'media_type' => $transformMimeType,
                'data' => $base64Image,
            ];
            return $this->sendRequest(null, $imageSource, $transformMimeType, $asset, $siteId);
        }

        return $this->sendRequest($imageUrl, null, $transformMimeType, $asset, $siteId);
    }

    private function sendRequest(?string $imageUrl, ?array $base64ImageSource, string $mimeType, Asset $asset, ?int $siteId): string
    {
        try {
            // Log the request intent for debugging
            Craft::info('Anthropic API request initiated for asset: ' . $asset->filename, __METHOD__);

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
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $errorResponse = $e->hasResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();
            Craft::error("Anthropic API Error: " . $errorResponse, __METHOD__);
            
            $responseModel = new AnthropicResponse();
            if ($responseModel->parseResponse($errorResponse) && $responseModel->hasError()) {
                throw new Exception("Anthropic API request failed: " . $responseModel->getErrorMessage());
            }
            throw new Exception("Anthropic API request failed: " . $errorResponse);
        }
    }
}
