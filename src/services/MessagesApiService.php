<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\Json;
use Exception;
use GuzzleHttp\Exception\{GuzzleException, RequestException};
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\api\{AnthropicRequest, AnthropicResponse};
use heavymetalavo\craftaialttext\models\Settings;

/**
 * Messages API Service
 *
 * The base for every provider that serves Anthropic's Messages API (`POST {baseUrl}/messages`): Anthropic itself and
 * hosts such as Langdock. It builds the request, sends it, reads the response and generates the alt text, as
 * ResponsesApiService does for the Responses API.
 *
 * A provider extends it to say where it lives (`baseUrl`), which settings it reads (`applySettings()`), how it
 * authenticates (`requestHeaders()`) and what to call itself in log lines and messages (`providerLabel`).
 *
 * Anthropic's errors carry `type: invalid_request_error` when it can't fetch an image URL, so the request is retried
 * with the image as base64. Langdock only takes base64, so LangdockAnthropicService sends it from the start.
 */
abstract class MessagesApiService extends ApiService
{
    /**
     * @var string The API key, read from the plugin settings by applySettings().
     */
    protected string $apiKey;

    /**
     * @var string The model name, as the provider names it, read from the plugin settings by applySettings().
     */
    protected string $model;

    /**
     * @var string The image detail level (`low`, `medium` or `high`), which picks the image's long edge.
     */
    protected string $detailLevel;

    /**
     * @var string The provider's base URL without a trailing slash, such as `https://api.anthropic.com/v1`. The request
     * goes to `{baseUrl}/messages`. Set by the provider's default or by applySettings().
     */
    protected string $baseUrl;

    /**
     * Initializes the service with the API key, model and image detail level from the plugin settings.
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
     * Reads the API key, model and image detail level from the plugin settings, and sets the base URL if it depends on them.
     */
    abstract protected function applySettings(Settings $settings): void;

    /**
     * The headers sent with every request, including the API key.
     *
     * @return array<string, string>
     */
    abstract protected function requestHeaders(): array;

    /**
     * Generates alt text for an asset using a vision model through the Messages API
     *
     * @param Asset $asset The asset to generate alt text for
     * @param int|null $siteId The site the alt text is for, which sets the language of the prompt's site variables
     * @param bool $forceBase64 Send the image as base64 instead of a URL the provider has to fetch
     * @throws Exception If the request is invalid or the provider returns an error
     */
    public function generateAltText(Asset $asset, ?int $siteId = null, bool $forceBase64 = false): string
    {
        if (!$this->validateImageSupport($asset)) {
            return '';
        }

        // Set by flattenTransparency() if this image's transparent areas are filled
        $this->transparencyFill = null;

        $targetDimension = match ($this->detailLevel) {
            'low' => 500,
            'medium' => 1000,
            'high' => 1568,
            default => 1000,
        };

        // Claude's vision: max long edge based on detail level, max 5MB payload, max ~1600 tokens
        $transformParams = $this->getVisionTransformParams($asset, maxLongEdge: $targetDimension, maxFileSizeMb: 5, maxTokens: 1600);

        // Checked before setTransform(), after which the asset reports the transform's MIME type instead of GIF or SVG
        $isAnimatedGif = $this->isAnimatedGif($asset);
        $isSvg = AiAltText::getInstance()->aiAltTextService->isSvg($asset);
        // Transparent images are flattened here, as models don't reliably see transparency, see getFlattenedBase64()
        $flattenedBase64 = ($isAnimatedGif || $isSvg) ? null : $this->getFlattenedBase64($asset, $transformParams);

        if (!empty($transformParams)) {
            $asset->setTransform($transformParams);
        }

        // The MIME type of the image sent: a JPG for a filled image and a PNG for an SVG, as both are rendered here
        // (an SVG can't use getMimeType(), which stays image/svg+xml when Craft's transformSvgs is off). Anything
        // else uses getMimeType(): the transform's output format after setTransform(), or the original's without one
        $mimeType = match (true) {
            $flattenedBase64 !== null => 'image/jpeg',
            $isSvg => 'image/png',
            default => $asset->getMimeType(),
        };
        
        if (!$this->isAcceptedMimeType($mimeType)) {
            throw new Exception("Asset transform produced unsupported MIME type: $mimeType. Supported formats are: " . implode(', ', self::ACCEPTED_MIME_TYPES));
        }
        
        // Animated GIFs, SVGs and transparent images are rendered here and sent as base64, see getFirstFrameBase64(),
        // getSvgBase64() and getFlattenedBase64()
        $imageUrl = ($isAnimatedGif || $isSvg || $flattenedBase64 !== null) ? null : $asset->getUrl($transformParams, true);

        // If we have a URL, resolve root-relative URLs to the site base; leave absolute URLs as-is
        if (!empty($imageUrl)) {
            $imageUrl = $this->resolveAssetUrl($asset, $imageUrl);
        }

        $imageSource = null;

        // If no public URL is available, or base64 is forced
        if ($forceBase64 || empty($imageUrl) || !$asset->getVolume()->getFs()->hasUrls) {
            $base64Image = match (true) {
                $flattenedBase64 !== null => $flattenedBase64,
                $isAnimatedGif => $this->getFirstFrameBase64($asset, $transformParams),
                $isSvg => $this->getSvgBase64($asset, $transformParams),
                default => $this->getAssetBase64String($asset, $transformParams),
            };
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
            $requestModel->setPrompt($this->generateTrigger());
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

            $url = $this->baseUrl . '/messages';
            $this->logRequest($url, $requestArray);

            $requestStartedAt = microtime(true);
            $response = $this->client->post($url, [
                'headers' => $this->requestHeaders(),
                'json' => $requestArray,
            ]);

            $responseBody = (string)$response->getBody();
            $this->logResponse($url, $response->getStatusCode(), $responseBody, microtime(true) - $requestStartedAt);
            
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
