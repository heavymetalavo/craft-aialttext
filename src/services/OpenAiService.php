<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\Component;
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
 * OpenAI API Service
 *
 * Handles all interactions with the OpenAI API, including sending requests and processing responses.
 * This service manages the API configuration and provides methods for generating alt text using OpenAI's vision models.
 *
 * @property string $apiKey The OpenAI API key
 * @property string $model The OpenAI model to use
 * @property string $baseUrl The base URL for OpenAI API requests
 */
class OpenAiService extends ApiService
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl = 'https://api.openai.com/v1';
    protected string $providerLabel = 'OpenAI';

    /**
     * @var bool Whether to send `store: false`, so OpenAI doesn't keep the request and response (it stores them for
     * at least 30 days by default). Other hosts of the Responses API leave it out: OpenRouter is stateless and
     * rejects `store: true`, and Langdock's behaviour with it hasn't been tested.
     */
    protected bool $sendStoreFalse = true;

    /**
     * Constructor
     *
     * Initializes the service with the OpenAI API key and model from the plugin settings.
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
     * Reads the API key and model from the plugin settings. Providers that speak the OpenAI Responses API
     * through another host override this to read their own settings.
     */
    protected function applySettings(Settings $settings): void
    {
        $this->apiKey = App::parseEnv($settings->openAiApiKey);
        $this->model = App::parseEnv($settings->openAiModel);
    }

    /**
     * The `detail` value used for images larger than 512px on either side.
     * Smaller images are sent as `low`, the request model's default.
     */
    protected function imageDetailLevel(): string
    {
        return App::parseEnv(AiAltText::getInstance()->getSettings()->openAiImageInputDetailLevel) ?: 'low';
    }

    /**
     * The `reasoning.effort` value sent to reasoning models.
     */
    protected function reasoningEffort(): string
    {
        return (string) App::parseEnv(AiAltText::getInstance()->getSettings()->openAiReasoningEffort);
    }

    /**
     * Sends a request to the OpenAI API
     *
     * This method handles the communication with the OpenAI API, including:
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
            $responseModel = new OpenAiResponse();
            if (!$responseModel->parseResponse($responseBody)) {
                // If parsing failed, make sure we have an error message
                if (!$responseModel->hasError()) {
                    $responseModel->setError('Failed to parse OpenAI API response');
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
                    'OpenAI API request failed after %.3fs',
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
                // as a flat {"message": "..."} rather than OpenAI's error object.
                $apiErrorMessage = $errorData['error']['message'] ?? $errorData['message'] ?? null;
                if (is_string($apiErrorMessage)) {
                    $errorMsg = 'OpenAI API error: ' . $apiErrorMessage;
                    Craft::error('OpenAI API error: ' . trim($responseBody), __METHOD__);

                    $errorResponse->setError($errorMsg, $errorData['error'] ?? null);
                    return $errorResponse;
                }
            }

            // Fall back to generic error handling
            $errorMsg = 'OpenAI API request failed: ' . $e->getMessage();
            Craft::error($errorMsg, __METHOD__);

            $errorResponse->setError($e->getMessage());
            return $errorResponse;
        }
    }



    /**
     * The transform that scales an image to the model's limits before it is sent.
     *
     * OpenAI Vision: max 2048px long edge (API handles short edge scaling internally), max 512MB payload, max 1536 patches.
     * Patch budget based on detail:high tiling — each 512px tile costs 170 tokens + 85 base.
     */
    protected function visionTransformParams(Asset $asset): array
    {
        return $this->getVisionTransformParams($asset, maxLongEdge: 2048, maxFileSizeMb: 512, maxPatches: 1536);
    }

    /**
     * Generates alt text for an asset using OpenAI's vision model
     *
     * This method:
     * - Creates the necessary request structure with text prompt and image
     * - Sends the request to OpenAI
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
