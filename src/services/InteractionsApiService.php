<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\Json;
use Exception;
use GuzzleHttp\Exception\RequestException;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\errors\RateLimitException;
use heavymetalavo\craftaialttext\models\api\InteractionsResponse;
use heavymetalavo\craftaialttext\models\Settings;

/**
 * Interactions API Service
 *
 * The base for every provider that serves Google's Interactions API (`POST {baseUrl}/interactions`), which Google
 * recommends for all new projects over its older `generateContent` API. It builds the request, sends it, reads the
 * response and generates the alt text, as ResponsesApiService does for the Responses API.
 *
 * A provider extends it to say where it lives (`baseUrl`), which settings it reads (`applySettings()`), how it
 * authenticates (`requestHeaders()`) and what to call itself in log lines and messages (`providerLabel`).
 *
 * Images are always sent as base64 in the request, so there is no URL fetch to fall back from.
 */
abstract class InteractionsApiService extends ApiService
{
    /**
     * @var int The image's long edge in pixels. Gemini reads a large image as a fixed number of tokens whatever its size,
     * and 1024px is plenty to describe a photo.
     */
    private const MAX_LONG_EDGE = 1024;

    /**
     * @var string The API key, read from the plugin settings by applySettings().
     */
    protected string $apiKey;

    /**
     * @var string The model name, as the provider names it, read from the plugin settings by applySettings().
     */
    protected string $model;

    /**
     * @var string The provider's base URL without a trailing slash, such as `https://generativelanguage.googleapis.com/v1beta`.
     * The request goes to `{baseUrl}/interactions`.
     */
    protected string $baseUrl;

    /**
     * Initializes the service with the API key and model from the plugin settings.
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
     * Reads the API key and model from the plugin settings.
     */
    abstract protected function applySettings(Settings $settings): void;

    /**
     * The headers sent with every request, including the API key.
     *
     * @return array<string, string>
     */
    abstract protected function requestHeaders(): array;

    /**
     * Gemini doesn't list GIF among the image types it reads, so a GIF is converted to a JPG like the other
     * unsupported types.
     *
     * @inheritdoc
     */
    protected function isAcceptedMimeType(string $mimeType): bool
    {
        return $mimeType !== 'image/gif' && parent::isAcceptedMimeType($mimeType);
    }

    /**
     * Sends a request to the Interactions API
     *
     * @param array $requestData The request data to send
     * @return InteractionsResponse The response, which carries the error if the call failed. This method doesn't throw.
     */
    protected function sendRequest(array $requestData): InteractionsResponse
    {
        $requestStartedAt = null;

        try {
            $url = $this->baseUrl . '/interactions';
            $this->logRequest($url, $requestData);

            $requestStartedAt = microtime(true);
            $response = $this->client->post($url, [
                'headers' => $this->requestHeaders(),
                'json' => $requestData,
            ]);

            $responseBody = (string)$response->getBody();
            $this->logResponse($url, $response->getStatusCode(), $responseBody, microtime(true) - $requestStartedAt);

            $responseModel = (new InteractionsResponse())->setProviderLabel($this->providerLabel);
            if (!$responseModel->parseResponse($responseBody)) {
                if (!$responseModel->hasError()) {
                    $responseModel->setError('Failed to parse ' . $this->providerLabel . ' API response');
                }
                Craft::warning('Response parsing failed: ' . $responseModel->getErrorMessage(), __METHOD__);
            }

            return $responseModel;
        } catch (Exception $e) {
            if ($requestStartedAt !== null) {
                Craft::debug(sprintf(
                    '%s API request failed after %.3fs',
                    $this->providerLabel,
                    microtime(true) - $requestStartedAt
                ), __METHOD__);
            }

            $errorResponse = new InteractionsResponse();

            if ($e instanceof RequestException && $e->hasResponse()) {
                $responseBody = (string)$e->getResponse()->getBody();
                $errorData = json_decode($responseBody, true);

                // A 429 is a rate limit that waiting out is worth trying, except `quota_exceeded`, a daily quota that
                // waiting minutes won't reset
                if ($e->getResponse()->getStatusCode() === 429 && ($errorData['error']['code'] ?? null) !== 'quota_exceeded') {
                    $errorResponse->rateLimitedFor = RateLimitException::retryAfter($e->getResponse()) ?? 0;
                }

                $apiErrorMessage = $errorData['error']['message'] ?? null;
                if (is_string($apiErrorMessage)) {
                    Craft::error($this->providerLabel . ' API error: ' . trim($responseBody), __METHOD__);

                    $errorResponse->setError($this->providerLabel . ' API error: ' . $apiErrorMessage, $errorData['error']);
                    return $errorResponse;
                }
            }

            Craft::error($this->providerLabel . ' API request failed: ' . $e->getMessage(), __METHOD__);

            $errorResponse->setError($e->getMessage());
            return $errorResponse;
        }
    }

    /**
     * Generates alt text for an asset using a vision model through the Interactions API
     *
     * @param Asset $asset The asset to generate alt text for
     * @param int|null $siteId The site the alt text is for, which sets the language of the prompt's site variables
     * @param bool $forceBase64 Not used: images are always sent as base64
     * @return string The generated alt text, or an empty string if the asset is skipped or the model returned no text
     * @throws Exception If the request is invalid or the provider returns an error
     */
    public function generateAltText(Asset $asset, ?int $siteId = null, bool $forceBase64 = false): string
    {
        if (!$this->validateImageSupport($asset)) {
            return '';
        }

        // Set by flattenTransparency() if this image's transparent areas are filled
        $this->transparencyFill = null;

        $transformParams = $this->getVisionTransformParams($asset, maxLongEdge: self::MAX_LONG_EDGE, maxFileSizeMb: 10);

        // Checked before setTransform(), after which the asset reports the transform's MIME type instead of GIF, WebP or SVG
        $isAnimated = $this->isAnimated($asset);
        $isSvg = AiAltText::getInstance()->aiAltTextService->isSvg($asset);
        // Transparent images are flattened here, as models don't reliably see transparency, see getFlattenedBase64()
        $flattenedBase64 = ($isAnimated || $isSvg) ? null : $this->getFlattenedBase64($asset, $transformParams);

        if (!empty($transformParams)) {
            $asset->setTransform($transformParams);
        }

        // The MIME type of the image sent: a JPG for a filled image and a WebP or PNG for an SVG, as both are rendered here
        // (an SVG can't use getMimeType(), which stays image/svg+xml when Craft's transformSvgs is off). Anything
        // else uses getMimeType(): the transform's output format after setTransform(), or the original's without one
        $mimeType = match (true) {
            $flattenedBase64 !== null => 'image/jpeg',
            $isSvg => 'image/' . $this->convertedFormat(),
            default => $asset->getMimeType(),
        };

        if (!$this->isAcceptedMimeType($mimeType)) {
            throw new Exception("Asset transform produced unsupported MIME type: $mimeType. Supported formats are: " . implode(', ', self::ACCEPTED_MIME_TYPES));
        }

        $base64Images = match (true) {
            $flattenedBase64 !== null => [$flattenedBase64],
            $isAnimated => $this->getFramesBase64($asset, $transformParams),
            $isSvg => [$this->getSvgBase64($asset, $transformParams)],
            default => [$this->getAssetBase64String($asset, $transformParams)],
        };

        Craft::info('Generating alt text with ' . $this->providerLabel . ' for asset: ' . $asset->filename . ' (base64 image)', __METHOD__);

        // The images come first, then the text that asks for the alt text, with the prompt as the system instruction,
        // which the model weights more than text in the input. `store: false` stops Google keeping the request and
        // response, which it does for 1 day (free tier) or 55 days (paid) by default
        $input = array_map(fn(string $base64Image) => [
            'type' => 'image',
            'data' => $base64Image,
            'mime_type' => $mimeType,
        ], $base64Images);
        $input[] = [
            'type' => 'text',
            'text' => $this->generateTrigger(count($base64Images)),
        ];

        $response = $this->sendRequest([
            'model' => $this->model,
            'system_instruction' => $this->resolvePrompt($asset, $siteId),
            'input' => $input,
            'store' => false,
        ]);

        if ($response->hasError()) {
            if ($response->rateLimitedFor !== null) {
                throw new RateLimitException($response->getErrorMessage(), $response->rateLimitedFor ?: null);
            }

            throw new Exception($response->getErrorMessage());
        }

        if ($response->getText() === '') {
            Craft::warning('No alt text was generated for asset: ' . $asset->filename, __METHOD__);
            return '';
        }

        return $response->getText();
    }
}
