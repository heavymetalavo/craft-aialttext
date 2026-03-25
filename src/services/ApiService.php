<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Base API Service
 *
 * Provides shared logic for image preparation, transformation, and downloading
 * via Guzzle before sending payloads to specific AI Provider services.
 */
abstract class ApiService extends Component
{
    /**
     * @var array Standard supported image formats across most AI Vision providers
     */
    public const ACCEPTED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif'
    ];

    /**
     * @var Client
     */
    protected Client $client;

    /**
     * @var bool Forces the use of base64 encoding even if the asset has a URL (useful for fallback when provider fails to download from URL)
     */
    protected bool $forceBase64 = false;

    public function __construct($config = [])
    {
        parent::__construct($config);
        $this->client = Craft::createGuzzleClient(['timeout' => 30]);
    }
    /**
     * Required implementation for child services to generate their specific payloads.
     */
    abstract public function generateAltText(Asset $asset, ?int $siteId = null): string;

    /**
     * Turns root-relative asset URLs into absolute URLs for Guzzle and provider APIs; leaves absolute URLs unchanged.
     */
    protected function resolveAssetUrl(Asset $asset, string $url): string
    {
        // Convert `//bucket.s3.com/img.jpg` to `https://bucket.s3.com/img.jpg`
        if (UrlHelper::isProtocolRelativeUrl($url)) {
            return UrlHelper::urlWithScheme($url, 'https');
        }

        // Catch both root-relative (`/imgs/file.jpg`) and normal relative (`imgs/file.jpg`) local volume paths
        // and convert them to absolute URLs via the Craft Site URL. (`domain.com/imgs/file.jpg`)
        if (!UrlHelper::isAbsoluteUrl($url)) {
            return UrlHelper::siteUrl($url, null, null, $asset->siteId);
        }

        return $url;
    }

    /**
     * Checks if a URL is accessible remotely.
     *
     * @param string $url The URL to check (already resolved for HTTP if needed)
     * @return bool Whether the URL is accessible
     */
    protected function isUrlAccessible(string $url): bool
    {
        try {
            $response = $this->client->head($url, [
                'timeout' => 30,
                'connect_timeout' => 30,
                'allow_redirects' => true,
            ]);
            return $response->getStatusCode() === 200;
        } catch (Exception $e) {
            Craft::warning('URL accessibility check failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Validates if the asset is an accepted image format natively.
     *
     * @param Asset $asset The asset to validate
     * @param bool $allowSvgs Whether the endpoint natively supports SVGs or relies on Craft's SVG transform pipeline
     * @throws Exception If the asset format is invalid or fundamentally unsupported
     */
    protected function validateImageSupport(Asset $asset, bool $allowSvgs = false): void
    {
        $mimeType = strtolower($asset->getMimeType());

        if ($mimeType === 'image/svg+xml') {
            if (!$allowSvgs && !Craft::$app->getConfig()->getGeneral()->transformSvgs) {
                throw new Exception("SVGs are not natively supported by this API provider and Craft's `transformSvgs` explicitly disables fallback rasterization.");
            }
        }
    }

    /**
     * Checks if a GIF asset contains multiple frames (animated).
     *
     * @param Asset $asset The asset to check
     * @return bool Whether the GIF is animated
     */
    protected function isAnimatedGif(Asset $asset): bool
    {
        $mimeType = strtolower($asset->getMimeType());
        if ($mimeType !== 'image/gif') {
            return false;
        }

        $fileContents = $asset->getContents();
        return substr_count($fileContents, "\x21\xF9\x04") > 1;
    }

    /**
     * Checks if a specific MIME type is supported by AI Vision bounds limit mappings
     *
     * @param string $mimeType
     * @return bool
     */
    protected function isAcceptedMimeType(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), self::ACCEPTED_MIME_TYPES);
    }

    /**
     * Checks if the asset format requires conversion to a natively supported format (JPEG/PNG).
     *
     * @param Asset $asset The asset to check
     * @return bool
     */
    protected function needsFormatConversion(Asset $asset): bool
    {
        return !$this->isAcceptedMimeType($asset->getMimeType());
    }

    /**
     * Downloads an asset or transform URL via Guzzle and returns its base64 encoded string.
     * Falls back to returning the original asset contents as base64 if the URL cannot be fetched.
     *
     * @param Asset $asset The original asset
     * @param string|null $imageUrl The target URL (often a temporary transformed URL)
     * @param array $transformParams The params applied, used for log context
     * @return string The base64 encoded data
     */
    protected function getAssetBase64String(Asset $asset, ?string $imageUrl, array $transformParams = []): string
    {
        // Always try to fetch the transformed or public URL using Guzzle to get the resized contents
        if (!empty($imageUrl)) {
            $imageUrl = $this->resolveAssetUrl($asset, $imageUrl);
            try {
                $response = $this->client->get($imageUrl);
                if ($response->getStatusCode() === 200) {
                    $assetContents = (string)$response->getBody();
                    return base64_encode($assetContents);
                }
            } catch (Exception|GuzzleException $e) {
                Craft::warning('Failed to download image URL for Base64 conversion: ' . $e->getMessage(), __METHOD__);
            }
        }
        
        if (empty($imageUrl) || !$asset->getVolume()->getFs()->hasUrls) {
            Craft::warning('No image URL or asset contents found, falling back to original asset contents', __METHOD__);
            if ($this->needsFormatConversion($asset)) {
                $assetMimeType = strtolower($asset->getMimeType());
                // See https://github.com/craftcms/cms/issues/17238#issuecomment-2873206148
                Craft::warning("Asset {$asset->filename} has no URL and an unsupported MIME type \"$assetMimeType\". A transform is required but retrieving the file contents for a transform is unsupported. Continuing with source asset file contents for base64 encoding just incase it is accepted...", __METHOD__);
            }
            
            if (!empty($transformParams)) {
                Craft::warning("Asset {$asset->filename} has no URL and requires a transform, but retrieving the file contents for a transform is unsupported. Continuing with source asset file contents for base64 encoding just incase it is accepted...", __METHOD__);
            }
        } else {
            // URL existed but Guzzle still failed to download it for encoding
            Craft::warning("API Request: Download failed for image {$asset->filename}. Using original, un-scaled asset file contents for base64 encoding.", __METHOD__);
        }

        return base64_encode($asset->getContents());
    }

    /**
     * Generates a standardized array of transform parameters across AI Vision boundaries.
     * Handles unsupported MIME type fallback conversions (SVG/WEBP to PNG/JPG) and dimensional bounding.
     *
     * @param Asset $asset The original asset
     * @param int|null $maxLongEdge The maximum allowed length for the longest edge of the image
     * @param int $maxFileSizeMb The maximum file size in MB before a quality reduction is forced
     * @param int|null $maxPatches OpenAI tile budget — total 512px patch count ceiling (e.g. ceil(w/32)*ceil(h/32))
     * @param int|null $maxTokens Anthropic token budget — total pixel-area token ceiling (e.g. (w*h)/750)
     * @return array The calculated transform params suited for Craft's transform engine
     */
    protected function getVisionTransformParams(Asset $asset, ?int $maxLongEdge = null, int $maxFileSizeMb = 20, ?int $maxPatches = null, ?int $maxTokens = null): array
    {
        $assetMimeType = strtolower($asset->getMimeType());
        
        // Set up transform parameters
        $transformParams = [];
        
        // decide if we need to transform the image to become a jpeg
        $needsFormatConversion = $this->needsFormatConversion($asset);
        
        // Always convert format if needed, regardless of dimensions
        if ($needsFormatConversion) {
            // @todo check if webp is supported by the environment and use that and fall back to jpg
            $transformParams['format'] = 'jpg';
            
            // check the image is a svg and fallback to transform to a png for transparency support
            if ($assetMimeType === 'image/svg+xml') {
                // @todo use webp and fallback to png for transparent images where webp is not supported
                $transformParams['format'] = 'png';
            }
        }
        
        // Animated GIFs need to be converted to a static format (extracts first frame)
        if ($this->isAnimatedGif($asset)) {
            Craft::warning("Asset {$asset->filename} is an animated GIF. Converting to JPG to extract the first frame for AI Vision analysis.", __METHOD__);
            $transformParams['format'] = 'jpg';
        }
        
        // Get original image dimensions
        $width = $asset->getWidth();
        $height = $asset->getHeight();
        
        // Cap the longest edge if it exceeds the provider's limit, Craft's 'fit' mode will preserve the aspect ratio
        if ($maxLongEdge !== null && ($width > $maxLongEdge || $height > $maxLongEdge)) {
            if ($width >= $height) {
                // Wide/square image: cap the width
                $transformParams['width'] = $maxLongEdge;
            } else {
                // Tall image: cap the height
                $transformParams['height'] = $maxLongEdge;
            }
        }
        
        // OpenAI patch budget: if the image would produce more tiles than allowed, scale down proportionally
        if ($maxPatches !== null) {
            $patchCount = ceil($width / 32) * ceil($height / 32);
            if ($patchCount > $maxPatches) {
                // Calculate scale factor to fit within patch budget
                $scaleFactor = sqrt($maxPatches / $patchCount);
                $scaledLongEdge = (int) floor(max($width, $height) * $scaleFactor);
                
                if ($width >= $height) {
                    $transformParams['width'] = $scaledLongEdge;
                } else {
                    $transformParams['height'] = $scaledLongEdge;
                }
            }
        }
        
        // Anthropic token budget: if the pixel area would exceed the token limit, scale down proportionally
        if ($maxTokens !== null) {
            $tokenCount = ($width * $height) / 750;
            if ($tokenCount > $maxTokens) {
                // Calculate scale factor to fit within token budget
                $scaleFactor = sqrt(($maxTokens * 750) / ($width * $height));
                $scaledLongEdge = (int) floor(max($width, $height) * $scaleFactor);
                
                if ($width >= $height) {
                    $transformParams['width'] = $scaledLongEdge;
                } else {
                    $transformParams['height'] = $scaledLongEdge;
                }
            }
        }
        
        // If the file exceeds the provider's max payload and no other transform has been set, reduce quality
        if (empty($transformParams) && $asset->size > $maxFileSizeMb * 1024 * 1024) {
            Craft::info("{$asset->filename} is larger than {$maxFileSizeMb}MB, setting transform quality to 75", __METHOD__);
            $transformParams['quality'] = 75;
        }
        
        // Set mode fit for all transforms, done here so that the array evaluates to empty if no resizing or formatting occurs
        if (!empty($transformParams)) {
            $transformParams['mode'] = 'fit';
        }

        return $transformParams;
    }
}
