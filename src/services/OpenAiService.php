<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use Exception;
use craft\base\Component;
use craft\elements\Asset;
use craft\errors\{AssetException, ImageTransformException};
use craft\helpers\{App, Json};
use GuzzleHttp\Exception\{GuzzleException, RequestException};
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\api\{OpenAiRequest, OpenAiResponse};
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
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://api.openai.com/v1';

    /**
     * Constructor
     *
     * Initializes the service with the OpenAI API key and model from the plugin settings.
     */
    public function __construct()
    {
        parent::__construct();
        $plugin = AiAltText::getInstance();
        $this->apiKey = App::parseEnv($plugin->getSettings()->openAiApiKey);
        $this->model = App::parseEnv($plugin->getSettings()->openAiModel);
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
    private function sendRequest(array $requestData): OpenAiResponse
    {
        try {
            // Log the request for debugging
            Craft::info('OpenAI API request: ' . Json::encode($requestData), __METHOD__);

            $response = $this->client->post($this->baseUrl . '/responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestData,
            ]);

            $responseBody = (string)$response->getBody();
            // Log the raw response for debugging
            Craft::info('OpenAI API raw response: ' . $responseBody, __METHOD__);

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
            $errorResponse = new OpenAiResponse();

            // Check if this is a Guzzle exception with a response
            if ($e instanceof RequestException) {
                // Get the response body and parse it
                $responseBody = (string) $e->getResponse()->getBody();
                $errorData = json_decode($responseBody, true);

                // Extract just the error message from the response
                if (isset($errorData['error']['message'])) {
                    $errorMsg = 'OpenAI API error: ' . $errorData['error']['message'];
                    Craft::error('OpenAI API error: ' . $responseBody, __METHOD__);

                    $errorResponse->setError($errorMsg);
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
    public function generateAltText(Asset $asset, ?int $siteId = null): string
    {
        $plugin = AiAltText::getInstance();
        // Validate image support using the parent base service method
        $this->validateImageSupport($asset);

        // OpenAI Vision: max 2048px long edge (API handles short edge scaling internally), max 20MB payload
        // Patch budget based on detail:high tiling — each 512px tile costs 170 tokens + 85 base
        $transformParams = $this->getVisionTransformParams($asset, maxLongEdge: 2048, maxFileSizeMb: 20, maxPatches: 1536);

        if (!empty($transformParams)) {
            $asset->setTransform($transformParams);
        }

        // Check mime type of the transform:
        $transformMimeType = $asset->getMimeType($transformParams);
        
        if (!$this->isAcceptedMimeType($transformMimeType)) {
            throw new Exception("Asset transform produced unsupported MIME type: $transformMimeType. Supported formats are: " . implode(', ', self::ACCEPTED_MIME_TYPES));
        }
        
        // Make sure that we do not get a "generate transform" url, but a real url with true
        $imageUrl = $asset->getUrl($transformParams, true);

        // If we have a URL, check if it's accessible remotely
        if (!empty($imageUrl)) {
            if (!$this->isUrlAccessible($imageUrl)) {
                Craft::warning('Asset URL is not accessible remotely: ' . $imageUrl, __METHOD__);
                $imageUrl = null; // Reset to null to trigger base64 encoding
            }
        }

        // If no public URL is available or URL is not accessible, try to get the file contents and encode as base64
        if (empty($imageUrl) || !$asset->getVolume()->getFs()->hasUrls) {
            $base64Image = $this->getAssetBase64String($asset, $imageUrl, $transformParams);
            $imageUrl = "data:$transformMimeType;base64,$base64Image";
        }

        // Only set detail parameter for images larger than 512x512 pixels
        // OpenAI API doesn't accept detail parameter for smaller images
        $width = $asset->getWidth();
        $height = $asset->getHeight();
        $detail = null;
        if ($width > 512 || $height > 512) {
            $detail = App::parseEnv($plugin->getSettings()->openAiImageInputDetailLevel) ?? 'low';
        }
        
        $prompt = App::parseEnv($plugin->getSettings()->prompt);

        // parse $prompt for {asset.param} and replace with $asset->param
        // make sure that if the string may contain "{asset.title}{asset.caption}" we only replace each occurrence, and do not capture "{asset.title}{asset.caption}"
        $prompt = preg_replace_callback('/{asset\.(.*?)}/', function ($matches) use ($asset) {
            return $asset->{$matches[1]};
        }, $prompt);

        // Get the $site
        $site = Craft::$app->getSites()->getSiteById($siteId);

        // parse $prompt for {site.param} and replace with $site->param
        $prompt = preg_replace_callback('/{site\.(.*?)}/', function ($matches) use ($site) {
            return $site->{$matches[1]};
        }, $prompt);

        // Log asset info for debugging
        Craft::info('Generating alt text for asset: ' . $asset->filename . ' (' . $imageUrl . ')', __METHOD__);

        // Create and populate the request model
        $request = new OpenAiRequest();
        $request->model = $this->model;
        $request->setPrompt($prompt)
            ->setImageUrl($imageUrl);
            
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

        // Send the request
        $response = $this->sendRequest($requestArray);

        // Check for errors from the API
        if ($response->hasError()) {
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
