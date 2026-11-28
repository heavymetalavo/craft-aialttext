# Roadmap

Planned work, not yet scheduled or promised. Shipped changes are in [CHANGELOG.md](CHANGELOG.md).

## Platform support

- **Craft 6.** In progress on `feature/craft-6`, tested against Craft 6 alpha.

## Under consideration

- **Excluding assets by where they're used.** Skipping assets related to entries in chosen sections, in bulk generation and on file replacement. It can't apply to a new upload, which isn't related to an entry until the entry is saved, and an asset used in several sections would need a setting for whether one match is enough, so it was left out of the first version of excluding assets. Putting such images in their own folder and excluding that already works.
- **Model suggestions for other providers.** OpenRouter's and Hugging Face's model fields now suggest image-capable models from their public models APIs. See whether the Anthropic, OpenAI and Langdock model fields can do the same. Unlike those two, Anthropic's and OpenAI's model lists need an API key, which the settings page would have to read (it may be an environment variable) and cope with failing, and each list would need to say which models take images, or be filtered some other way. Langdock's would need checking.
