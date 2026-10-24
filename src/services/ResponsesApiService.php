<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\elements\Asset;
use craft\errors\{AssetException, ImageTransformException};
use craft\helpers\{App, Json};
use Exception;
use GuzzleHttp\Exception\{GuzzleException, RequestException};
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\api\{OpenAiRequest, OpenAiResponse};
use heavymetalavo\craftaialttext\models\Settings;
use yii\base\InvalidConfigException;

/**
 * Responses API Service
 *
 * The base for every provider that serves the Responses API (`POST {baseUrl}/responses`), which OpenAI designed and
 * the Open Responses spec (https://www.openresponses.org/) now describes. That is OpenAI itself and hosts such as
 * Langdock and OpenRouter. It builds the request, sends it, reads the response and generates the alt text.
 *
 * A provider extends it to say where it lives (`baseUrl`), which settings it reads (`applySettings()`,
 * `imageDetailLevel()`, `reasoningEffort()`) and what to call itself in log lines and messages (`providerLabel`).
 * It can also adjust the places where hosts differ: image sizing (`visionTransformParams()`), the `store` flag
 * (`sendStoreFalse`), the headers it sends (`extraHeaders()`) and how errors are worded.
 *
 * Hosts differ most in their error bodies. OpenAI's and Langdock's carry `type: invalid_request_error` when they
 * can't fetch an image URL, so the request is retried with the image as base64. OpenRouter's errors have no `type`,
 * so OpenRouterService sends base64 from the start.
 */
abstract class ResponsesApiService extends ApiService
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
     * @var string The provider's base URL without a trailing slash, such as `https://api.openai.com/v1`. The request
     * goes to `{baseUrl}/responses`. Set by the provider's default or by applySettings().
     */
    protected string $baseUrl;

    /**
     * @var bool Whether to send `store: false`, so the host doesn't keep the request and response. Only OpenAI needs
     * it, as it stores them for at least 30 days by default. OpenRouter and Langdock don't store them, and reject
     * `store: true` (they accept `false`).
     */
    protected bool $sendStoreFalse = false;

    /**
     * Headers to send beside the authorization, for a host that takes extras.
     *
     * @return array<string, string>
     */
    protected function extraHeaders(): array
    {
        return [];
    }

    /**
     * Constructor
     *
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
     * Reads the API key and model from the plugin settings, and sets the base URL if it depends on them.
     */
    abstract protected function applySettings(Settings $settings): void;

    /**
     * The `detail` value used for images larger than 512px on either side.
     * Smaller images are sent as `low`, the request model's default.
     */
    abstract protected function imageDetailLevel(): string;

    /**
     * The `reasoning.effort` value sent to reasoning models.
     */
    abstract protected function reasoningEffort(): string;

    /**
     * Sends a request to the Responses API
     *
     * This method handles the communication with the API, including:
     * - API call execution
     * - Response parsing
     * - Error handling
     *
     * @param array $requestData The request data to send
     * @return OpenAiResponse The response, which carries the error if the call failed. This method doesn't throw.
     */
    protected function sendRequest(array $requestData): OpenAiResponse
    {
        $requestStartedAt = null;

        try {
            $url = $this->baseUrl . '/responses';
            $this->logRequest($url, $requestData);

            $requestStartedAt = microtime(true);
            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    ...$this->extraHeaders(),
                ],
                'json' => $requestData,
            ]);

            $responseBody = (string)$response->getBody();
            $this->logResponse($url, $response->getStatusCode(), $responseBody, microtime(true) - $requestStartedAt);

            // Create response model and parse the response
            $responseModel = (new OpenAiResponse())->setProviderLabel($this->providerLabel);
            if (!$responseModel->parseResponse($responseBody)) {
                // If parsing failed, make sure we have an error message
                if (!$responseModel->hasError()) {
                    $responseModel->setError('Failed to parse ' . $this->providerLabel . ' API response');
                }
                $errorMsg = 'Response parsing failed: ' . $responseModel->getErrorMessage();
                Craft::warning($errorMsg, __METHOD__);
            }

            // Make sure the response model is valid
            if (!$responseModel->validate()) {
                $errorMsg = 'Response validation failed: ' . Json::encode($responseModel->getErrors());
                Craft::warning($errorMsg, __METHOD__);
                // Set error if not already set
                if (!$responseModel->hasError()) {
                    $responseModel->setError($errorMsg);
                }
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

            $errorResponse = new OpenAiResponse();

            // Check if this is a Guzzle exception with a response
            if ($e instanceof RequestException && $e->hasResponse()) {
                // Get the response body and parse it
                $responseBody = (string) $e->getResponse()->getBody();
                $errorData = json_decode($responseBody, true);

                // Extract just the error message from the response. Langdock reports some errors
                // as a flat {"message": "..."} rather than an error object.
                $apiErrorMessage = $errorData['error']['message'] ?? $errorData['message'] ?? null;
                if (is_string($apiErrorMessage)) {
                    $errorMsg = $this->providerLabel . ' API error: ' . $apiErrorMessage;
                    Craft::error($this->providerLabel . ' API error: ' . trim($responseBody), __METHOD__);

                    $errorResponse->setError($errorMsg, $errorData['error'] ?? null);
                    return $errorResponse;
                }
            }

            // Fall back to generic error handling
            Craft::error($this->providerLabel . ' API request failed: ' . $e->getMessage(), __METHOD__);

            $errorResponse->setError($e->getMessage());
            return $errorResponse;
        }
    }

    /**
     * The transform that scales an image to the model's limits before it is sent. This is the sizing for OpenAI's
     * models, which suits any host serving them. A host that serves other authors' models (OpenRouter serves
     * Claude, for example) can override it to size their images the way those models need.
     *
     * OpenAI Vision: max 2048px long edge (API handles short edge scaling internally), max 512MB payload, max 1536 patches.
     * Patch budget based on detail:high tiling — each 512px tile costs 170 tokens + 85 base.
     */
    protected function visionTransformParams(Asset $asset): array
    {
        return $this->getVisionTransformParams($asset, maxLongEdge: 2048, maxFileSizeMb: 512, maxPatches: 1536);
    }

    /**
     * Generates alt text for an asset using a vision model through the Responses API
     *
     * This method:
     * - Creates the necessary request structure with text prompt and image
     * - Sends the request to the provider
     * - Processes the response
     * - Handles any errors that occur during the process
     *
     * @param Asset $asset The asset to generate alt text for
     * @param int|null $siteId The site the alt text is for, which sets the language of the prompt's site variables
     * @param bool $forceBase64 Send the image as base64 instead of a URL the provider has to fetch
     * @return string The generated alt text, or an empty string if the asset is skipped or the model returned no text
     * @throws GuzzleException
     * @throws ImageTransformException
     * @throws AssetException
     * @throws InvalidConfigException
     * @throws Exception If the request is invalid or the provider returns an error
     */
    public function generateAltText(Asset $asset, ?int $siteId = null, bool $forceBase64 = false): string
    {
        // Validate image support using the parent base service method
        if (!$this->validateImageSupport($asset)) {
            return '';
        }

        // Set by flattenTransparency() if this image's transparent areas are filled
        $this->transparencyFill = null;

        $transformParams = $this->visionTransformParams($asset);

        // Checked before setTransform(), after which the asset reports the transform's MIME type instead of GIF, WebP or SVG
        $isAnimated = $this->isAnimated($asset);
        $isSvg = AiAltText::getInstance()->aiAltTextService->isSvg($asset);
        // Transparent images are flattened here, as providers ignore the alpha channel, see getFlattenedBase64()
        $flattenedBase64 = $isAnimated || $isSvg ? null : $this->getFlattenedBase64($asset, $transformParams);

        if (!empty($transformParams)) {
            $asset->setTransform($transformParams);
        }

        // After setTransform(), getMimeType() reflects the output format of the transform. A flattened image is a JPG
        $mimeType = $flattenedBase64 !== null ? 'image/jpeg' : $asset->getMimeType();
        
        if (!$this->isAcceptedMimeType($mimeType)) {
            throw new Exception("Asset transform produced unsupported MIME type: $mimeType. Supported formats are: " . implode(', ', self::ACCEPTED_MIME_TYPES));
        }
        
        // Make sure that we do not get a "generate transform" url, but a real url with true. Animated images, SVGs and
        // transparent images are rendered here and sent as base64, see getFramesBase64(), getSvgBase64() and getFlattenedBase64()
        $imageUrl = $isAnimated || $isSvg || $flattenedBase64 !== null ? null : $asset->getUrl($transformParams, true);

        // If we have a URL, resolve root-relative URLs to the site base; leave absolute URLs as-is
        if (!empty($imageUrl)) {
            $imageUrl = $this->resolveAssetUrl($asset, $imageUrl);
        }

        $imageUrls = [$imageUrl];

        // If no public URL is available, or base64 is forced
        if ($forceBase64 || empty($imageUrl) || !$asset->getVolume()->getFs()->hasUrls) {
            $base64Images = match (true) {
                $flattenedBase64 !== null => [$flattenedBase64],
                $isAnimated => $this->getFramesBase64($asset, $transformParams),
                $isSvg => [$this->getSvgBase64($asset, $transformParams)],
                default => [$this->getAssetBase64String($asset, $transformParams)],
            };
            // Replace URL with data strings
            $imageUrls = array_map(fn(string $base64Image) => "data:$mimeType;base64,$base64Image", $base64Images);
            $imageUrl = $imageUrls[0];
        }

        // Use the configured detail level for images over 512px on either side. Smaller images keep the request
        // model's default, `low`.
        $width = $asset->getWidth();
        $height = $asset->getHeight();
        $detail = null;
        if ($width > 512 || $height > 512) {
            $detail = $this->imageDetailLevel();
        }
        
        $prompt = $this->resolvePrompt($asset, $siteId);

        // Log asset info for debugging
        Craft::info('Generating alt text with ' . $this->providerLabel . ' for asset: ' . $asset->filename . ' (' . (str_starts_with($imageUrl, 'data:') ? substr($imageUrl, 0, 30) . '…' : $imageUrl) . ')', __METHOD__);

        // Create and populate the request model
        $request = new OpenAiRequest();
        $request->model = $this->model;
        $request->setInstructions($prompt)
            ->setPrompt($this->generateTrigger(count($imageUrls)))
            ->setImageUrls($imageUrls)
            ->setReasoningEffort($this->reasoningEffort());
            
        // Only set detail if the image is large enough
        if ($detail !== null) {
            $request->setDetail($detail);
        }

        // Validate the request
        if (!$request->validate()) {
            throw new Exception('Invalid request: ' . Json::encode($request->getErrors()));
        }

        // Convert to array explicitly to avoid potential object-to-array conversion issues
        $requestArray = $request->toArray();

        if ($this->sendStoreFalse) {
            $requestArray['store'] = false;
        }

        // Send the request
        $response = $this->sendRequest($requestArray);

        // Check for errors from the API
        if ($response->hasError()) {
            $errorDetails = $response->error['details'] ?? null;
            $isBase64 = strpos($imageUrl, 'data:') === 0;

            if ($errorDetails && isset($errorDetails['type']) && $errorDetails['type'] === 'invalid_request_error' && !$forceBase64 && !$isBase64) {
                Craft::warning('Can access the asset URL, but the provider could not, forcing base64 fallback', __METHOD__);
                return $this->generateAltText($asset, $siteId, true);
            }

            throw new Exception($response->getErrorMessage());
        }

        // If output is empty, log and return empty string
        if (empty($response->outputText)) {
            Craft::warning('No alt text was generated for asset: ' . $asset->filename, __METHOD__);
            return '';
        }

        return $response->getText();
    }
}
