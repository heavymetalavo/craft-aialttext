# Roadmap

Planned work, not yet scheduled or promised. Shipped changes are in [CHANGELOG.md](CHANGELOG.md).

## Settings

- **Prompt variable validation on save.** Check the `{asset.…}` and `{site.…}` variables when the Prompt setting is saved, so a typo like `{asset.filenmae}` is caught in the control panel. Add it as an inline validator on `prompt` in `Settings::defineRules()`. Only reject names that can be positively ruled out, because a false positive would block a prompt that works. Custom Asset fields need a field-layout scan. Object-valued variables like `{asset.volume}` pass a name check. The runtime error stays as the safety net, since project config deploys and env-var prompts skip validation.
- **Config file.** Document setting the plugin from `config/ai-alt-text.php`, e.g. a different provider or model per environment. Craft gives values there priority over the control panel, where the field still shows the saved value but changes to it have no effect, so the settings page should mark the fields the file controls.
- **Excluded assets.** Let images be skipped by bulk and on-upload generation: by volume or folder (e.g. icons), or by where they're used (e.g. assets related to entries in a given section). An asset can be related to several entries, so a rule would need to say whether one match is enough. Generating for a single asset on request would still work.
- **Animation process strategy** let admins decide if webp/gif animations should be processed as a "film strip" in small (1000x1000) or larger (2000x2000) or whatever an ai provider can support, e.g. up to 8000x8000, or whether we send each frame individually as it's own image, combine all the ai responses into a single description saved agained the gif/webp. admins may also want to decide how many frames get sent on a separate field, e.g. 3, 5, 10 and so on where each frame captured is spread evenly throughout the duration of the clip
- **png file format** - in some scenarios we send png files up to ai providers, but they could be PNG which offers transparency and better file sizes, we no longer need to use png I don't think, even with storing basic images which were basic vectors I think this would be better?

## Platform support

- **Craft 6.** In progress on `feature/craft-6`, tested against Craft 6 alpha.

## Under consideration

- **Non-public asset URLs.** Providers can't fetch asset URLs on hosts like `*.ddev.site`, so every generation logs one failed download before the base64 fallback succeeds. Options are skipping the URL attempt for non-public hosts or adding a force-base64 setting.
- **Descriptive titles and filenames.** Use the same request to suggest a short, descriptive title and filename (e.g. "Polar bear waving on snow" and `polar-bear-waving-on-snow.jpg` for `hans-jurgen-mager-qQWV91TTBrE-unsplash.jpg`, whose title Craft takes from the filename), and optionally save them. A new title is safe to save, but renaming the file changes the asset's URL, so it would need to be opt-in, avoid clashing with existing filenames, and warn that hard-coded URLs in content or templates would break; Craft's own references go by asset ID, so they keep working.
- **Smaller converted images.** AVIF, HEIC and HEIF images are converted to PNG before they're sent, which is lossless but large for photos. WebP is accepted by every provider and is usually much smaller, so requests would be lighter. It needs ImageMagick with WebP support, so PNG would stay as the fallback.
- **Vision model suggestions for OpenRouter.** The model field could suggest image-capable models from OpenRouter's public models API (cached), rather than only linking to its models page.
- **Custom endpoints.** Let a base URL be set for gateways that accept the OpenAI, Anthropic or OpenRouter request shapes unchanged, as Langdock does, so the plugin could work with a new provider even if the plugin doesn't officially support that provider. For the Responses API shape this would be a small subclass of `ResponsesApiService`.
- **Retry on rate limits.** When a provider returns a 429, re-queue the job with a delay (using `Retry-After` when sent, otherwise a growing back-off) instead of failing it, and pause other jobs for that provider and model through a shared cache entry so a bulk run settles to the account's real rate. Mostly useful for new OpenRouter accounts, which are limited per model.
- **Share the Anthropic code the same way.** `LangdockAnthropicService` extends `AnthropicService`, as the Responses providers used to extend `OpenAiService`. A `MessagesApiService` base for the Anthropic Messages API would put Langdock Claude (and any other Anthropic-compatible host) beside Anthropic rather than under it, and give those messages a provider label too.
