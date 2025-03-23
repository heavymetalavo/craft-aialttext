<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\App;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
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
            
            $responseData = json_decode($responseBody, true);
            
            // If responseData is not an array, something went wrong
            if (!is_array($responseData)) {
                throw new Exception('Invalid response format: ' . $responseBody);
            }
            
            // Log the parsed response for debugging
            Craft::info('OpenAI API parsed response: ' . json_encode($responseData), __METHOD__);

            $responseModel = new OpenAiResponse();
            
            // Check if output_text exists
            if (isset($responseData['output_text'])) {
                $responseModel->output_text = $responseData['output_text'];
            } 
            // Check for other response formats
            elseif (isset($responseData['choices'][0]['message']['content'])) {
                $responseModel->output_text = $responseData['choices'][0]['message']['content'];
            }
            // Check for error messages
            elseif (isset($responseData['error'])) {
                $errorMessage = is_array($responseData['error']) 
                    ? ($responseData['error']['message'] ?? json_encode($responseData['error']))
                    : $responseData['error'];
                throw new Exception('API error: ' . $errorMessage);
            } 
            // If we can't find any output, log the entire response
            else {
                Craft::warning('Could not find output_text in response: ' . json_encode($responseData), __METHOD__);
                // Set error instead of using a message as output_text
                $responseModel->error = ['message' => 'Could not parse response from OpenAI API.'];
                $responseModel->output_text = '';
            }

            if (!$responseModel->validate()) {
                Craft::warning('Response validation failed: ' . json_encode($responseModel->getErrors()), __METHOD__);
                // Set error instead of using a message as output_text
                $responseModel->error = ['message' => 'Response validation failed: ' . json_encode($responseModel->getErrors())];
                $responseModel->output_text = '';
            }

            return $responseModel;

        } catch (Exception $e) {
            Craft::error('OpenAI API request failed: ' . $e->getMessage(), __METHOD__);
            $errorResponse = new OpenAiResponse();
            $errorResponse->output_text = 'Error: ' . $e->getMessage();
            $errorResponse->error = ['message' => $e->getMessage()];
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
     * @return string The generated alt text, or an empty string if generation fails
     */
    public function generateAltText(Asset $asset): string
    {
        try {
            $imageUrl = $asset->getUrl();
            
            // Make sure we have a valid URL
            if (empty($imageUrl)) {
                throw new Exception('Asset URL is empty. Make sure the asset is accessible.');
            }
            
            $detail = Craft::$app->getConfig()->getGeneral()->openAiImageDetail ?? 'auto';
            $prompt = App::parseEnv(AiAltText::getInstance()->getSettings()->prompt);
            
            // Make sure we have a valid prompt
            if (empty($prompt)) {
                $prompt = 'Generate a concise, descriptive alt text for this image.';
            }

            // Log asset info for debugging
            Craft::info('Generating alt text for asset: ' . $asset->filename . ' (' . $imageUrl . ')', __METHOD__);

            // Build the request data directly as an array
            $requestData = [
                'model' => $this->model,
                'input' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'input_text',
                                'text' => $prompt
                            ],
                            [
                                'type' => 'input_image',
                                'image_url' => $imageUrl,
                                'detail' => $detail
                            ]
                        ]
                    ]
                ]
            ];

            $response = $this->sendRequest($requestData);

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
            Craft::error('Failed to generate alt text: ' . $e->getMessage(), __METHOD__);
            // Return empty string on errors
            return '';
        }
    }
} 
