# 🖼️ Images

[← README](../README.md) · [Getting started](getting-started.md) · [AI providers](providers.md) · [Settings](settings.md) · [Console commands](console-commands.md) · **Images** · [Costs](costs.md)

How the plugin prepares images for each AI provider, and the limits to know about.

## Provider-Specific Limits

- **Automatic Scaling**: The plugin automatically detects when an image exceeds provider limits and applies transforms (resizing or quality reduction) before sending the payload.
- **Supported file types**: Both AI providers support: `png`, `jpeg`, `jpg`, `webp`, `gif` (non-animated)
- **SVG Support**: SVGs are rasterized to PNG (preserving transparency) before being sent to the AI (where transformSvgs is enabled).
- **AVIF, HEIC & HEIF Support**: These image types are converted to PNG before being sent to an AI provider. This requires an image driver (ImageMagick built with AVIF/HEIF/HEIC support) that can decode them on environments without that support these assets are skipped.
- **Animated GIFs**: Only the first frame is processed.
- **Private Assets**: Assets on private volumes without public URLs can be sent as base64 encoded strings. Retrieving an image transform's file contents is not currently supported by CraftCMS. Meaning that for file types not supported by an AI provider (e.g. an SVG files) it is not possible to generate alt text for these private assets.
- **Servd/Cloud**: Support for specialized asset bundles (like Servd) depends on the environment's ability to handle raster transforms.
- **Consider AI Provider level boundaries**: e.g. OpenAI: "No watermarks or logos - No NSFW content - Clear enough for a human to understand"

## Oddities, Logic & Limitations

- The OpenAI API has [image input requirements](https://platform.openai.com/docs/guides/images-vision?api-mode=responses#image-input-requirements) which over the lifespan of this plugin have changed without notice, the requirements are not always enforced, e.g. base64 encoded images above the required image dimensions have been accepted by the API.
- Craft CMS uses the [Imagine library](https://github.com/php-imagine/Imagine) for image processing, which can only transform [select image formats](https://github.com/php-imagine/Imagine/blob/develop/src/Image/Format.php#L14-L32). Support for these formats may vary depending on what ImageMagick drivers are available in your environment. If a source image is in a supported format, the plugin will generate a transform in one of the supported file types to send to the OpenAI API.
- Where an unsupported file type is requested the plugin will attempt an image transform to a jpg to be sent instead
- The plugin checks a file's mimetype to see if it's valid, or if it needs a format conversion before sending to the API
- If an asset's dimensions are larger than the dimensions required by the API an image transform is sent instead
- If an asset has no URL (private) and requires a transform (e.g. if the original asset is an unsupported mime type, or, the dimensions are too large), Craft may be unable to retrieve the transform's file contents for base64 encoding. In that case the plugin will not fall back to sending the original source file unless its MIME type is natively accepted by the AI provider.
- Where an alternative image transformer is used, e.g. when an application is hosted on [Servd](https://servd.host) and assets are processed through their asset platform this may not support svg -> raster transforms
