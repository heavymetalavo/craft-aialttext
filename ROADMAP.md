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
- **Vision model suggestions for OpenRouter.** The model field could suggest image-capable models from OpenRouter's public models API (cached), rather than only linking to its models page.
- **Custom endpoints.** Let a base URL be set for gateways that accept the OpenAI, Anthropic or OpenRouter request shapes unchanged, as Langdock does, so the plugin could work with a new provider even if the plugin doesn't officially support that provider.
- **Retry on rate limits.** When a provider returns a 429, re-queue the job with a delay (using `Retry-After` when sent, otherwise a growing back-off) instead of failing it, and pause other jobs for that provider and model through a shared cache entry so a bulk run settles to the account's real rate. Mostly useful for new OpenRouter accounts, which are limited per model.
