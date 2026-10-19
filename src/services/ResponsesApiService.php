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
 * A provider extends it to say where it lives (`baseUrl`), which settings it reads and what to call itself in
 * messages (`providerLabel`), and can adjust the places where hosts differ: image sizing, the `store` flag and how
 * errors are worded. Hosts differ most in their error bodies, which is why a failed image URL is only retried as
 * base64 when the error says `invalid_request_error`, as OpenAI and Langdock do and OpenRouter doesn't.
 */
abstract class ResponsesApiService extends ApiService
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl;

    /**
     * @var bool Whether to send `store: false`, so the host doesn't keep the request and response. Only OpenAI needs
     * it, as it stores them for at least 30 days by default. OpenRouter and Langdock don't store them, and reject
     * `store: true` (they accept `false`).
     */
    protected bool $sendStoreFalse = false;

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
     * @return OpenAiResponse The API response
     * @throws Exception|GuzzleException If the API call fails
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
     * models, which suits any host serving them. A host that serves other vendors' models can override it to size
     * their images the way those vendors do.
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
     * @param int|null $siteId
     * @return string The generated alt text, or an empty string if generation fails
     * @throws GuzzleException
     * @throws ImageTransformException
     * @throws AssetException
     * @throws InvalidConfigException
     * @throws Exception
     */
    public function generateAltText(Asset $asset, ?int $siteId = null, bool $forceBase64 = false): string
    {
        // Validate image support using the parent base service method
        if (!$this->validateImageSupport($asset)) {
            return '';
        }

        $transformParams = $this->visionTransformParams($asset);

        if (!empty($transformParams)) {
            $asset->setTransform($transformParams);
        }

        // After setTransform(), getMimeType() reflects the output format of the transform
        $mimeType = $asset->getMimeType();
        
        if (!$this->isAcceptedMimeType($mimeType)) {
            throw new Exception("Asset transform produced unsupported MIME type: $mimeType. Supported formats are: " . implode(', ', self::ACCEPTED_MIME_TYPES));
        }
        
        // Make sure that we do not get a "generate transform" url, but a real url with true
        $imageUrl = $asset->getUrl($transformParams, true);

        // If we have a URL, resolve root-relative URLs to the site base; leave absolute URLs as-is
        if (!empty($imageUrl)) {
            $imageUrl = $this->resolveAssetUrl($asset, $imageUrl);
        }

        // If no public URL is available, or base64 is forced
        if ($forceBase64 || empty($imageUrl) || !$asset->getVolume()->getFs()->hasUrls) {
            $base64Image = $this->getAssetBase64String($asset, $transformParams);
            $imageUrl = "data:$mimeType;base64,$base64Image"; // Replace URL with data string
        }

        // Only set detail parameter for images larger than 512x512 pixels
        // OpenAI API doesn't accept detail parameter for smaller images
        $width = $asset->getWidth();
        $height = $asset->getHeight();
        $detail = null;
        if ($width > 512 || $height > 512) {
            $detail = $this->imageDetailLevel();
        }
        
        $prompt = $this->resolvePrompt($asset, $siteId);

        // Log asset info for debugging
        Craft::info('Generating alt text for asset: ' . $asset->filename . ' (' . (str_starts_with($imageUrl, 'data:') ? substr($imageUrl, 0, 30) . '…' : $imageUrl) . ')', __METHOD__);

        // Create and populate the request model
        $request = new OpenAiRequest();
        $request->model = $this->model;
        $request->setInstructions($prompt)
            ->setPrompt(self::GENERATE_TRIGGER)
            ->setImageUrl($imageUrl)
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
