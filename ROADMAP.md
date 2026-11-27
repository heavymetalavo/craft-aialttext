# Roadmap

Planned work, not yet scheduled or promised. Shipped changes are in [CHANGELOG.md](CHANGELOG.md).

## Settings

- **Excluded assets.** Let images be skipped by bulk and on-upload generation: by volume or folder (e.g. icons), or by where they're used (e.g. assets related to entries in a given section). An asset can be related to several entries, so a rule would need to say whether one match is enough. Generating for a single asset on request would still work.
- **Animation strategy.** Let admins choose how animated GIFs, WebPs and PNGs (APNG) are sent: as one film strip image of evenly spaced frames, at a size the AI provider supports (e.g. 1000×1000 or 2000×2000, up to 8000×8000 for some), or as one request per frame, combining the responses into a single description saved against the asset. A separate setting would set how many frames are sent (e.g. 3, 5 or 10), spread evenly through the animation.

## Providers

- **Hugging Face.** Its Inference Providers router (`https://router.huggingface.co/v1`) works like OpenRouter: one Hugging Face token, open-weight models from providers such as Groq and Scaleway (a model id is `org/model` with an optional `:provider` suffix), billed at the providers' own prices. Its Responses API (beta) at `/v1/responses` documents text and image input (`input_text` and `input_image`), so it should fit the same request shape as OpenAI, Langdock and OpenRouter. Still to check with a real token: whether it accepts `detail`, base64 images and `store: false`, what its errors look like, and which vision models work.

## Platform support

- **Craft 6.** In progress on `feature/craft-6`, tested against Craft 6 alpha.

## Under consideration

- **Descriptive titles and filenames.** Use the same request to suggest a short, descriptive title and filename (e.g. "Polar bear waving on snow" and `polar-bear-waving-on-snow.jpg` for `hans-jurgen-mager-qQWV91TTBrE-unsplash.jpg`, whose title Craft takes from the filename), and optionally save them. A new title is safe to save, but renaming the file changes the asset's URL, so it would need to be opt-in, avoid clashing with existing filenames, and warn that hard-coded URLs in content or templates would break; Craft's own references go by asset ID, so they keep working.
- **Model suggestions for other providers.** OpenRouter's model field now suggests image-capable models from its public models API. See whether the Anthropic, OpenAI and Langdock model fields can do the same. Unlike OpenRouter's, Anthropic's and OpenAI's model lists need an API key, which the settings page would have to read (it may be an environment variable) and cope with failing, and each list would need to say which models take images, or be filtered some other way. Langdock's would need checking.
- **Custom endpoints.** Let a base URL be set for gateways that accept the OpenAI, Anthropic or OpenRouter request shapes unchanged, as Langdock does, so the plugin could work with a new provider even if the plugin doesn't officially support that provider. For the Responses API shape this would be a small subclass of `ResponsesApiService`.
- **Several asset IDs from the command line.** `ai-alt-text/generate/single` takes one asset ID and generates it straight away, and `generate/missing` and `generate/all` can only be limited by site. An option such as `--asset-ids=71,72,83` on those commands would queue just those assets, which is useful for targeted bulk runs and for testing queue behaviour such as rate limit retries.
