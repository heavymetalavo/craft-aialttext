# Roadmap

Planned work, not yet scheduled or promised. Shipped changes are in [CHANGELOG.md](CHANGELOG.md).

## Settings

- **Excluded assets.** Let images be skipped by bulk and on-upload generation: by volume or folder (e.g. icons), or by where they're used (e.g. assets related to entries in a given section). An asset can be related to several entries, so a rule would need to say whether one match is enough. Generating for a single asset on request would still work.

## Platform support

- **Craft 6.** In progress on `feature/craft-6`, tested against Craft 6 alpha.

## Under consideration

- **Descriptive titles and filenames.** Use the same request to suggest a short, descriptive title and filename (e.g. "Polar bear waving on snow" and `polar-bear-waving-on-snow.jpg` for `hans-jurgen-mager-qQWV91TTBrE-unsplash.jpg`, whose title Craft takes from the filename), and optionally save them. A new title is safe to save, but renaming the file changes the asset's URL, so it would need to be opt-in, avoid clashing with existing filenames, and warn that hard-coded URLs in content or templates would break; Craft's own references go by asset ID, so they keep working.
- **Model suggestions for other providers.** OpenRouter's and Hugging Face's model fields now suggest image-capable models from their public models APIs. See whether the Anthropic, OpenAI and Langdock model fields can do the same. Unlike those two, Anthropic's and OpenAI's model lists need an API key, which the settings page would have to read (it may be an environment variable) and cope with failing, and each list would need to say which models take images, or be filtered some other way. Langdock's would need checking.
- **Custom endpoints.** Let a base URL be set for gateways that accept the OpenAI, Anthropic or OpenRouter request shapes unchanged, as Langdock does, so the plugin could work with a new provider even if the plugin doesn't officially support that provider. For the Responses API shape this would be a small subclass of `ResponsesApiService`, and for the Chat Completions shape one of `ChatCompletionsApiService`.
- **Several asset IDs from the command line.** `ai-alt-text/generate/single` takes one asset ID and generates it straight away, and `generate/missing` and `generate/all` can only be limited by site. An option such as `--asset-ids=71,72,83` on those commands would queue just those assets, which is useful for targeted bulk runs and for testing queue behaviour such as rate limit retries.
