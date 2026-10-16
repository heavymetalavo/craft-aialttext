# 🤖 💬 AI Alt Text

**README** · [Getting started](docs/getting-started.md) · [AI providers](docs/providers.md) · [Settings](docs/settings.md) · [Console commands](docs/console-commands.md) · [Images](docs/images.md) · [Costs](docs/costs.md)

Write alt text for every image on your Craft site with AI: one click, in bulk, or as images are uploaded, in each site's language.

🚨 **New in 5.3:** OpenRouter support, with hundreds of vision models behind one API key.  
🚨 **New in 5.2:** Langdock support, for Claude and GPT models hosted in the EU.

[Plugin Store](https://plugins.craftcms.com/ai-alt-text?craft5) | [GitHub Repository](https://github.com/heavymetalavo/craft-aialttext)

## ✨ What you get

- **Any vision model, one key.** Use Anthropic or OpenAI directly, Langdock (hosted in the EU), or OpenRouter, which offers over 300 image-capable models, including Claude, GPT, Gemini, Grok, Qwen and DeepSeek. Switching model is one setting. OpenAI and Anthropic models are supported, and more than 50 others worked in our tests. See [AI providers](docs/providers.md).
- **Pennies for a whole library.** With `gpt-6-luna`, alt text for 1,000 images costs about 6 cents. [Compare models](docs/costs.md).
- **Every site's language.** Alt text is written in the site's language, and can be saved to each site of a multi-site install.
- **One click, in bulk, or automatically.** From an asset's action menu, for selected assets, from the bulk actions utility, on upload or file replacement, or from the [command line](docs/console-commands.md). Bulk work runs through Craft's queue, where failed jobs can be retried.
- **Awkward images handled.** Large images are scaled to each provider's limits, SVG, AVIF, HEIC and HEIF are converted, animated GIFs use their first frame, and private volumes are sent as base64. See [Images](docs/images.md).
- **Your prompt, your rules.** Edit the instructions sent to the AI, with `{asset.*}` and `{site.*}` variables. See [Settings](docs/settings.md#-default-prompt).
- **Permissions built in.** Bulk generation has its own utility permission, and single assets follow the asset's own save permission.

## 🎬 Video walkthrough

A full walkthrough of all plugin features - [watch on YouTube](https://youtu.be/Kp0OQVfd4iw):

[![Watch the AI Alt Text plugin walkthrough on YouTube](https://web-extract.constantcontact.com/v1/thumbnail?url=https://img.youtube.com/vi/Kp0OQVfd4iw/hqdefault.jpg)](https://youtu.be/Kp0OQVfd4iw)

## 🚀 Quick start

Craft CMS 5.0.0 or later (running Craft 4? [Use the 4.x branch](https://github.com/heavymetalavo/craft-aialttext/blob/4.x/README.md)), PHP 8.2 or later, and an API key for Anthropic, OpenAI, Langdock or OpenRouter.

```sh
composer require heavymetalavo/craft-aialttext
./craft plugin/install ai-alt-text
```

1. Choose a provider and add its API key under **Settings → AI Alt Text**, see [AI providers](docs/providers.md).
2. Add the native `alt` field to your asset volumes' field layouts, see [Field requirements](docs/getting-started.md#-field-requirements).
3. Generate alt text from an asset's action menu, the bulk actions utility, on upload or from the command line.

Then use it in your templates:

```twig
<img src="{{ asset.url }}" alt="{{ asset.alt ?: asset.title }}">
```

## 📚 Documentation

- [Getting started](docs/getting-started.md) - requirements, installation, the `alt` field, generating alt text, troubleshooting
- [AI providers](docs/providers.md) - API keys for Anthropic, OpenAI, Langdock and OpenRouter, and choosing a model
- [Settings](docs/settings.md) - every setting, the default prompt, image detail, reasoning effort and permissions
- [Console commands](docs/console-commands.md) - stats, missing, all and single
- [Images](docs/images.md) - supported file types, scaling and conversion, private volumes and limitations
- [Costs](docs/costs.md) - estimated cost per alt text by model, and avoiding unexpected charges

## ⚠️ Disclaimer

We've taken some steps to try prevent unexpected costs with default plugin settings (e.g. detail: `low` and the suggested small model `gpt-6-luna`) though we take no responsibility for excessive API token usage that may result from mistakes, bugs, or security vulnerabilities within this plugin so use at your own risk. See [Avoiding unexpected charges](docs/costs.md#-avoiding-unexpected-charges).

## 🙋 Support

If you encounter any issues or have questions, please submit them on [GitHub](https://github.com/heavymetalavo/craft-aialttext/issues).

## Credits

The eye icon used in this project is from [SVG Repo](https://www.svgrepo.com/svg/193488/eye) and is available under the CC0 1.0 Universal (Public Domain) license.
