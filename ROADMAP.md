# Roadmap

Planned work, not yet scheduled or promised. Shipped changes are in [CHANGELOG.md](CHANGELOG.md).

## 5.2.0

- **Prompt variable validation on save.** Check `{asset.…}` and `{site.…}` tokens when the Prompt setting is saved, so a typo like `{asset.filenmae}` is caught in the control panel. Add it as an inline validator on `prompt` in `Settings::defineRules()`. Only reject names that can be positively ruled out, because a false positive would block a prompt that works. Custom Asset fields need a field-layout scan. Object-valued tokens like `{asset.volume}` pass a name check. The runtime error stays as the safety net, since project config deploys and env-var prompts skip validation.

## Providers

- **Langdock.** Built on `feature/langdock`, not yet merged.
- **DeepSeek.** Built on `feature/deepseek-provider`, not yet merged.
- **OpenRouter.** Not started.

## Platform support

- **Craft 6.** In progress on `feature/craft-6`, tested against Craft 6 alpha.

## Under consideration

- **Non-public asset URLs.** Providers can't fetch asset URLs on hosts like `*.ddev.site`, so every generation logs one failed download before the base64 fallback succeeds. Options are skipping the URL attempt for non-public hosts or adding a force-base64 setting.
