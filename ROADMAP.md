# Roadmap

Planned work, not yet scheduled or promised. Shipped changes are in [CHANGELOG.md](CHANGELOG.md).

## Settings

- **Prompt variable validation on save.** Check the `{asset.…}` and `{site.…}` variables when the Prompt setting is saved, so a typo like `{asset.filenmae}` is caught in the control panel. Add it as an inline validator on `prompt` in `Settings::defineRules()`. Only reject names that can be positively ruled out, because a false positive would block a prompt that works. Custom Asset fields need a field-layout scan. Object-valued variables like `{asset.volume}` pass a name check. The runtime error stays as the safety net, since project config deploys and env-var prompts skip validation.

## Providers

- **DeepSeek.** Built on `feature/deepseek-provider` (local only), not yet merged. OpenRouter already reaches DeepSeek's vision models, so a direct provider may not be needed.

## Platform support

- **Craft 6.** In progress on `feature/craft-6`, tested against Craft 6 alpha.

## Under consideration

- **Non-public asset URLs.** Providers can't fetch asset URLs on hosts like `*.ddev.site`, so every generation logs one failed download before the base64 fallback succeeds. Options are skipping the URL attempt for non-public hosts or adding a force-base64 setting.
- **Claude image sizing on OpenRouter.** OpenRouter requests go through the OpenAI path, so Claude models get images sized for OpenAI rather than for Anthropic, and in testing Claude cost about 15 to 20% more through OpenRouter than direct (about 40% more input tokens). Sizing images the Anthropic way when the model starts with `anthropic/` would close the gap.
- **Vision model suggestions for OpenRouter.** The model field could suggest image-capable models from OpenRouter's public models API (cached), rather than only linking to its models page.
- **Custom endpoints.** Let a base URL be set for gateways that accept the OpenAI, Anthropic or OpenRouter request shapes unchanged, as Langdock does, so the plugin could work with a new provider even if the plugin doesn't officially support that provider.
