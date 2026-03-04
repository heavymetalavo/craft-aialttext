<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\App;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\models\api\AnthropicRequest;
use heavymetalavo\craftaialttext\models\api\AnthropicResponse;

/**
 * Anthropic API Service
 *
 * Handles API interactions with Anthropic's Claude to generate alt text via the Messages API.
 */
class AnthropicService extends Component
{
    private string $apiKey;
    private string $model;
    private string $detailLevel;
    private string $baseUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct()
    {
        parent::__construct();
        $this->apiKey = App::parseEnv(AiAltText::getInstance()->getSettings()->anthropicApiKey);
        $this->model = App::parseEnv(AiAltText::getInstance()->getSettings()->anthropicModel);
        $this->detailLevel = AiAltText::getInstance()->getSettings()->anthropicImageDetailLevel;
    }

    /**
     * Checks if a URL is accessible remotely
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
     * Generates alt text using Claude Messages API
     */
    public function generateAltText(Asset $asset, ?int $siteId = null): string
    {
        $mimeType = strtolower($asset->getMimeType());
        $acceptedMimeTypes = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
        
        $needsFormatConversion = !in_array($mimeType, $acceptedMimeTypes);
        if ($mimeType === 'image/gif') {
            $fileContents = $asset->getContents();
            if (substr_count($fileContents, "\x21\xF9\x04") > 1) {
                $needsFormatConversion = true; // Attempt to grab single frame by converting
            }
        }

        if (!Craft::$app->getConfig()->getGeneral()->transformSvgs && $mimeType === 'image/svg+xml') {
            throw new Exception('SVGs are not supported by the Anthropic API and transformSvgs is disabled.');
        }
        
        $transformParams = [
            'mode'  => 'fit',
        ];
        if ($needsFormatConversion) {
            $transformParams['format'] = $mimeType === 'image/svg+xml' ? 'png' : 'jpg';
        }

        $targetDimension = match ($this->detailLevel) {
            'very_low' => 200,
            'low' => 500,
            'high' => 1536,
            default => 1000, // medium
        };

        // Avoid extremely large images for API
        $width = $asset->getWidth();
        $height = $asset->getHeight();
        
        if ($width > $targetDimension || $height > $targetDimension) {
            if ($width >= $height) {
                // Wide image
                $transformParams['width'] = $targetDimension;
                // keep aspect ratio by adjusting height relative to the original aspect ratio
                $transformParams['height'] = (int)round(($height / $width) * $targetDimension); 
            } else {
                // Tall image
                $transformParams['height'] = $targetDimension;
                $transformParams['width'] = (int)round(($width / $height) * $targetDimension);
            }
        }

        if (empty($transformParams) && $asset->size > 20 * 1024 * 1024) {
            $transformParams['quality'] = 75;
        }

        $asset->setTransform($transformParams);
        $transformMimeType = $asset->getMimeType($transformParams);
        $imageUrl = $asset->getUrl($transformParams, true);

        if (!empty($imageUrl) && !$this->isUrlAccessible($imageUrl)) {
            $imageUrl = null;
        }

        $imageSource = null;
        
        // Always try to fetch the transformed URL using Guzzle to get the resized contents
        if (!empty($imageUrl)) {
            try {
                $client = Craft::createGuzzleClient();
                $response = $client->get($imageUrl, ['timeout' => 10]);
                if ($response->getStatusCode() === 200) {
                    $assetContents = (string)$response->getBody();
                    $base64Image = base64_encode($assetContents);
                    $imageSource = [
                        'type' => 'base64',
                        'media_type' => $transformMimeType,
                        'data' => $base64Image,
                    ];
                }
            } catch (Exception $e) {
                Craft::warning('Failed to download transformed image URL for Anthropic: ' . $e->getMessage(), __METHOD__);
                $imageUrl = null; // Trigger fallback
            }
        }

        // Fallback to original asset contents if URL wasn't accessible or downloaded
        if (empty($imageSource)) {
            $assetContents = $asset->getContents();
            $base64Image = base64_encode($assetContents);
            $imageSource = [
                'type' => 'base64',
                'media_type' => $transformMimeType,
                'data' => $base64Image,
            ];
            Craft::warning("Anthropic API: No URL available or download failed for transformed image $asset->filename. Using original, un-scaled asset file contents for base64 encoding.", __METHOD__);
        }

        return $this->sendAnthropicRequest(null, $imageSource, $transformMimeType, $asset, $siteId);
    }

    private function sendAnthropicRequest(?string $imageUrl, ?array $imageSource, string $mimeType, Asset $asset, ?int $siteId): string
    {
        $promptTemplate = App::parseEnv(AiAltText::getInstance()->getSettings()->prompt);

        $prompt = preg_replace_callback('/{asset\.(.*?)}/', function ($matches) use ($asset) {
            return $asset->{$matches[1]};
        }, $promptTemplate);

        $site = Craft::$app->getSites()->getSiteById($siteId);
        $prompt = preg_replace_callback('/{site\.(.*?)}/', function ($matches) use ($site) {
            return $site->{$matches[1]};
        }, $prompt);

        if (empty($prompt)) {
            $prompt = 'Describe this image for an alt text.';
        }

        $requestModel = new AnthropicRequest();
        $requestModel->model = $this->model;
        $requestModel->setPrompt($prompt);
        if ($imageSource) {
            $requestModel->setImageSource($imageSource);
        } elseif ($imageUrl) {
            $requestModel->setImageUrl($imageUrl);
        }

        if (!$requestModel->validate()) {
            throw new Exception("Invalid Anthropic request: " . \json_encode($requestModel->getErrors()));
        }

        $client = Craft::createGuzzleClient();

        try {
            $response = $client->post($this->baseUrl, [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'json' => $requestModel->toArray(),
            ]);

            $responseBody = (string)$response->getBody();
            
            $responseModel = new AnthropicResponse();
            if (!$responseModel->parseResponse($responseBody)) {
                throw new Exception("Anthropic API returned error: " . $responseModel->getErrorMessage());
            }

            return $responseModel->getText();
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $errorResponse = $e->hasResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();
            Craft::error("Anthropic API Error: " . $errorResponse, __METHOD__);
            
            $responseModel = new AnthropicResponse();
            if ($responseModel->parseResponse($errorResponse) && $responseModel->hasError()) {
                throw new Exception("Anthropic API request failed: " . $responseModel->getErrorMessage());
            }
            throw new Exception("Anthropic API request failed: " . $errorResponse);
        }
    }
}
