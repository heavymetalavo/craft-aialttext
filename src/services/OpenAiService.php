<?php

namespace heavymetalavo\craftaialttext\services;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Json;
use Exception;
use GuzzleHttp\Exception\{GuzzleException, RequestException};
use heavymetalavo\craftaialttext\models\api\{OpenAiRequest, OpenAiResponse};
use Illuminate\Container\Attributes\Singleton;
use heavymetalavo\craftaialttext\AiAltText;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI API Service
 *
 * Handles all interactions with the OpenAI API, including sending requests and processing responses.
 */
#[Singleton]
class OpenAiService extends ApiService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://api.openai.com/v1';
    private bool $hasFallbackRan = false;

    public function __construct()
    {
        parent::__construct();
        $settings = AiAltText::settings();
        $this->apiKey = Env::parse($settings->openAiApiKey);
        $this->model = Env::parse($settings->openAiModel);
    }

    /**
     * Sends a request to the OpenAI API.
     *
     * @throws Exception|GuzzleException
     */
    private function sendRequest(array $requestData): OpenAiResponse
    {
        $requestStartedAt = null;

        try {
            Log::debug('OpenAI API request: ' . Json::encode($requestData));

            $requestStartedAt = microtime(true);
            $response = $this->client->post($this->baseUrl . '/responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestData,
            ]);

            $responseBody = (string)$response->getBody();
            Log::debug(sprintf('OpenAI API request took %.3fs', microtime(true) - $requestStartedAt));
            Log::debug('OpenAI API raw response: ' . $responseBody);

            $responseModel = new OpenAiResponse();
            if (!$responseModel->parseResponse($responseBody)) {
                if (!$responseModel->hasError()) {
                    $responseModel->setError('Failed to parse OpenAI API response');
                }
                Log::warning('Response parsing failed: ' . $responseModel->getErrorMessage());
            }

            if (!$responseModel->validate()) {
                $errorMsg = 'Response validation failed: ' . Json::encode($responseModel->errors()->toArray());
                Log::warning($errorMsg);
                if (!$responseModel->hasError()) {
                    $responseModel->setError($errorMsg);
                }
            }

            return $responseModel;

        } catch (Exception $e) {
            if ($requestStartedAt !== null) {
                Log::debug(sprintf('OpenAI API request failed after %.3fs', microtime(true) - $requestStartedAt));
            }

            $errorResponse = new OpenAiResponse();

            if ($e instanceof RequestException) {
                $responseBody = (string) $e->getResponse()->getBody();
                $errorData = json_decode($responseBody, true);

                if (isset($errorData['error']['message'])) {
                    $errorMsg = 'OpenAI API error: ' . $errorData['error']['message'];
                    Log::error('OpenAI API error: ' . $responseBody);
                    $errorResponse->setError($errorMsg, $errorData['error']);
                    return $errorResponse;
                }
            }

            $errorMsg = 'OpenAI API request failed: ' . $e->getMessage();
            Log::error($errorMsg);
            $errorResponse->setError($e->getMessage());
            return $errorResponse;
        }
    }

    /**
     * Generates alt text for an asset using OpenAI's vision model.
     *
     * @throws GuzzleException
     * @throws Exception
     */
    public function generateAltText(Asset $asset, ?int $siteId = null): string
    {
        $settings = AiAltText::settings();

        if (!$this->validateImageSupport($asset)) {
            return '';
        }

        // OpenAI Vision: max 2048px long edge, max 512MB payload, max 1536 patches
        $transformParams = $this->getVisionTransformParams($asset, maxLongEdge: 2048, maxFileSizeMb: 512, maxPatches: 1536);

        if (!empty($transformParams)) {
            $asset->setTransform($transformParams);
        }

        $mimeType = $asset->getMimeType();

        if (!$this->isAcceptedMimeType($mimeType)) {
            throw new Exception("Asset transform produced unsupported MIME type: $mimeType. Supported formats are: " . implode(', ', self::ACCEPTED_MIME_TYPES));
        }

        $imageUrl = $asset->getUrl($transformParams, true);

        if (!empty($imageUrl)) {
            $imageUrl = $this->resolveAssetUrl($asset, $imageUrl);

            if (!$this->forceBase64 && !$this->isUrlAccessible($imageUrl)) {
                Log::warning('Asset URL is not accessible locally: ' . $imageUrl);
                $this->forceBase64 = true;
            }
        }

        if ($this->forceBase64 || empty($imageUrl) || !$asset->getVolume()->getFs()->hasUrls) {
            $base64Image = $this->getAssetBase64String($asset, $transformParams);
            $imageUrl = "data:$mimeType;base64,$base64Image";
        }

        $width = $asset->getWidth();
        $height = $asset->getHeight();
        $detail = null;
        if ($width > 512 || $height > 512) {
            $detail = Env::parse($settings->openAiImageInputDetailLevel) ?? 'low';
        }

        $prompt = Env::parse($settings->prompt);

        $prompt = preg_replace_callback('/{asset\.(.*?)}/', function ($matches) use ($asset) {
            return $asset->{$matches[1]};
        }, $prompt);

        $site = Sites::getSiteById($siteId);
        $prompt = preg_replace_callback('/{site\.(.*?)}/', function ($matches) use ($site) {
            return $site->{$matches[1]};
        }, $prompt);

        Log::info('Generating alt text for asset: ' . $asset->filename . ' (' . $imageUrl . ')');

        $request = new OpenAiRequest();
        $request->model = $this->model;
        $request->setPrompt($prompt)
            ->setImageUrl($imageUrl)
            ->setReasoningEffort((string) Env::parse($settings->openAiReasoningEffort));

        if ($detail !== null) {
            $request->setDetail($detail);
        }

        if (!$request->validate()) {
            throw new Exception('Invalid request: ' . Json::encode($request->errors()->toArray()));
        }

        $requestArray = $request->toArray();
        $response = $this->sendRequest($requestArray);

        if ($response->hasError()) {
            $errorDetails = $response->error['details'] ?? null;
            $isBase64 = strpos($imageUrl, 'data:') === 0;

            if ($errorDetails && isset($errorDetails['type']) && $errorDetails['type'] === 'invalid_request_error' && !$this->hasFallbackRan && !$isBase64) {
                $this->hasFallbackRan = true;
                $this->forceBase64 = true;
                Log::warning('Can access the asset URL, but the provider could not, forcing base64 fallback');
                return $this->generateAltText($asset, $siteId);
            }

            throw new Exception($response->getErrorMessage());
        }

        if (empty($response->outputText)) {
            Log::warning('No alt text was generated for asset: ' . $asset->filename);
            return '';
        }

        return $response->getText();
    }
}
