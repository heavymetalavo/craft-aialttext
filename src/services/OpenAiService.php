<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\App;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\api\OpenAiRequest;
use heavymetalavo\craftaialttext\models\api\OpenAiResponse;

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
class OpenAiService extends Component
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
        $this->apiKey = App::parseEnv(AiAltText::getInstance()->getSettings()->openAiApiKey);
        $this->model = App::parseEnv(AiAltText::getInstance()->getSettings()->openAiModel);
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
     * @throws Exception If the API call fails
     */
    public function sendRequest(array $requestData): OpenAiResponse
    {
        $client = Craft::createGuzzleClient();

        try {
            // Log the request for debugging
            Craft::info('OpenAI API request: ' . json_encode($requestData), __METHOD__);

            $response = $client->post($this->baseUrl . '/responses', [
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
                $errorMsg = 'Response validation failed: ' . json_encode($responseModel->getErrors());
                Craft::warning($errorMsg, __METHOD__);
                // Set error if not already set
                if (!$responseModel->hasError()) {
                    $responseModel->setError($errorMsg);
                }
            }

            return $responseModel;

        } catch (Exception $e) {
            $errorMsg = 'OpenAI API request failed: ' . $e->getMessage();
            Craft::error($errorMsg, __METHOD__);
            
            $errorResponse = new OpenAiResponse();
            $errorResponse->setError($e->getMessage());
            return $errorResponse;
        }
    }

    /**
     * Checks if a URL is accessible remotely
     *
     * @param string $url The URL to check
     * @return bool Whether the URL is accessible
     */
    private function isUrlAccessible(string $url): bool
    {
        try {
            $client = Craft::createGuzzleClient();
            $response = $client->head($url, [
                'timeout' => 5,
                'connect_timeout' => 5,
                'allow_redirects' => true,
            ]);
            return $response->getStatusCode() === 200;
        } catch (Exception $e) {
            Craft::warning('URL accessibility check failed: ' . $e->getMessage(), __METHOD__);
            return false;
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
     * @return string The generated alt text, or an empty string if generation fails
     */
    public function generateAltText(Asset $asset): string
    {
        try {
            $imageUrl = $asset->getUrl();
            
            // If we have a URL, check if it's accessible remotely
            if (!empty($imageUrl)) {
                if (!$this->isUrlAccessible($imageUrl)) {
                    Craft::warning('Asset URL is not accessible remotely: ' . $imageUrl, __METHOD__);
                    $imageUrl = null; // Reset to null to trigger base64 encoding
                }
            }
            
            // If no public URL is available or URL is not accessible, try to get the file contents and encode as base64
            if (empty($imageUrl) || !$asset->getVolume()->getFs()->hasUrls) {
                $fileContents = file_get_contents($asset->getPath());
                if ($fileContents === false) {
                    throw new Exception('Failed to read asset file contents');
                }
                
                // Get the MIME type
                $mimeType = $asset->getMimeType();
                if (empty($mimeType)) {
                    $mimeType = 'image/jpeg'; // Default to JPEG if MIME type is unknown
                }
                
                // Encode as base64 and create data URI
                $base64Image = base64_encode($fileContents);
                $imageUrl = "data:{$mimeType};base64,{$base64Image}";
            }
            
            $detail = Craft::$app->getConfig()->getGeneral()->openAiImageDetail ?? 'auto';
            $prompt = App::parseEnv(AiAltText::getInstance()->getSettings()->prompt);
            
            // Make sure we have a valid prompt
            if (empty($prompt)) {
                $prompt = 'Generate a concise, descriptive alt text for this image.';
            }

            // Log asset info for debugging
            Craft::info('Generating alt text for asset: ' . $asset->filename . ' (' . $imageUrl . ')', __METHOD__);

            // Create and populate the request model
            $request = new OpenAiRequest();
            $request->model = $this->model;
            $request->setPrompt($prompt)
                    ->setImageUrl($imageUrl)
                    ->setDetail($detail);

            // Validate the request
            if (!$request->validate()) {
                throw new Exception('Invalid request: ' . json_encode($request->getErrors()));
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
            if (empty($response->output_text)) {
                Craft::warning('No alt text was generated for asset: ' . $asset->filename, __METHOD__);
                return '';
            }

            return $response->getText();

        } catch (Exception $e) {
            $errorMessage = 'Failed to generate alt text: ' . $e->getMessage();
            Craft::error($errorMessage, __METHOD__);
            
            // Return empty string on errors
            return '';
        }
    }
}
