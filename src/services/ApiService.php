<?php

namespace heavymetalavo\craftaialttext\services;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Cms;
use CraftCms\Cms\Image\ImageTransformHelper;
use CraftCms\Cms\Support\Url;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use heavymetalavo\craftaialttext\AiAltText;
use Illuminate\Support\Facades\Log;

/**
 * Base API Service
 *
 * Provides shared logic for image preparation, transformation, and downloading
 * via Guzzle before sending payloads to specific AI Provider services.
 */
abstract class ApiService
{
    /**
     * @var array Standard supported image formats across most AI Vision providers
     */
    public const ACCEPTED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
    ];

    /**
     * @var Client
     */
    protected Client $client;

    /**
     * @var bool Forces the use of base64 encoding even if the asset has a URL
     */
    protected bool $forceBase64 = false;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 30]);
    }

    /**
     * Required implementation for child services to generate their specific payloads.
     */
    abstract public function generateAltText(Asset $asset, ?int $siteId = null): string;

    /**
     * Turns root-relative asset URLs into absolute URLs; leaves absolute URLs unchanged.
     */
    protected function resolveAssetUrl(Asset $asset, string $url): string
    {
        if (Url::isProtocolRelativeUrl($url)) {
            return Url::urlWithScheme($url, 'https');
        }

        if (!Url::isAbsoluteUrl($url)) {
            return Url::siteUrl($url, null, null, $asset->siteId);
        }

        return $url;
    }

    /**
     * Checks if a URL is accessible remotely.
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
            Log::warning('URL accessibility check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validates if the asset is an accepted image format.
     *
     * @throws Exception If the asset format is fundamentally unsupported
     */
    protected function validateImageSupport(Asset $asset, bool $allowSvgs = false): bool
    {
        $mimeType = $asset->getMimeType();

        if ($mimeType === 'image/svg+xml') {
            if (!AiAltText::settings()->processSvgs) {
                Log::info("Skipping SVG asset (processSvgs disabled): $asset->filename");
                return false;
            }

            if (!$allowSvgs) {
                if (!Cms::config()->transformSvgs) {
                    Log::warning("SVG asset $asset->filename requires transformSvgs to be enabled in Craft general config to be processed; skipping.");
                    return false;
                }

                Log::debug("SVG asset $asset->filename is not natively supported by the provider, will attempt transformation.");
            }
        }

        return true;
    }

    /**
     * Checks if a GIF asset contains multiple frames (animated).
     */
    protected function isAnimatedGif(Asset $asset): bool
    {
        $mimeType = $asset->getMimeType();
        if ($mimeType !== 'image/gif') {
            return false;
        }

        $fileContents = $asset->getContents();
        return substr_count($fileContents, "\x21\xF9\x04") > 1;
    }

    /**
     * Checks if a specific MIME type is supported by AI Vision providers.
     */
    protected function isAcceptedMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::ACCEPTED_MIME_TYPES);
    }

    /**
     * Checks if the asset format requires conversion to a natively supported format.
     */
    protected function needsFormatConversion(Asset $asset): bool
    {
        return !$this->isAcceptedMimeType($asset->getMimeType());
    }

    /**
     * Downloads the asset's (possibly transformed) URL via Guzzle and returns its base64 encoded string.
     * Falls back to the original asset file contents if the URL cannot be fetched.
     *
     * Note: passes null (not []) to getUrl() when $transformParams is empty so that any transform
     * previously applied via setTransform() is respected.
     */
    protected function getAssetBase64String(Asset $asset, array $transformParams = []): string
    {
        $imageUrl = $asset->getUrl(!empty($transformParams) ? $transformParams : null, true);

        if (!empty($imageUrl)) {
            $imageUrl = $this->resolveAssetUrl($asset, $imageUrl);
            try {
                $response = $this->client->get($imageUrl);
                if ($response->getStatusCode() === 200) {
                    return base64_encode((string)$response->getBody());
                }
            } catch (Exception|GuzzleException $e) {
                Log::warning('Failed to download image URL for Base64 conversion: ' . $e->getMessage());
            }
        }

        // For SVGs that require format conversion (e.g. SVG→PNG), getContents() would return
        // raw SVG data which AI providers reject even when a transform MIME type is claimed.
        // Try generating the transform locally to obtain the correct binary data instead.
        $originalExtension = strtolower($asset->getExtension());
        if ($originalExtension === 'svg' && !empty($transformParams)) {
            try {
                $imageTransform = ImageTransformHelper::normalizeTransform($transformParams);
                if ($imageTransform) {
                    $tempPath = ImageTransformHelper::generateTransform($asset, $imageTransform);
                    $binary = file_get_contents($tempPath);
                    @unlink($tempPath);
                    if ($binary !== false && $binary !== '') {
                        Log::debug("Generated local SVG transform for {$asset->filename}");
                        return base64_encode($binary);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to generate local SVG transform for {$asset->filename}: " . $e->getMessage());
            }
            throw new Exception("Cannot process SVG asset '{$asset->filename}': the transform URL is inaccessible and local transform generation failed. Ensure the volume URL is publicly accessible, or disable SVG processing in the plugin settings.");
        }

        if (empty($imageUrl) || !$asset->getVolume()->getFs()->hasUrls) {
            if ($this->needsFormatConversion($asset)) {
                $assetMimeType = $asset->getMimeType();
                Log::warning("Asset {$asset->filename} has no URL and an unsupported MIME type \"$assetMimeType\". A transform is required but retrieving the file contents for a transform is unsupported. Continuing with source asset file contents for base64 encoding just incase it is accepted...");
            }
        } else {
            Log::warning("API Request: Download failed for image {$asset->filename}. Using original, un-scaled asset file contents for base64 encoding.");
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
     * @param int|null $maxPatches OpenAI tile budget — total 512px patch count ceiling
     * @param int|null $maxTokens Anthropic token budget — total pixel-area token ceiling
     */
    protected function getVisionTransformParams(Asset $asset, ?int $maxLongEdge = null, int $maxFileSizeMb = 20, ?int $maxPatches = null, ?int $maxTokens = null): array
    {
        $isSvg = app(AiAltTextService::class)->isSvg($asset);

        $transformParams = [];

        $needsFormatConversion = $this->needsFormatConversion($asset);

        if ($needsFormatConversion) {
            $transformParams['format'] = $isSvg ? 'png' : 'jpg';
        }

        if ($this->isAnimatedGif($asset)) {
            Log::warning("Asset {$asset->filename} is an animated GIF. Converting to JPG to extract the first frame for AI Vision analysis.");
            $transformParams['format'] = 'jpg';
        }

        $width = $asset->getWidth();
        $height = $asset->getHeight();

        if ($maxLongEdge !== null && ($width > $maxLongEdge || $height > $maxLongEdge)) {
            if ($width >= $height) {
                $transformParams['width'] = $maxLongEdge;
            } else {
                $transformParams['height'] = $maxLongEdge;
            }
        }

        if ($maxPatches !== null) {
            $patchCount = ceil($width / 32) * ceil($height / 32);
            if ($patchCount > $maxPatches) {
                $scaleFactor = sqrt($maxPatches / $patchCount);
                $scaledLongEdge = (int) floor(max($width, $height) * $scaleFactor);
                if ($width >= $height) {
                    $transformParams['width'] = $scaledLongEdge;
                } else {
                    $transformParams['height'] = $scaledLongEdge;
                }
            }
        }

        if ($maxTokens !== null) {
            $tokenCount = ($width * $height) / 750;
            if ($tokenCount > $maxTokens) {
                $scaleFactor = sqrt(($maxTokens * 750) / ($width * $height));
                $scaledLongEdge = (int) floor(max($width, $height) * $scaleFactor);
                if ($width >= $height) {
                    $transformParams['width'] = $scaledLongEdge;
                } else {
                    $transformParams['height'] = $scaledLongEdge;
                }
            }
        }

        if (empty($transformParams) && $asset->size > $maxFileSizeMb * 1024 * 1024) {
            Log::debug("{$asset->filename} is larger than {$maxFileSizeMb}MB, setting transform quality to 75");
            $transformParams['quality'] = 75;
        }

        if (!empty($transformParams)) {
            $transformParams['mode'] = 'fit';
        }

        return $transformParams;
    }
}
