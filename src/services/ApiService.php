<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use heavymetalavo\craftaialttext\AiAltText;

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
     * @var string Minimal user-turn trigger sent alongside the image. The substantive instructions
     * live in the provider's system/instructions field (see resolvePrompt()).
     */
    protected const GENERATE_TRIGGER = 'Generate the alt text for this image now.';

    /**
     * @var Client
     */
    protected Client $client;

    public function __construct($config = [])
    {
        parent::__construct($config);
        $this->client = Craft::createGuzzleClient(['timeout' => 30]);
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
     * Craft::$app->language)). It needs a dedicated case because it maps to a method chain rather
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
        $promptTemplate = App::parseEnv(AiAltText::getInstance()->getSettings()->prompt);

        $prompt = preg_replace_callback('/{asset\.(.*?)}/', function ($matches) use ($asset) {
            return $asset->{$matches[1]};
        }, $promptTemplate);

        // A null $siteId means "no particular site requested" — use the asset's own site. But an
        // explicit $siteId that no longer resolves (e.g. a queued job running after the site was
        // deleted) must fail loudly rather than silently generating in another site's language.
        $site = $siteId !== null ? Craft::$app->getSites()->getSiteById($siteId) : $asset->getSite();
        if ($site === null) {
            throw new Exception("Cannot generate alt text for {$asset->filename}: site (ID: {$siteId}) could not be found.");
        }
        $prompt = preg_replace_callback('/{site\.(.*?)}/', function ($matches) use ($site) {
            if ($matches[1] === 'languageName') {
                return $site->getLocale()->getDisplayName(Craft::$app->language);
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
        if (UrlHelper::isProtocolRelativeUrl($url)) {
            return UrlHelper::urlWithScheme($url, 'https');
        }

        // Return absolute URLs unchanged
        if (UrlHelper::isAbsoluteUrl($url)) {
            return $url;
        }

        // Root-relative volume URLs are relative to the domain origin, not to a path-based site URL. e.g. `/local/image.jpg` becomes `https://example.com/local/image.jpg` (even when the asset's site base URL includes `/en/`)
        if (UrlHelper::isRootRelativeUrl($url)) {
            $site = Craft::$app->getSites()->getSiteById($asset->siteId);
            $siteBaseUrl = $site?->getBaseUrl() ?: UrlHelper::baseSiteUrl();
            $hostInfo = UrlHelper::hostInfo($siteBaseUrl);

            // A path-only site base URL (e.g. `/en-US`) has no host info, and in console/queue
            // requests hostInfo() cannot fall back to the current request's host — use the
            // primary site's host instead so the URL is absolute for Guzzle and providers.
            if (empty($hostInfo)) {
                $hostInfo = UrlHelper::hostInfo(UrlHelper::baseSiteUrl());
            }

            return rtrim($hostInfo, '/') . $url;
        }

        // Normal relative URLs are resolved against the asset's site base URL.
        return UrlHelper::siteUrl($url, null, null, $asset->siteId);
    }

    /**
     * Validates if the asset is an accepted image format natively.
     *
     * @param Asset $asset The asset to validate
     * @param bool $allowSvgs Whether the endpoint natively supports SVGs or relies on Craft's SVG transform pipeline
     * @throws Exception If the asset format is invalid or fundamentally unsupported
     */
    protected function validateImageSupport(Asset $asset, bool $allowSvgs = false): bool
    {
        $mimeType = $asset->getMimeType();

        if ($mimeType === 'image/svg+xml') {
            if (!AiAltText::getInstance()->getSettings()->processSvgs) {
                Craft::info("Skipping SVG asset (processSvgs disabled): $asset->filename", __METHOD__);
                return false;
            }

            if (!$allowSvgs) {
                if (!Craft::$app->getConfig()->getGeneral()->transformSvgs) {
                    Craft::warning("SVG asset $asset->filename requires transformSvgs to be enabled in Craft general config to be processed; skipping.", __METHOD__);
                    return false;
                }
                
                Craft::debug("SVG asset $asset->filename is not natively supported by the provider, will attempt transformation.", __METHOD__);
            }
        }

        $images = Craft::$app->getImages();

        if ($mimeType === 'image/avif' && !$images->getSupportsAvif()) {
            Craft::warning("AVIF asset $asset->filename cannot be processed: the image driver does not support AVIF; skipping.", __METHOD__);
            return false;
        }

        if (in_array($mimeType, ['image/heic', 'image/heif'], true) && !$images->getSupportsHeic()) {
            Craft::warning("HEIC asset $asset->filename cannot be processed: the image driver does not support HEIC/HEIF; skipping.", __METHOD__);
            return false;
        }
        
        return true;
    }

    /**
     * Checks if a GIF asset contains multiple frames (animated).
     *
     * @param Asset $asset The asset to check
     * @return bool Whether the GIF is animated
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
     * Checks if a specific MIME type is supported by AI Vision bounds limit mappings
     *
     * @param string $mimeType
     * @return bool
     */
    protected function isAcceptedMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::ACCEPTED_MIME_TYPES);
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
     * Downloads the asset's (possibly transformed) URL via Guzzle and returns its base64 encoded string.
     * Falls back to the original asset file contents if the URL cannot be fetched.
     *
     * Note: passes null (not []) to getUrl() when $transformParams is empty so that any transform
     * previously applied via setTransform() is respected. Passing [] would clear the active transform
     * and return the original file URL instead.
     *
     * @param Asset $asset The original asset
     * @param array $transformParams Transform params to pass to getUrl(), or empty to use active transform
     * @return string The base64 encoded data
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
                Craft::warning('Failed to download image URL for Base64 conversion: ' . $e->getMessage(), __METHOD__);
            }
        }

        // Reset the transform so the asset reports the original MIME type before
        // falling back to source bytes.
        $asset->setTransform(null);
        $originalMimeType = $asset->getMimeType();
        if (!$this->isAcceptedMimeType($originalMimeType)) {
            throw new Exception("Cannot generate alt text for {$asset->filename}: The transform or asset is not publicly available, or the image transform could not be downloaded, and the original format \"$originalMimeType\" is not supported natively by the AI provider.");
        }

        Craft::warning("Falling back to the source file contents of {$asset->filename} for base64 encoding: the (transformed) image URL was unavailable or could not be downloaded, and its source MIME type \"$originalMimeType\" is natively supported by the AI provider.", __METHOD__);

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
        // Clear any transform applied by a previous attempt (the base64 fallback re-runs
        // generateAltText with the same asset) so format and dimension detection below always
        // read the original source asset rather than an already-transformed state.
        $asset->setTransform(null);

        $aiAltTextService = AiAltText::getInstance()->aiAltTextService;
        // SVG, AVIF and HEIC can carry transparency, so convert those to PNG to preserve it
        $preferPng = $aiAltTextService->isSvg($asset) || $aiAltTextService->isAvif($asset) || $aiAltTextService->isHeic($asset);
        
        // Set up transform parameters
        $transformParams = [];
        
        // Decide if we need to convert the image to a different format
        $needsFormatConversion = $this->needsFormatConversion($asset);
        
        // Always convert format if needed, regardless of dimensions
        if ($needsFormatConversion) {
            $transformParams['format'] = $preferPng ? 'png' : 'jpg';
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
        
        // Applied independently of the other transform params. Gating this on "no other transform
        // has been set" meant an image needing both a resize and a quality reduction only ever got
        // the resize - so for Anthropic (5MB) a large photo over the long-edge limit kept full
        // quality and could still exceed the payload limit, which the base64 fallback can't fix.
        //
        // This is a heuristic on the *source* size: the transformed output's size isn't known
        // until it's generated, so it errs toward reducing quality.
        if ($asset->size > $maxFileSizeMb * 1024 * 1024) {
            Craft::debug("{$asset->filename} is larger than {$maxFileSizeMb}MB, setting transform quality to 75", __METHOD__);
            $transformParams['quality'] = 75;
        }
        
        // Set mode fit for all transforms, done here so that the array evaluates to empty if no resizing or formatting occurs
        if (!empty($transformParams)) {
            $transformParams['mode'] = 'fit';
        }

        return $transformParams;
    }

}
