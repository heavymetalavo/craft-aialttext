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
use heavymetalavo\craftaialttext\models\api\OpenAiMessage;
use heavymetalavo\craftaialttext\models\api\OpenAiContent;
use heavymetalavo\craftaialttext\models\api\TextContent;
use heavymetalavo\craftaialttext\models\api\ImageContent;

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
     * - Request validation
     * - API call execution
     * - Response parsing
     * - Error handling
     * 
     * @param OpenAiRequest $request The request to send
     * @return OpenAiResponse The API response
     * @throws Exception If the request is invalid or the API call fails
     */
    public function sendRequest(OpenAiRequest $request): OpenAiResponse
    {
        $client = Craft::createGuzzleClient();

        try {
            if (!$request->validate()) {
                throw new Exception('Invalid request: ' . json_encode($request->getErrors()));
            }

            $response = $client->post($this->baseUrl . '/responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $request->toArray(),
            ]);

            $responseData = json_decode($response->getBody(), true);
            $responseModel = new OpenAiResponse();
            $responseModel->output = $responseData['output'] ?? '';

            if (!$responseModel->validate()) {
                throw new Exception('Invalid response: ' . json_encode($responseModel->getErrors()));
            }

            if ($responseModel->hasError()) {
                throw new Exception($responseModel->getErrorMessage());
            }

            return $responseModel;

        } catch (Exception $e) {
            Craft::error('OpenAI API request failed: ' . $e->getMessage(), __METHOD__);
            $errorResponse = new OpenAiResponse();
            $errorResponse->output = '';
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
        $prompt = App::parseEnv(AiAltText::getInstance()->getSettings()->prompt);
        $imageUrl = $asset->getUrl();
        $detail = App::parseEnv(AiAltText::getInstance()->getSettings()->openAiImageInputDetailLevel);

        $request = new OpenAiRequest();
        $request->model = $this->model;
        $request->input = [
            'prompt' => $prompt,
            'image' => [
                'url' => $imageUrl,
                'detail' => $detail
            ]
        ];
        $request->max_tokens = 300;

        $response = $this->sendRequest($request);
        
        if ($response->hasError()) {
            throw new Exception($response->getErrorMessage());
        }

        $altText = $response->getText();
        if (!$altText) {
            throw new Exception('No text in response');
        }

        return $altText;
    }
} 
