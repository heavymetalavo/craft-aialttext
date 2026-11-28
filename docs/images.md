# 🖼️ Images

[← README](../README.md) · [Getting started](getting-started.md) · [AI providers](providers.md) · [Settings](settings.md) · [Console commands](console-commands.md) · **Images** · [Costs](costs.md)

How the plugin prepares images for each AI provider, and the limits to know about.

![How images are prepared. Your image, any JPG, PNG, WebP, GIF, SVG, AVIF, HEIC or HEIF asset on a public or private volume, is converted: SVG, AVIF, HEIC and HEIF are converted, animated GIFs use their first frame, and transparent areas are filled white, or grey for light artwork. It's scaled down if it's over the provider's limits, using your image detail setting. It's sent as a URL, or as base64 for SVGs, animated GIFs, transparent images, private volumes, OpenRouter and Langdock's Anthropic models. A vision model, through Anthropic, OpenAI, Langdock or OpenRouter, describes it and returns the alt text. Opaque PNG, JPG, WebP and still GIF images within the limits are sent unchanged, and filled images tell the model their background is transparent.](images/image-preparation.png)

## What AI providers accept

AI providers accept PNG, JPEG, WebP and GIF images, but not animated GIFs, and Google's Gemini API doesn't list GIF at all, so GIFs are sent to it as JPGs ([OpenAI](https://developers.openai.com/api/docs/guides/images-vision#image-input-requirements), [Anthropic](https://platform.claude.com/docs/en/build-with-claude/vision#supported-formats), [OpenRouter](https://openrouter.ai/docs/guides/overview/multimodal/image-understanding)). None of them accepts SVG, AVIF, HEIC or HEIF, or says how it handles transparency, so the plugin prepares images first:

- **Automatic Scaling**: The plugin automatically detects when an image exceeds provider limits and applies transforms (resizing or quality reduction) before sending the payload.
- **SVG Support**: When the **Process SVGs** setting is on, SVGs are rasterised by the plugin and sent as base64, whatever Craft's `transformSvgs` setting is. They need Craft's Imagick image driver, as GD can't read SVGs, and ImageMagick with SVG support. Without them, SVGs are skipped, with a warning on the settings page. ⚠️ For accurate colours the server needs an SVG renderer, see [SVG renderers](#svg-renderers) below.
- **Transparency**: In our tests, no model reliably saw transparency. Given a black line on a transparent background, GPT and Gemini models described "a solid black square": every transparent pixel still stores a colour, hidden by its transparency, and here it was black. Claude models saw white. So the plugin fills transparent areas with white, or grey for light artwork, and tells the model the background is transparent.
- **AVIF, HEIC & HEIF Support**: These image types are converted to WebP before being sent to an AI provider, or to PNG where the image driver can't write WebP. WebP is accepted by every provider and is usually far smaller than PNG: a HEIC photo went from 1.4MB as a PNG to 86KB, and SVG renders from 61KB and 107KB to 17KB and 28KB. Craft saves it at its image quality setting (`defaultImageQuality`), or lossless when its `optimizeImageFilesize` setting is off (it's on by default), so a low `defaultImageQuality` can blur fine detail such as small text. This needs an image driver that can decode them, such as ImageMagick built with AVIF/HEIF/HEIC support. Without it, these assets are skipped.
- **Animated GIFs and WebPs**: 3 frames are sent by default, evenly spaced from the first to the last, or as many as the **Animation frames** setting gives (only the first at 1), so the model can describe the animation rather than one moment of it. Each frame is rendered by the plugin as a JPG, with its transparent areas filled as above, and sent as base64, and the frames go in order as separate images in one request, which tells the model they're frames of one animation. A model may recognise a well-known animation from its first frame alone, but frames let it describe one it doesn't know. Each frame costs about as much as a still image, see [Costs](costs.md). Craft's GD image driver only reads the first frame, so with GD only that is sent, whatever the setting; use ImageMagick (Imagick) to send more.
- **URLs or base64**: Images are sent as URLs for the AI provider to download where they can be, and as base64 if the download fails. Google, OpenRouter, Hugging Face, custom endpoints and Langdock's Anthropic models are always sent base64. Turn on **Always send images as base64** to skip the download attempt, e.g. on a local site the provider can't reach.
- **Private Assets**: Assets on private volumes without public URLs are sent as base64. Craft can't return a transform's file contents for them, so one that needs a transform, for a file type the provider doesn't accept (e.g. AVIF or HEIC) or for its size, gets no alt text, and the original is only sent if the provider accepts its type. SVGs, animated GIFs and WebPs, and images with transparency are the exception, as the plugin renders them itself.
- **Servd/Cloud**: Support for specialized asset bundles (like Servd) depends on the environment's ability to handle raster transforms.
- **Consider AI Provider level boundaries**: e.g. OpenAI: "No watermarks or logos - No NSFW content - Clear enough for a human to understand"

## SVG renderers

How accurately an SVG is described depends on the server's SVG renderer: ImageMagick built with librsvg (`--with-rsvg` when building it yourself), or Inkscape (`apt install inkscape` on Debian or Ubuntu). Without one, ImageMagick uses its own basic renderer, which draws gradients as solid black, so the plugin replaces each gradient with the average of its colours first: the colours come out about right, but the shading is lost.

| ImageMagick | Its own basic renderer | librsvg built in | Inkscape |
|---|---|---|---|
| 6 | Gradients black | Works | Works |
| 7.0 to 7.1.1-27 | Gradients black | Works | Doesn't work |
| 7.1.1-28 to 7.1.2-33 | Gradients black, most strokes skipped | Works | Doesn't work |
| 7.1.2-34 and later | Gradients black, most strokes skipped | Works | Works |

Checked in October 2026 by installing each distribution's ImageMagick package. Packages change between releases, so check your server: `magick -list format | grep RSVG` (`convert -list format | grep RSVG` on ImageMagick 6) prints a line if librsvg is built in.

| Server | ImageMagick package | librsvg built in? | For accurate SVGs |
|---|---|---|---|
| Rocky Linux 9 with EPEL (RHEL family) | 6.9.13 | Yes | Nothing extra |
| Alpine 3.19 and later | 7.1.1-32 to 7.1.2-34 | Only with `imagemagick-svg`, which is needed to read SVGs at all | `apk add imagemagick-svg` |
| Alpine 3.18 and earlier | 7.0 to 7.1.1-13 | Yes, in the `imagemagick` package | Nothing extra |
| Ubuntu 22.04 and 24.04 | 6.9.11 and 6.9.12 | No | `apt install inkscape` |
| Debian 12 | 6.9.11 | No | `apt install inkscape` |
| Debian 13 (used by DDEV) | 7.1.1 | No | Too old for Inkscape, so build ImageMagick with `--with-rsvg` |
| ImageMagick built from source | Any | If built with `--with-rsvg` | Install librsvg's development files (e.g. `librsvg2-dev`), build with `--with-rsvg`, then rebuild PHP's Imagick extension against it |

## Oddities, Logic & Limitations

- **Transparency**: PNG, WebP, GIF, AVIF, HEIC and HEIF images, and SVGs, are filled with white, or with mid grey when what's drawn is mostly light, such as a white logo. They're sent as base64 JPGs (WebPs or PNGs for SVGs) rather than as URLs, and the request names the fill colour, so the Prompt's guidance on transparency still applies.
- **Animated PNGs (APNG)** aren't supported: Craft sanitises uploaded PNGs by re-saving them, which keeps only the first frame, unlike GIFs. We'll reconsider if that changes or someone needs it.
- **Format and size checks**: The plugin checks each file's MIME type and dimensions. A type the provider doesn't accept, or an image over its limits, is sent as an image transform instead, made with Craft's [Imagine library](https://github.com/php-imagine/Imagine), which can only write [some formats](https://github.com/php-imagine/Imagine/blob/develop/src/Image/Format.php#L14-L32), depending on the server's ImageMagick. Providers' image input requirements, e.g. [OpenAI's](https://platform.openai.com/docs/guides/images-vision?api-mode=responses#image-input-requirements), have changed without notice and aren't always enforced.
- **Blitz Plugin**: Saving alt text saves the asset, so with a [Blitz](https://putyourlightson.com/plugins/blitz) refresh mode that regenerates the cache in a queue job, each alt text job is followed by higher-priority purge and generate cache jobs, which slow a bulk run right down. Either:
  - Turn off Blitz's **Caching Enabled** and set its **Refresh Mode** (`refreshMode`) to "Clear the cache, regenerate manually or organically" while the jobs run, then turn them back and refresh the cache. A small purge job still runs before each alt text job.
  - Or run the jobs from the command line with isolation off, so Blitz refreshes once at the end: `./craft ai-alt-text/generate/missing && ./craft queue/run --isolate=0 && ./craft queue/run` (the second `queue/run` processes the refresh job, which is queued last). Keep the control panel closed meanwhile, or set `runQueueAutomatically` to `false`, as it runs jobs one per request too. On [Servd](https://servd.host) or another host with a queue runner, turn off its [dedicated queue runner](https://servd.host/docs/dedicated-queue-runner) and run the commands over [SSH](https://servd.host/docs/shell-sessions-ssh) or the host's commands page, then turn it back on.
- Where an alternative image transformer is used, e.g. when an application is hosted on [Servd](https://servd.host) and assets are processed through their asset platform this may not support every transform. SVGs, animated GIFs and WebPs, and images with transparency are rendered by the plugin with Craft's image driver, not through the transformer.
