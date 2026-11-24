<?php

namespace heavymetalavo\craftaialttext\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\{App, FileHelper, ImageTransforms, Json, UrlHelper};
use craft\image\Raster;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use heavymetalavo\craftaialttext\AiAltText;
use Imagine\Image\{Box, Point};
use Imagick;
use ImagickPixel;
use ImagickPixelException;

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
     * @var string Added to the trigger when flattenTransparency() has filled the image's transparent areas, so the model
     * knows the fill isn't part of the image. %s is the fill's colour.
     */
    protected const TRANSPARENCY_NOTE = 'The image has a transparent background, filled with %s here so you can see what is drawn.';

    /**
     * @var int Default request timeout in seconds, used unless the project sets its own `timeout`
     * in config/guzzle.php.
     */
    public const DEFAULT_TIMEOUT = 30;

    /**
     * @var int How many bytes of a GIF to scan for multiple frames (2MB). See isAnimatedGif().
     */
    private const GIF_FRAME_SCAN_BYTES = 2097152;

    /**
     * @var Client
     */
    protected Client $client;

    /**
     * @var string The provider's name in log lines and error messages, set by each provider service.
     */
    protected string $providerLabel = 'API';

    /**
     * @var string|null The colour flattenTransparency() filled the image's transparent areas with, or null if it didn't.
     * Each provider resets it before preparing an image.
     */
    protected ?string $transparencyFill = null;

    public function __construct($config = [])
    {
        parent::__construct($config);

        // Craft::createGuzzleClient() merges as ArrayHelper::merge($defaults, $guzzleConfig,
        // $config) - the array passed here goes LAST. Passing a timeout unconditionally therefore
        // overrode whatever the project set in config/guzzle.php, making the timeout impossible to
        // change. Only supply a default when the project hasn't specified one.
        $guzzleConfig = Craft::$app->getConfig()->getConfigFromFile('guzzle');

        $this->client = Craft::createGuzzleClient(
            isset($guzzleConfig['timeout']) ? [] : ['timeout' => self::DEFAULT_TIMEOUT]
        );
    }
    /**
     * Logs, at debug level, where a request is going and what it carries. Base64 image data is cut short so the
     * model, instructions, detail and reasoning stay readable, and the images' type, size and weight are given
     * beside the endpoint, outside the request body.
     */
    protected function logRequest(string $url, array $body): void
    {
        if (!(defined('YII_DEBUG') && YII_DEBUG)) {
            return;
        }

        $images = [];
        $trimmed = $this->trimBase64ForLog($body, $images);

        Craft::debug(
            $this->providerLabel . ' request to ' . $url . ($images ? ' with ' . implode(', ', $images) : '') . ': ' . Json::encode($trimmed),
            static::class
        );
    }

    /**
     * Logs, at debug level, the status, time and body of a response. The body is trimmed because OpenRouter pads a
     * slow response with leading whitespace (a keep-alive while the model works), which would fill the log with blank lines.
     */
    protected function logResponse(string $url, int $status, string $body, float $seconds): void
    {
        Craft::debug(sprintf('%s response from %s: HTTP %d in %.3fs: %s', $this->providerLabel, $url, $status, $seconds, trim($body)), static::class);
    }

    /**
     * Cuts base64 image data in a request body down to its first characters, and adds a description of each image
     * (e.g. `image/jpeg 1341x894, 87 KB`) to $images.
     *
     * @param string[] $images
     */
    private function trimBase64ForLog(mixed $value, array &$images): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->trimBase64ForLog($item, $images);
            }

            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        $prefix = '';
        $base64 = null;
        if (preg_match('#^(data:[\w/+.-]+;base64,)(.+)$#s', $value, $matches)) {
            [, $prefix, $base64] = $matches;
        } elseif (strlen($value) > 5000 && preg_match('#^[A-Za-z0-9+/=\s]+$#', $value)) {
            $base64 = $value;
        }

        if ($base64 === null) {
            return $value;
        }

        $binary = base64_decode($base64, true);
        $info = $binary !== false ? @getimagesizefromstring($binary) : false;
        $images[] = sprintf(
            '%s %s, %d KB',
            $info['mime'] ?? 'image of unknown type',
            $info ? $info[0] . 'x' . $info[1] : 'unknown size',
            (int) round(strlen((string) $binary) / 1024)
        );

        return $prefix . substr($base64, 0, 40) . '…';
    }

    /**
     * The text sent with the image: GENERATE_TRIGGER, and TRANSPARENCY_NOTE if the image's transparent areas were filled,
     * e.g. `Generate the alt text for this image now. The image has a transparent background, filled with white here so
     * you can see what is drawn.`
     */
    protected function generateTrigger(): string
    {
        return self::GENERATE_TRIGGER . ($this->transparencyFill !== null ? ' ' . sprintf(self::TRANSPARENCY_NOTE, $this->transparencyFill) : '');
    }

    /**
     * Required implementation for child services to generate their specific payloads.
     */
    abstract public function generateAltText(Asset $asset, ?int $siteId = null, bool $forceBase64 = false): string;

    /**
     * Resolves the configured prompt template into a final instruction string, substituting
     * {asset.*} and {site.*} variables.
     *
     * {site.languageName} resolves to the site language's display name, e.g. "English (United
     * Kingdom)" — the same value Craft shows in its language dropdown ($locale->getDisplayName(
     * Craft::$app->language)). It needs a dedicated case because it maps to a method chain rather
     * than a property. The BCP 47 language tag itself is available via the ordinary {site.language}
     * variable, so the default prompt pairs them ("{site.languageName} (BCP 47: {site.language})") and
     * the format stays visible and editable in the prompt. Other {site.*} / {asset.*} variables resolve
     * to the matching property.
     *
     * @todo Consider making $siteId a required `int` and dropping the null branch below — in
     *       practice $siteId is never null (every caller resolves a concrete site). Doing it
     *       cleanly means tightening ?int → int across the call chain (the abstract
     *       generateAltText(), ResponsesApiService & AnthropicService generateAltText()/sendRequest(),
     *       and AiAltTextService::generateAltText()), with the one genuine guard at the queue job,
     *       whose siteId payload is legitimately nullable (`$this->siteId ?? $asset->siteId`).
     * @throws Exception If an explicitly requested site no longer exists, or a prompt variable can't be resolved.
     */
    protected function resolvePrompt(Asset $asset, ?int $siteId): string
    {
        $promptTemplate = App::parseEnv(AiAltText::getInstance()->getSettings()->prompt);

        $prompt = preg_replace_callback('/{asset\.(.*?)}/', function ($matches) use ($asset) {
            return $this->resolvePromptVariable($asset, $matches[1], $matches[0]);
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
            return $this->resolvePromptVariable($site, $matches[1], $matches[0]);
        }, $prompt);

        return $prompt;
    }

    /**
     * Resolves a single `{asset.*}` / `{site.*}` prompt variable to a string.
     *
     * Deliberately not restricted to an allowlist - reading arbitrary properties, including custom
     * field values, is a legitimate use of the prompt field. But a mistyped variable throws
     * UnknownPropertyException, and an object-valued one (`{asset.volume}`, `{site.locale}`) throws
     * on string conversion. Both are settings mistakes that would repeat for every asset, so they
     * fail before any API call is made, with a message naming the variable and the setting to fix.
     * A property that is simply empty (null) resolves to an empty string.
     *
     * @param object $model The asset or site the variable refers to
     * @param string $property The property name from inside the variable
     * @param string $original The full variable as written, used in the error message
     * @throws Exception If the variable doesn't resolve to text
     */
    private function resolvePromptVariable(object $model, string $property, string $original): string
    {
        try {
            $value = $model->$property;
        } catch (\Throwable $e) {
            throw new Exception(
                "Prompt variable \"{$original}\" could not be resolved. Check the Prompt setting.",
                0,
                $e
            );
        }

        if ($value === null || is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new Exception(sprintf(
            'Prompt variable "%s" resolves to a %s, which cannot be used in a prompt. Check the Prompt setting.',
            $original,
            get_debug_type($value)
        ));
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
     * SVGs are only checked against the Process SVGs setting and Craft's image driver (see canProcessSvgs()). The plugin
     * renders them itself (see getSvgBase64()), not through Craft's transforms, so Craft's `transformSvgs` setting
     * doesn't apply.
     *
     * @param Asset $asset The asset to validate
     * @throws Exception If the asset format is invalid or fundamentally unsupported
     */
    protected function validateImageSupport(Asset $asset): bool
    {
        $mimeType = $asset->getMimeType();

        if ($mimeType === 'image/svg+xml' && !AiAltText::getInstance()->aiAltTextService->canProcessSvgs()) {
            Craft::info("Skipping SVG asset (processSvgs off, or Craft's image driver can't read SVGs): $asset->filename", __METHOD__);
            return false;
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
     * Only the first GIF_FRAME_SCAN_BYTES of the file are read, a small piece at a time. If that
     * isn't enough to decide, the GIF is reported as animated: converting a static GIF to a JPG is
     * harmless, but sending an animated one on unconverted would be rejected by the provider.
     *
     * @param Asset $asset The asset to check
     * @return bool Whether the GIF is animated
     */
    protected function isAnimatedGif(Asset $asset): bool
    {
        // Only GIFs can be animated, so anything else is a quick "no".
        $mimeType = $asset->getMimeType();
        if ($mimeType !== 'image/gif') {
            return false;
        }

        // Open the file as a stream so it can be read in small pieces, never all at once.
        $stream = $asset->getStream();

        try {
            $found = 0;     // How many frame markers have been seen so far.
            $read = 0;      // How many bytes have been read so far.
            $carry = '';    // The last 2 bytes of the previous piece (see below).

            // Keep reading until we hit the size limit or the end of the file.
            while ($read < self::GIF_FRAME_SCAN_BYTES && !feof($stream)) {
                // Read the next 8KB piece. Only this piece is held in memory.
                $chunk = fread($stream, 8192);

                // Nothing came back, so there is no more file to read.
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $read += strlen($chunk);

                // Every frame starts with this 3-byte marker. The leftover bytes from the last piece
                // are put in front so a marker split across two pieces is still counted.
                $found += substr_count($carry . $chunk, "\x21\xF9\x04");

                // Two markers means two frames, so it's animated and we can stop reading.
                if ($found > 1) {
                    return true;
                }

                // Remember the last 2 bytes for the next piece, even if this piece was very short.
                $carry = substr($carry . $chunk, -2);
            }

            // Stopped at the size limit with file left unread: we can't rule out animation, so assume it.
            // Stopped at the end of the file with fewer than two markers: it's a single-frame GIF.
            return $read >= self::GIF_FRAME_SCAN_BYTES && !feof($stream);
        } finally {
            // Always close the file, however the function exits.
            fclose($stream);
        }
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
     * Renders the first frame of an animated GIF as a JPG, scaled to fit $transformParams and with any transparent areas
     * filled by flattenTransparency(), and returns it base64 encoded.
     *
     * Craft's own JPG transform of an animated GIF stacks its frames on the Imagick driver, leaving the last one showing,
     * so the first frame is rendered here instead. GD only reads the first frame, so it's unaffected.
     *
     * @param Asset $asset The animated GIF
     * @param array $transformParams The transform params from getVisionTransformParams(), for the width and height
     * @return string The base64 encoded JPG
     */
    protected function getFirstFrameBase64(Asset $asset, array $transformParams): string
    {
        /** @var Raster $image */
        $image = Craft::$app->getImages()->loadImage(ImageTransforms::getLocalImageSource($asset));
        $image->disableAnimation()->scaleToFit($transformParams['width'] ?? null, $transformParams['height'] ?? null, false);
        $this->flattenTransparency($image);

        $path = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . FileHelper::uniqueName($asset->getFilename(false) . '.jpg');
        $image->saveAs($path);

        try {
            return base64_encode(file_get_contents($path));
        } finally {
            FileHelper::unlink($path);
        }
    }

    /**
     * Renders an image with transparency as a JPG, its transparent areas filled by flattenTransparency(), scaled to fit
     * $transformParams, and returns it base64 encoded.
     *
     * Models don't reliably see transparency. Some, such as GPT and Gemini, see the colour each transparent pixel still
     * stores: often the colour of what's drawn, so a black line on transparent comes out as a solid black square, but
     * it can be anything, even a hidden image that would then be described. Others, such as Claude, see white, which
     * hides white artwork. Filling the transparent areas ourselves means every model sees the same, chosen background.
     * Animated GIFs and SVGs are flattened by getFirstFrameBase64() and getSvgBase64() instead.
     *
     * @param Asset $asset The image, checked before a transform is set on it
     * @param array $transformParams The transform params from getVisionTransformParams(), for the width and height
     * @return string|null The base64 encoded JPG, or null if the image has no transparency
     */
    protected function getFlattenedBase64(Asset $asset, array $transformParams): ?string
    {
        // JPGs can't be transparent
        if ($asset->getMimeType() === 'image/jpeg') {
            return null;
        }

        $image = Craft::$app->getImages()->loadImage(ImageTransforms::getLocalImageSource($asset));

        // An SVG whose MIME type Craft misreads, e.g. as text/html, loads as an Svg and is left to Craft's transform
        if (!$image instanceof Raster) {
            return null;
        }

        // An animated WebP would have its frames stacked, leaving the last one showing, so only its first frame is
        // kept, as for GIFs
        $image->disableAnimation()->scaleToFit($transformParams['width'] ?? null, $transformParams['height'] ?? null, false);

        if (!$this->flattenTransparency($image)) {
            return null;
        }

        $path = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . FileHelper::uniqueName($asset->getFilename(false) . '.jpg');
        $image->saveAs($path);

        try {
            return base64_encode(file_get_contents($path));
        } finally {
            FileHelper::unlink($path);
        }
    }

    /**
     * Fills an image's transparent areas with white, or with mid grey if what's drawn is mostly light, so a white logo
     * doesn't disappear. Models don't reliably see transparency, see getFlattenedBase64().
     *
     * @param Raster $image The image
     * @return bool Whether the image had any transparency
     */
    protected function flattenTransparency(Raster $image): bool
    {
        // A small copy is enough to tell whether any of it is transparent, and how light the rest is
        $sample = $image->getImagineImage()->copy()->resize(new Box(64, 64));
        $transparent = false;
        $lightness = 0;
        $opacity = 0;

        for ($x = 0; $x < 64; $x++) {
            for ($y = 0; $y < 64; $y++) {
                $colour = $sample->getColorAt(new Point($x, $y));
                // CMYK colours have no alpha, and are always opaque
                $alpha = $colour->isOpaque() ? 1 : $colour->getAlpha() / 100;
                $transparent = $transparent || $alpha < 1;

                // Gray and RGB colours both print as #rrggbb
                [$r, $g, $b] = sscanf((string)$colour, '#%2x%2x%2x');
                $lightness += $alpha * (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
                $opacity += $alpha;
            }
        }

        if (!$transparent) {
            return false;
        }

        // Grey shows light content better than white does once it's lighter than 75%
        [$background, $this->transparencyFill] = $opacity > 0 && $lightness / $opacity > 0.75 ? ['#808080', 'grey'] : ['#ffffff', 'white'];
        $image->scaleToFitAndFill($image->getWidth(), $image->getHeight(), $background, 'center-center', false);

        return true;
    }

    /**
     * Renders an SVG as a PNG, its transparent areas filled by flattenTransparency(), and returns it base64 encoded.
     *
     * Craft loads SVGs into Imagick from a string, which ImageMagick often renders with its own built-in SVG renderer,
     * and that renderer draws gradients as solid black. So the SVG is rendered here, with its gradients flattened first
     * where ImageMagick can't draw them, see canRenderSvgGradients() and flattenSvgGradients(). Craft also draws SVGs
     * onto white, which hides white artwork, so here they're drawn onto transparent and filled by flattenTransparency().
     * Rendering it here also works for SVGs on volumes without public URLs, whose transforms Craft can't hand over.
     *
     * @param Asset $asset The SVG
     * @param array $transformParams The transform params from getVisionTransformParams(), for the width and height
     * @return string The base64 encoded PNG
     */
    protected function getSvgBase64(Asset $asset, array $transformParams): string
    {
        $images = Craft::$app->getImages();
        $svg = $asset->getContents();

        // Only Imagick reaches here, as SVGs are skipped on GD, see canProcessSvgs()
        if (!$this->canRenderSvgGradients()) {
            $svg = $this->flattenSvgGradients($svg);
        }

        // Craft's image classes load from files, so the SVG and the PNG drawn from it go in uniquely named temp
        // files, deleted at the end
        $path = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . FileHelper::uniqueName($asset->getFilename(false));
        FileHelper::writeToFile("$path.svg", $svg);

        try {
            // Rendered at the provider's size limit if the SVG is larger, otherwise at 1000px, as Craft's transforms do
            $size = max($transformParams['width'] ?? 0, $transformParams['height'] ?? 0) ?: 1000;

            // Craft's Svg sets the SVG's width and height to $size, still as SVG code, then Imagick draws it with the
            // server's SVG renderer. It's drawn onto transparent, as Craft's own drawing uses white, which hides white
            // artwork, and saved as png32, a PNG that keeps the transparency for flattenTransparency() to fill.
            $imagick = new Imagick();
            $imagick->setBackgroundColor(new ImagickPixel('transparent'));
            $imagick->readImageBlob($images->loadImage("$path.svg")->scaleToFit($size, $size)->getSvgString());
            $imagick->writeImage("png32:$path.png");
            $image = $images->loadImage("$path.png");

            $this->flattenTransparency($image);
            $image->saveAs("$path.png");

            return base64_encode(file_get_contents("$path.png"));
        } finally {
            FileHelper::unlink("$path.svg");
            FileHelper::unlink("$path.png");
        }
    }

    /**
     * Whether ImageMagick draws gradients in an SVG read from a string, as Craft reads them. Its built-in SVG renderer
     * draws them as solid black. ImageMagick built with librsvg draws them, and so does Inkscape when it's installed,
     * with ImageMagick 6 or 7.1.2-34 and later, as earlier ImageMagick 7 releases can't pass Inkscape an SVG read from a
     * string. Anything else is assumed to draw them black, so they're flattened rather than sent that way.
     *
     * @return bool Whether gradients are drawn
     */
    protected function canRenderSvgGradients(): bool
    {
        if (!empty(Imagick::queryFormats('RSVG'))) {
            return true;
        }

        if (!preg_match('/ImageMagick (\d+\.\d+\.\d+)-(\d+)/', Imagick::getVersion()['versionString'], $match)) {
            return false;
        }

        $version = "$match[1].$match[2]";
        if (!str_starts_with($version, '6.') && version_compare($version, '7.1.2.34', '<')) {
            return false;
        }

        // PHP-FPM often clears PATH, and ImageMagick's shell then looks in the standard directories
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin') as $dir) {
            // @ as open_basedir can block the check, which then counts as Inkscape not being installed
            if (@is_executable("$dir/inkscape")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replaces each gradient used as a fill or stroke in an SVG with the average of its stop colours, so ImageMagick's
     * built-in SVG renderer, which draws gradients as solid black, shows about the right colour. The SVG is returned
     * unchanged if it can't be parsed.
     *
     * For example, `fill="url(#a)"`, where gradient `a` runs from `#ffeb3b` to `#ff9800`, becomes `fill="#ffc21e"`. The
     * gradient elements themselves are left in place, unused.
     *
     * @param string $svg The SVG markup
     * @return string The SVG markup with solid colours in place of gradients
     */
    protected function flattenSvgGradients(string $svg): string
    {
        $doc = new DOMDocument();
        // LIBXML_NONET stops the parser fetching anything from the network, such as an external DTD
        if (!@$doc->loadXML($svg, LIBXML_NONET)) {
            return $svg;
        }

        // XPath queries find elements anywhere in the SVG, however deeply nested
        $xpath = new DOMXPath($doc);

        // local-name() matches the gradients whether or not the SVG declares a namespace prefix
        $gradients = [];
        foreach ($xpath->query('//*[local-name()="linearGradient" or local-name()="radialGradient"]') as $gradient) {
            $gradients[$gradient->getAttribute('id')] = $gradient;
        }

        // Nothing to flatten, so the SVG is returned exactly as it was, rather than re-serialised
        if (empty($gradients)) {
            return $svg;
        }

        // Each gradient's average colour, by id, or null when it has no colour Imagick can read
        $colours = [];
        foreach (array_keys($gradients) as $id) {
            $colours[$id] = $this->averageGradientColour($gradients, (string)$id);
        }

        // A function, used by both loops below. Swaps each url(#id) that points at a gradient for its average colour.
        // Anything else, such as a pattern or a gradient without a readable colour, is left as it is. The pattern
        // matches url(#a), url('#a') and url("#a"), with or without spaces inside the brackets, and captures the id
        $replace = fn(string $value): string => preg_replace_callback(
            '/url\(\s*[\'"]?#([^\'")\s]+)[\'"]?\s*\)/',
            fn(array $match) => $colours[$match[1]] ?? $match[0],
            $value
        );

        // Fills and strokes can be set as attributes, e.g. fill="url(#a)", or in a style attribute, e.g.
        // style="fill:url(#a)". Each one is read, has its gradient references swapped for colours by $replace, and is
        // written back, so fill="url(#a)" becomes fill="#ffc21e". Values without a gradient come back unchanged
        foreach ($xpath->query('//*[@fill or @stroke or @style]') as $element) {
            foreach (['fill', 'stroke', 'style'] as $attribute) {
                if ($element->hasAttribute($attribute)) {
                    $element->setAttribute($attribute, $replace($element->getAttribute($attribute)));
                }
            }
        }

        // Illustrator, among others, can set fills in a <style> element instead
        foreach ($xpath->query('//*[local-name()="style"]') as $style) {
            $style->textContent = $replace($style->textContent);
        }

        // Back to SVG markup, with the gradients swapped for solid colours
        return $doc->saveXML();
    }

    /**
     * The average of a gradient's stop colours as a hex colour. A gradient without stops of its own takes them from
     * the gradient it links to with `href`, as Illustrator exports often do.
     *
     * @param DOMElement[] $gradients The SVG's gradients, by id
     * @param string $id The gradient's id
     * @param int $depth How many `href` links have been followed, to stop at a loop
     * @return string|null The colour, or null if the gradient has no stops with a colour Imagick can read
     */
    private function averageGradientColour(array $gradients, string $id, int $depth = 0): ?string
    {
        // An id that isn't a gradient in this SVG, or a chain of href links that loops, has no colour
        $gradient = $gradients[$id] ?? null;
        if ($gradient === null || $depth > 10) {
            return null;
        }

        // Each <stop> sets one of the gradient's colours, as RGB channels from 0 to 255
        $colours = [];
        foreach ($gradient->childNodes as $stop) {
            // Skips whitespace, comments and anything else that isn't a <stop>
            if (!$stop instanceof DOMElement || $stop->localName !== 'stop') {
                continue;
            }

            // A stop's colour can be in its style attribute, e.g. style="stop-color:#ff9800", which wins over a
            // stop-color attribute: the pattern pulls the value out of the style, and if there isn't one, the
            // stop-color attribute is read instead
            $colour = preg_match('/stop-color\s*:\s*([^;]+)/', $stop->getAttribute('style'), $match)
                ? trim($match[1])
                : $stop->getAttribute('stop-color');

            try {
                // ImagickPixel reads any CSS colour, e.g. #ff9800, #f90, rgb(255, 152, 0) or orange, and getColor()
                // returns its channels, e.g. ['r' => 255, 'g' => 152, 'b' => 0, 'a' => 1]. An unset stop-color is black
                $colours[] = (new ImagickPixel($colour ?: 'black'))->getColor();
            } catch (ImagickPixelException) {
                // A colour Imagick can't read, e.g. currentColor
            }
        }

        // No stops of its own, so it borrows them from the gradient it links to, e.g. href="#b". Inkscape and
        // Illustrator exports often give each shape its own gradient for its position and angle, linking to one
        // shared gradient that holds the colours, so without following the link these shapes would get no colour and
        // stay black. SVG 2 uses href, older SVGs xlink:href
        if (empty($colours)) {
            $href = $gradient->getAttribute('href') ?: $gradient->getAttributeNS('http://www.w3.org/1999/xlink', 'href');

            // This method calls itself for the linked gradient, with $depth one higher, so a chain of links is
            // followed until a gradient has stops. A link to another file, rather than to #id, gives no colour
            return str_starts_with($href, '#') ? $this->averageGradientColour($gradients, substr($href, 1), $depth + 1) : null;
        }

        // The mean of each channel across the stops, as a hex colour, e.g. #ffeb3b and #ff9800 give #ffc21e. For each
        // of r, g and b, array_column() takes that channel from every stop, which is averaged and rounded, and the
        // three results are passed to sprintf() (the ... spreads the array into separate arguments) as 2-digit hex
        return sprintf('#%02x%02x%02x', ...array_map(
            fn(string $channel) => (int)round(array_sum(array_column($colours, $channel)) / count($colours)),
            ['r', 'g', 'b']
        ));
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
        // SVG, AVIF and HEIC are converted to PNG, which is lossless. Any transparency is filled before this transform is
        // used, see getFlattenedBase64() and getSvgBase64()
        $preferPng = $aiAltTextService->isSvg($asset) || $aiAltTextService->isAvif($asset) || $aiAltTextService->isHeic($asset);
        
        // Set up transform parameters
        $transformParams = [];
        
        // Decide if we need to convert the image to a different format
        $needsFormatConversion = $this->needsFormatConversion($asset);
        
        // Always convert format if needed, regardless of dimensions
        if ($needsFormatConversion) {
            $transformParams['format'] = $preferPng ? 'png' : 'jpg';
        }
        
        // Animated GIFs are sent as a JPG of their first frame, rendered by getFirstFrameBase64(), so the format matches
        if ($this->isAnimatedGif($asset)) {
            Craft::info("Asset {$asset->filename} is an animated GIF. Sending its first frame as a JPG.", __METHOD__);
            $transformParams['format'] = 'jpg';
        }
        
        // Get original image dimensions
        $width = $asset->getWidth();
        $height = $asset->getHeight();
        
        // Each limit gives the longest edge it allows, and the smallest wins: the provider's long edge, OpenAI's patch
        // budget and Anthropic's token budget. Craft's 'fit' mode preserves the aspect ratio
        $longEdge = max($width, $height);
        $limit = $longEdge;

        // The provider's long edge, e.g. 500px for Anthropic's low detail level
        if ($maxLongEdge !== null) {
            $limit = min($limit, $maxLongEdge);
        }

        // OpenAI patch budget: if the image would produce more tiles than allowed, scale down proportionally
        if ($maxPatches !== null) {
            $patchCount = ceil($width / 32) * ceil($height / 32);
            if ($patchCount > $maxPatches) {
                $limit = min($limit, (int) floor($longEdge * sqrt($maxPatches / $patchCount)));
            }
        }

        // Anthropic token budget: if the pixel area would exceed the token limit, scale down proportionally
        if ($maxTokens !== null) {
            $tokenCount = ($width * $height) / 750;
            if ($tokenCount > $maxTokens) {
                $limit = min($limit, (int) floor($longEdge * sqrt(($maxTokens * 750) / ($width * $height))));
            }
        }

        if ($limit < $longEdge) {
            if ($width >= $height) {
                // Wide/square image: cap the width
                $transformParams['width'] = $limit;
            } else {
                // Tall image: cap the height
                $transformParams['height'] = $limit;
            }
        }
        
        // If the file exceeds the provider's max payload and no other transform has been set, reduce quality
        if (empty($transformParams) && $asset->size > $maxFileSizeMb * 1024 * 1024) {
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
