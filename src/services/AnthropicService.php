<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\{App, Json};
use Exception;
use GuzzleHttp\Exception\{GuzzleException, RequestException};
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\api\{AnthropicRequest, AnthropicResponse};
use heavymetalavo\craftaialttext\models\Settings;

/**
 * Anthropic API Service
 *
 * Handles API interactions with the Anthropic Messages API to generate alt text.
 */
class AnthropicService extends ApiService
{
    protected string $apiKey;
    protected string $model;
    protected string $detailLevel;
    protected string $baseUrl = 'https://api.anthropic.com/v1/messages';
    protected string $providerLabel = 'Anthropic';

    /**
     * Initializes the service with the Anthropic API key, model and image detail level from the plugin settings.
     *
     * @param array $config Standard Yii component configuration. The parent constructor is called last,
     * per Yii convention, so that config is applied after the defaults set here.
     */
    public function __construct($config = [])
    {
        $this->applySettings(AiAltText::getInstance()->getSettings());
        parent::__construct($config);
    }

    /**
     * Reads the API key, model and image detail level from the plugin settings. Providers that speak the
     * Anthropic Messages API through another host override this to read their own settings.
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = App::parseEnv($settings->anthropicApiKey);
        $this->model = App::parseEnv($settings->anthropicModel);
        $this->detailLevel = $settings->anthropicImageDetailLevel;
    }

    /**
     * The headers sent with every request.
     */
    protected function requestHeaders(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ];
    }

    /**
     * Generates alt text using the Anthropic Messages API
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

        // After setTransform(), getMimeType() reflects the output format of the transform
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
            $imageUrl = null; // Clear URL so it's not sent in the payload
        }

        return $this->sendRequest($imageUrl, $imageSource, $mimeType, $asset, $siteId, $forceBase64);
    }

    /**
     * @throws Exception
     */
    private function sendRequest(?string $imageUrl, ?array $base64ImageSource, string $mimeType, Asset $asset, ?int $siteId, bool $forceBase64 = false): string
    {
        try {
            // Log the request intent for debugging
            Craft::info('Generating alt text with ' . $this->providerLabel . ' for asset: ' . $asset->filename . ' (' . ($imageUrl ?: 'base64 image') . ')', __METHOD__);

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
                throw new Exception("Invalid " . $this->providerLabel . " request: " . Json::encode($requestModel->getErrors()));
            }

            // Convert explicit request structure to array for the JSON payload
            $requestArray = $requestModel->toArray();

            $this->logRequest($this->baseUrl, $requestArray);

            $requestStartedAt = microtime(true);
            $response = $this->client->post($this->baseUrl, [
                'headers' => $this->requestHeaders(),
                'json' => $requestArray,
            ]);

            $responseBody = (string)$response->getBody();
            $this->logResponse($this->baseUrl, $response->getStatusCode(), $responseBody, microtime(true) - $requestStartedAt);
            
            $responseModel = (new AnthropicResponse())->setProviderLabel($this->providerLabel);
            if (!$responseModel->parseResponse($responseBody)) {
                throw new Exception($this->providerLabel . " API returned error: " . $responseModel->getErrorMessage());
            }

            return $responseModel->getText();
        } catch (GuzzleException $e) {
            // ConnectException (DNS, TLS, connect timeouts) is not a RequestException
            $errorResponse = ($e instanceof RequestException && $e->hasResponse())
                ? (string)$e->getResponse()->getBody()
                : $e->getMessage();
            Craft::error($this->providerLabel . " API Error: " . trim($errorResponse), __METHOD__);
            
            $responseModel = (new AnthropicResponse())->setProviderLabel($this->providerLabel);
            if ($responseModel->parseResponse($errorResponse) && $responseModel->hasError()) {
                throw new Exception($this->providerLabel . " API request failed parsed response: " . $responseModel->getErrorMessage());
            }

            // Try a fallback where if we have accessed the image before but the provider cannot access it, we can try again with the base64 encoded contents
            try {
                $decodedErrorResponse = Json::decode($errorResponse);
            } catch (\Throwable $jsonError) {
                $decodedErrorResponse = null;
            }
            $errorType = $decodedErrorResponse['error']['type'] ?? null;
            if ($errorType === 'invalid_request_error' && !$forceBase64 && !$base64ImageSource) {
                Craft::warning('Can access the asset URL, but the provider could not, forcing base64 fallback', __METHOD__);
                return $this->generateAltText($asset, $siteId, true);
            }

            // Langdock reports errors as a flat {"message": "..."} rather than Anthropic's error object
            if (is_string($decodedErrorResponse['message'] ?? null)) {
                throw new Exception($this->providerLabel . ' API request failed: ' . $decodedErrorResponse['message']);
            }

            throw new Exception($this->providerLabel . " API request failed error response: " . $errorResponse);
        }
    }
}
