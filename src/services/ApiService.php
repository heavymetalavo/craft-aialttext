<?php

namespace heavymetalavo\craftaialttext\services;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Cms;
use CraftCms\Cms\Image\ImageTransformHelper;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Facades\Images;
use CraftCms\Cms\Support\Facades\Sites;
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
     * @var string Minimal user-turn trigger sent alongside the image. The substantive instructions
     * live in the provider's system/instructions field (see resolvePrompt()).
     */
    protected const GENERATE_TRIGGER = 'Generate the alt text for this image now.';

    /**
     * @var Client
     */
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 30]);
    }

    /**
     * Required implementation for child services to generate their specific payloads.
     */
    abstract public function generateAltText(Asset $asset, ?int $siteId = null, bool $forceBase64 = false): string;

    /**
     * Resolves the configured prompt template into a final instruction string, substituting
     * {asset.*} and {site.*} placeholders.
     *
     * {site.languageName} resolves to the site language's display name, e.g. "English (United
     * Kingdom)" — the same value Craft shows in its language dropdown ($locale->getDisplayName(
     * app()->getLocale())). It needs a dedicated case because it maps to a method chain rather
     * than a property. The BCP 47 language tag itself is available via the ordinary {site.language}
     * token, so the default prompt pairs them ("{site.languageName} (BCP 47: {site.language})") and
     * the format stays visible and editable in the prompt. Other {site.*} / {asset.*} tokens resolve
     * to the matching property.
     *
     * @todo Consider making $siteId a required `int` and dropping the null branch below — in
     *       practice $siteId is never null (every caller resolves a concrete site). Doing it
     *       cleanly means tightening ?int → int across the call chain (the abstract
     *       generateAltText(), OpenAiService & AnthropicService generateAltText()/sendRequest(),
     *       and AiAltTextService::generateAltText()), with the one genuine guard at the queue job,
     *       whose siteId payload is legitimately nullable (`$this->siteId ?? $asset->siteId`).
     * @throws Exception If an explicitly requested site no longer exists.
     */
    protected function resolvePrompt(Asset $asset, ?int $siteId): string
    {
        $promptTemplate = Env::parse(AiAltText::settings()->prompt);

        $prompt = preg_replace_callback('/{asset\.(.*?)}/', function ($matches) use ($asset) {
            return $asset->{$matches[1]};
        }, $promptTemplate);

        // A null $siteId means "no particular site requested" — use the asset's own site. But an
        // explicit $siteId that no longer resolves (e.g. a queued job running after the site was
        // deleted) must fail loudly rather than silently generating in another site's language.
        $site = $siteId !== null ? Sites::getSiteById($siteId) : $asset->getSite();
        if ($site === null) {
            throw new Exception("Cannot generate alt text for {$asset->filename}: site (ID: {$siteId}) could not be found.");
        }
        $prompt = preg_replace_callback('/{site\.(.*?)}/', function ($matches) use ($site) {
            if ($matches[1] === 'languageName') {
                return $site->getLocale()->getDisplayName(app()->getLocale());
            }
            return $site->{$matches[1]};
        }, $prompt);

        return $prompt;
    }

    /**
     * Resolves an asset URL to an absolute URL for Guzzle and provider APIs.
     *
     * Return examples:
     * - `https://cdn.example.com/image.jpg` remains `https://cdn.example.com/image.jpg`
     * - `//cdn.example.com/image.jpg` becomes `https://cdn.example.com/image.jpg`
     * - `/local/image.jpg` becomes `https://example.com/local/image.jpg` (even when the asset's site base URL includes `/en/`
     * - `local/image.jpg` becomes `https://example.com/en/local/image.jpg` when the asset's site base URL includes `/en/`
     */
    protected function resolveAssetUrl(Asset $asset, string $url): string
    {
        // Force protocol-relative URLs to use https
        if (Url::isProtocolRelativeUrl($url)) {
            return Url::urlWithScheme($url, 'https');
        }

        // Return absolute URLs unchanged
        if (Url::isAbsoluteUrl($url)) {
            return $url;
        }

        // Root-relative volume URLs are relative to the domain origin, not to a path-based site URL. e.g. `/local/image.jpg` becomes `https://example.com/local/image.jpg` (even when the asset's site base URL includes `/en/`)
        if (Url::isRootRelativeUrl($url)) {
            $site = Sites::getSiteById($asset->siteId);
            $siteBaseUrl = $site?->getBaseUrl() ?: Url::baseSiteUrl();
            $hostInfo = Url::hostInfo($siteBaseUrl);

            // A path-only site base URL (e.g. `/en-US`) has no host info, and in console/queue
            // requests hostInfo() cannot fall back to the current request's host — use the
            // primary site's host instead so the URL is absolute for Guzzle and providers.
            if (empty($hostInfo)) {
                $hostInfo = Url::hostInfo(Url::baseSiteUrl());
            }

            return rtrim($hostInfo, '/') . $url;
        }

        // Normal relative URLs are resolved against the asset's site base URL.
        return Url::siteUrl($url, null, null, $asset->siteId);
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

        if ($mimeType === 'image/avif' && !Images::getSupportsAvif()) {
            Log::warning("AVIF asset $asset->filename cannot be processed: the image driver does not support AVIF; skipping.");
            return false;
        }

        if (in_array($mimeType, ['image/heic', 'image/heif'], true) && !Images::getSupportsHeic()) {
            Log::warning("HEIC asset $asset->filename cannot be processed: the image driver does not support HEIC/HEIF; skipping.");
            return false;
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
     *
     * @throws Exception If the URL is unavailable and the source format is not natively supported
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

        // Reset the transform so the asset reports the original MIME type before
        // falling back to source bytes.
        $asset->setTransform(null);
        $originalMimeType = $asset->getMimeType();
        if (!$this->isAcceptedMimeType($originalMimeType)) {
            throw new Exception("Cannot generate alt text for {$asset->filename}: The transform or asset is not publicly available, or the image transform could not be downloaded, and the original format \"$originalMimeType\" is not supported natively by the AI provider.");
        }

        Log::warning("Falling back to the source file contents of {$asset->filename} for base64 encoding: the (transformed) image URL was unavailable or could not be downloaded, and its source MIME type \"$originalMimeType\" is natively supported by the AI provider.");

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
        // Clear any transform applied by a previous attempt (the base64 fallback re-runs
        // generateAltText with the same asset) so format and dimension detection below always
        // read the original source asset rather than an already-transformed state.
        $asset->setTransform(null);

        $aiAltTextService = app(AiAltTextService::class);
        // SVG, AVIF and HEIC can carry transparency, so convert those to PNG to preserve it
        $preferPng = $aiAltTextService->isSvg($asset) || $aiAltTextService->isAvif($asset) || $aiAltTextService->isHeic($asset);

        $transformParams = [];

        $needsFormatConversion = $this->needsFormatConversion($asset);

        if ($needsFormatConversion) {
            $transformParams['format'] = $preferPng ? 'png' : 'jpg';
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
