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
     * Validates if the asset is an accepted image format and converts if needed
     *
     * @param Asset $asset The asset to validate
     * @return bool Whether the asset is an accepted format or was successfully converted
     */
    private function isValidImageFormat(Asset $asset): bool
    {
        $extension = strtolower($asset->getExtension());
        $mimeType = strtolower($asset->getMimeType());

        // Check for accepted extensions
        $acceptedExtensions = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
        if (!in_array($extension, $acceptedExtensions)) {
            Craft::warning('Asset has unsupported extension: ' . $extension . '. Will attempt to convert to JPEG.', __METHOD__);
            return true; // We'll handle conversion in generateAltText
        }

        // Check for accepted MIME types
        $acceptedMimeTypes = [
            'image/png',
            'image/jpeg',
            'image/webp',
            'image/gif'
        ];

        if (!in_array($mimeType, $acceptedMimeTypes)) {
            Craft::warning('Asset has unsupported MIME type: ' . $mimeType . '. Will attempt to convert to JPEG.', __METHOD__);
            return true; // We'll handle conversion in generateAltText
        }

        // For GIFs, check if they're animated
        if ($extension === 'gif' && $mimeType === 'image/gif') {
            $filePath = $asset->getPath();
            if (file_exists($filePath)) {
                $fileContents = file_get_contents($filePath);
                // Check for multiple image frames in GIF
                if (substr_count($fileContents, "\x21\xF9\x04") > 1) {
                    Craft::warning('Animated GIF detected. Will attempt to convert first frame to JPEG.', __METHOD__);
                    return true; // We'll handle conversion in generateAltText
                }
            }
        }

        return true;
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
    public function generateAltText(Asset $asset, int $siteId = null): string
    {
        try {
            // Validate image format first
            if (!$this->isValidImageFormat($asset)) {
                throw new Exception('Asset is not in a supported image format. Supported formats are: PNG, JPEG, WEBP, and non-animated GIF.');
            }

            // Get original image dimensions
            $width = $asset->getWidth();
            $height = $asset->getHeight();

            // Check if format needs to be converted
            $extension = strtolower($asset->getExtension());
            $acceptedExtensions = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
            $needsFormatConversion = !in_array($extension, $acceptedExtensions);

            // Set up transform parameters
            $transformParams = [];

            // Always convert format if needed, regardless of dimensions
            if ($needsFormatConversion) {
                $transformParams['format'] = 'jpg';
            }

            // Add dimension constraints if needed
            if ($width > 2048 || $height > 2048) {
                $transformParams['width'] = 2048;
                $transformParams['height'] = 2048;
                $transformParams['mode'] = 'fit';
            }

            // Get the image URL with transformation if needed
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
                $assetContents = $asset->getContents();

                // Get the MIME type
                $mimeType = $asset->getMimeType();
                if (empty($mimeType)) {
                    $mimeType = 'image/jpeg'; // Default to JPEG if MIME type is unknown
                }

                // Encode as base64 and create data URI
                $base64Image = base64_encode($assetContents);
                $imageUrl = "data:{$mimeType};base64,{$base64Image}";
            }

            $detail = Craft::$app->getConfig()->getGeneral()->openAiImageDetail ?? 'auto';
            $prompt = App::parseEnv(AiAltText::getInstance()->getSettings()->prompt);

            // parse $prompt for {asset.param} and replace with $asset->param
            // make sure that if the string may contain "{asset.title}{asset.caption}" we only replace each occurrence, and do not capture "{asset.title}{asset.caption}"
            $prompt = preg_replace_callback('/{asset\.(.*?)}/', function ($matches) use ($asset) {
                return $asset->{$matches[1]};
            }, $prompt);

            // Get the $site
            $site = Craft::$app->getSites()->getSiteById($siteId);

            // parse $prompt for {site.param} and replace with $site->param
            // make sure that if the string may contain "{site.title}{site.caption}" we only replace each occurrence, and do not capture "{site.title}{site.caption}"
            $prompt = preg_replace_callback('/{site\.(.*?)}/', function ($matches) use ($site) {
                return $site->{$matches[1]};
            }, $prompt);

            // Make sure we have a valid prompt
            if (empty($prompt)) {
                $prompt = 'Generate a brief (roughly 150 characters maximum) alt text description focusing on the main subject and overall composition. Do not add a prefix of any kind (e.g. alt text: AI content) so the value is suitable for the alt text attribute value of the image.';
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

    /**
     * Generates a title and filename for an asset using OpenAI.
     * 
     * @param Asset $asset The asset to generate a title and filename for
     * @param string $titlePrompt The prompt to use for generating the title
     * @param string $filenamePrompt The prompt to use for generating the filename
     * @return array An array containing 'title' and 'filename' keys
     * @throws Exception If generation fails
     */
    public function generateTitleAndFilename(Asset $asset, string $titlePrompt, string $filenamePrompt): array
    {
        try {
            // Validate image format first
            if (!$this->isValidImageFormat($asset)) {
                throw new Exception('Asset is not in a supported image format. Supported formats are: PNG, JPEG, WEBP, and non-animated GIF.');
            }

            // Get original image dimensions
            $width = $asset->getWidth();
            $height = $asset->getHeight();

            // Check if format needs to be converted
            $extension = strtolower($asset->getExtension());
            $acceptedExtensions = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
            $needsFormatConversion = !in_array($extension, $acceptedExtensions);

            // Set up transform parameters
            $transformParams = [];

            // Always convert format if needed, regardless of dimensions
            if ($needsFormatConversion) {
                $transformParams['format'] = 'jpg';
            }

            // Add dimension constraints if needed
            if ($width > 2048 || $height > 2048) {
                $transformParams['width'] = 2048;
                $transformParams['height'] = 2048;
                $transformParams['mode'] = 'fit';
            }

            // Get the image URL with transformation if needed
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
                $assetContents = $asset->getContents();

                // Get the MIME type
                $mimeType = $asset->getMimeType();
                if (empty($mimeType)) {
                    $mimeType = 'image/jpeg'; // Default to JPEG if MIME type is unknown
                }

                // Encode as base64 and create data URI
                $base64Image = base64_encode($assetContents);
                $imageUrl = "data:{$mimeType};base64,{$base64Image}";
            }

            $detail = Craft::$app->getConfig()->getGeneral()->openAiImageDetail ?? 'auto';

            // Generate title
            $titleRequest = new OpenAiRequest();
            $titleRequest->model = $this->model;
            $titleRequest->setPrompt($titlePrompt)
                        ->setImageUrl($imageUrl)
                        ->setDetail($detail);
            $titleRequest->input[0]['max_tokens'] = 100;

            if (!$titleRequest->validate()) {
                throw new Exception('Invalid title request: ' . json_encode($titleRequest->getErrors()));
            }

            $titleResponse = $this->sendRequest($titleRequest->toArray());
            if ($titleResponse->hasError()) {
                throw new Exception($titleResponse->getErrorMessage());
            }

            $title = trim($titleResponse->getText());

            // Generate filename
            $filenameRequest = new OpenAiRequest();
            $filenameRequest->model = $this->model;
            $filenameRequest->setPrompt($filenamePrompt)
                          ->setImageUrl($imageUrl)
                          ->setDetail($detail);
            $filenameRequest->input[0]['max_tokens'] = 50;

            if (!$filenameRequest->validate()) {
                throw new Exception('Invalid filename request: ' . json_encode($filenameRequest->getErrors()));
            }

            $filenameResponse = $this->sendRequest($filenameRequest->toArray());
            if ($filenameResponse->hasError()) {
                throw new Exception($filenameResponse->getErrorMessage());
            }

            $filename = $this->cleanFilename($filenameResponse->getText());

            return [
                'title' => $title,
                'filename' => $filename
            ];

        } catch (Exception $e) {
            $errorMessage = 'Failed to generate title and filename: ' . $e->getMessage();
            Craft::error($errorMessage, __METHOD__);
            throw $e;
        }
    }

    /**
     * Cleans a generated filename to ensure it's valid
     * 
     * @param string $filename The filename to clean
     * @return string The cleaned filename
     */
    private function cleanFilename(string $filename): string
    {
        // Remove file extension if present
        $filename = pathinfo($filename, PATHINFO_FILENAME);
        
        // Convert to lowercase
        $filename = strtolower($filename);
        
        // Replace spaces and underscores with hyphens
        $filename = preg_replace('/[\s_]+/', '-', $filename);
        
        // Remove any characters that aren't alphanumeric or hyphens
        $filename = preg_replace('/[^a-z0-9-]/', '', $filename);
        
        // Trim hyphens from beginning and end
        $filename = trim($filename, '-');
        
        return $filename;
    }
}
