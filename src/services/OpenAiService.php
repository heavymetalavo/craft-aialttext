<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\errors\AssetException;
use craft\errors\ImageTransformException;
use craft\helpers\App;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\api\OpenAiRequest;
use heavymetalavo\craftaialttext\models\api\OpenAiResponse;
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
     * @throws Exception|GuzzleException If the API call fails
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
     * Checks if a URL is accessible remotely
     *
     * @param string $url The URL to check
     * @return bool Whether the URL is accessible
     * @throws GuzzleException
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
     * @return bool Whether the asset is an accepted mimetype or was successfully converted
     * @throws ImageTransformException
     */
    private function isValidImageFormat(Asset $asset): bool
    {
        $mimeType = strtolower($asset->getMimeType());

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
        if ($mimeType === 'image/gif') {
            $fileContents = $asset->getContents();
            // Check for multiple image frames in GIF
            if (substr_count($fileContents, "\x21\xF9\x04") > 1) {
                return false;
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
     * @param int|null $siteId
     * @return string The generated alt text, or an empty string if generation fails
     * @throws GuzzleException
     * @throws ImageTransformException
     * @throws AssetException
     * @throws InvalidConfigException
     * @throws Exception
     */
    public function generateAltText(Asset $asset, int $siteId = null): string
    {
        // Validate image format first
        if (!$this->isValidImageFormat($asset)) {
            throw new Exception('Asset is not in a supported image format. Supported formats are: PNG, JPEG, WEBP, and non-animated GIF.');
        }

        // Get original image dimensions
        $width = $asset->getWidth();
        $height = $asset->getHeight();

        // Check if format needs to be converted
        $acceptedMimeTypes = [
            'image/png',
            'image/jpeg',
            'image/webp',
            'image/gif',
        ];
        $assetMimeType = strtolower($asset->getMimeType());

        // Check if the asset is an animated gif which OpenAI API does not support
        if ($assetMimeType === 'image/gif') {
            $fileContents = $asset->getContents();
            // Check for multiple image frames in GIF
            if (substr_count($fileContents, "\x21\xF9\x04") > 1) {
                throw new Exception('Animated GIF detected, this is not supported.');
            }
        }
        
        // decide if we need to transform svgs
        if (!Craft::$app->getConfig()->getGeneral()->transformSvgs && $assetMimeType === 'image/svg+xml') {
            throw new Exception('SVGs are not supported by the OpenAI API and transformSvgs is disabled.');
        }

        // decide if we need to transform the image to become a jpeg
        $needsFormatConversion = !in_array($assetMimeType, $acceptedMimeTypes);

        // Set up transform parameters
        $transformParams = [];

        // Always convert format if needed, regardless of dimensions
        if ($needsFormatConversion) {
            $transformParams['format'] = 'jpg';
        }

        // If width is larger than height and width is larger than 2000px set transform params
        if ($width > $height && ($width > 2000 || $height > 768)) {
            $transformParams['width'] = 2000;
            $transformParams['height'] = 768;
            $transformParams['mode'] = 'fit';
        } elseif ($height > $width && ($height > 2000 || $width > 768)) {
            $transformParams['width'] = 768;
            $transformParams['height'] = 2000;
            $transformParams['mode'] = 'fit';
        }
        
        // Very unlikely a 20MB file will be under 2000x768, but just in case lets set the quality to 75 to mitigate the risk of that scenario
        if (empty($transformParams) && $asset->size >  20 * 1024 * 1024) {
            Craft::info("$asset->filename is 20MB file detected setting transform quality to 75", __METHOD__);
            $transformParams['quality'] = 75;
        }

        // Set the transform
        $asset->setTransform($transformParams);

        // Check mime type of the transform:
        $transformMimeType = $asset->getMimeType($transformParams);
        if (!in_array($transformMimeType, $acceptedMimeTypes)) {
            Craft::warning("Asset transform has unsupported MIME type: $transformMimeType, continuing with source asset...", __METHOD__);
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
            if ($needsFormatConversion) {
                // See https://github.com/craftcms/cms/issues/17238#issuecomment-2873206148
                Craft::warning("Asset $asset->filename has no URL and an unsupported MIME type \"$assetMimeType\". A transform is required but retrieving the file contents for a transform is unsupported. Continuing with source asset file contents for base64 encoding just incase it is accepted...", __METHOD__);
            }
            if (!empty($transformParams)) {
                Craft::warning("Asset $asset->filename has no URL and requires a transform, but retrieving the file contents for a transform is unsupported. Continuing with source asset file contents for base64 encoding just incase it is accepted...", __METHOD__);
            }
            $assetContents = $asset->getContents();

            // Encode as base64 and create data URI
            $base64Image = base64_encode($assetContents);
            $imageUrl = "data:$transformMimeType;base64,$base64Image";
        }

        $detail = App::parseEnv(AiAltText::getInstance()->getSettings()->openAiImageInputDetailLevel) ?? 'low';
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
    }
}
