# Roadmap

Planned work, not yet scheduled or promised. Shipped changes are in [CHANGELOG.md](CHANGELOG.md).

## Platform support

- **Craft 6.** In progress on `feature/craft-6`, tested against Craft 6 alpha.

## Under consideration

- **Model suggestions for other providers.** OpenRouter's and Hugging Face's model fields now suggest image-capable models from their public models APIs. See whether the Anthropic, OpenAI and Langdock model fields can do the same. Unlike those two, Anthropic's and OpenAI's model lists need an API key, which the settings page would have to read (it may be an environment variable) and cope with failing, and each list would need to say which models take images, or be filtered some other way. Langdock's would need checking.
- **Custom endpoints.** Let a base URL be set for gateways that accept the OpenAI, Anthropic or OpenRouter request shapes unchanged, as Langdock does, so the plugin could work with a new provider even if the plugin doesn't officially support that provider. For the Responses API shape this would be a small subclass of `ResponsesApiService`, and for the Chat Completions shape one of `ChatCompletionsApiService`.
- **Several asset IDs from the command line.** `ai-alt-text/generate/single` takes one asset ID and generates it straight away, and `generate/missing` and `generate/all` can only be limited by site. An option such as `--asset-ids=71,72,83` on those commands would queue just those assets, which is useful for targeted bulk runs and for testing queue behaviour such as rate limit retries.
