# 🚀 Getting started

[← README](../README.md) · **Getting started** · [AI providers](providers.md) · [Settings](settings.md) · [Console commands](console-commands.md) · [Images](images.md) · [Costs](costs.md)

How to install AI Alt Text, set it up and generate your first alt text. For the overview, see the [README](../README.md).

## 📋 Requirements

This plugin requires: 
- Craft CMS 5.0.0 or later - running Craft 4? [Use the 4.x branch](https://github.com/heavymetalavo/craft-aialttext/blob/4.x/README.md) instead
- PHP 8.2 or later
- An Anthropic, OpenAI, Langdock or OpenRouter API key, see [AI providers](providers.md)

Recommended:
- For SVGs, Craft's Imagick image driver, and for accurate alt text, an SVG renderer for ImageMagick: librsvg (`imagemagick-svg` on Alpine), or Inkscape (`apt install inkscape` on Debian or Ubuntu) with ImageMagick 6 or 7.1.2-34 and later. See [Images](images.md#oddities-logic--limitations)

## 📥 Installation

You can install this plugin from [the Plugin Store](https://plugins.craftcms.com/ai-alt-text?craft5) or with Composer.

### 📦 With Composer

Open your terminal and run the following commands:

```sh
# tell Composer to load the plugin
composer require heavymetalavo/craft-aialttext
# or
ddev composer require heavymetalavo/craft-aialttext
```

Then:

```sh
# tell Craft to install the plugin
./craft plugin/install ai-alt-text
# or
ddev craft plugin/install ai-alt-text
```

## 🏷️ Field requirements

This plugin requires a native CraftCMS field for alt text with the handle `alt` to be added to all asset volumes where you want to generate alt text. The plugin will use this field to store the generated alt text.

To add this field:
1. Go to **Settings → Assets → Volume name → + Add → search for the `alt` field and click → save**
2. Scroll to Field Layout section
3. Click the `+ Add` button
4. Search for the `alt` field and click 
5. Save changes to the volume
6. Update your templates to use the new `alt` field

## 🚀 How to use

1. Check the plugin settings are suitable for your project (and your API key is added)
2. Ensure your volumes have the native `alt` field assigned to the field layout
3. Ensure your templates are updated to use the `alt` field, you could consider a fallback `asset.alt ?: asset.title` if that what was used before
4. Then generate some AI Alt text by performing one of the following actions:
    1. Triggering a bulk action in the bulk actions table
    2. For individual or a group of specific assets find them in the <strong>Assets</strong> manager section</a> clicking the checkbox on a row, clicking the cog icon to reveal the Element actions menu and select <strong>Generate AI Alt Text</strong>
    3. When viewing a single asset's page, open the action menu and select <strong>Generate AI Alt Text</strong>
    4. Upload a new asset (if the upload setting is enabled)
5. A single asset, selected in the **Assets** manager or on its own page, is generated straight away. Multiple selected assets, the bulk actions, new uploads, and the other sites when **Save translated results for each site** is enabled are queued as jobs instead

![Where to generate alt text: on an asset's page, from its action menu; for selected assets in Assets, from the actions menu (the cog); from an asset's menu in an Assets field; from the bulk actions utility in Utilities, for all sites or one site at a time; from the command line, with ai-alt-text/generate/stats, missing or single; or automatically, with the "Generate for new image assets (on upload or file replacement)" setting turned on](images/where-to-generate.png)

Example twig:

```twig
{% set asset = craft.assets.one() %}
<img src="{{ asset.url }}" alt="{{ asset.alt ?: asset.title }}">
```

## 🛠️ Troubleshooting

- If the plugin returns errors about API authentication, verify your API key.
- For "bad request" errors, ensure your selected model supports vision capabilities.
- Alt text generation is processed through Craft's queue system for bulk operations, so check the queue if generation seems to be taking a long time.
- If a queued job fails with a rate limit error, such as "OpenRouter API error: Rate limit exceeded: new-account-rpm/… new accounts are limited to 20 requests per minute for this model", the provider is limiting how fast you can send requests. This is common on a new OpenRouter account, which can be limited per model until it has built up some history of use, and OpenRouter doesn't publish when that lifts. The simplest fix is to switch the **Model** to a different one for now (for example `anthropic/claude-haiku-5.5` in place of `openai/gpt-6-luna`), as limits are set per model, and switch back once the account has matured. You can also use the provider directly, or add your own OpenAI or Anthropic key to OpenRouter. Failed jobs can be retried from the **Queue Manager** once the limit has eased, and running a single queue worker for bulk jobs keeps the request rate down.
- Any errors _should_ be logged, check your queue.log files!
- Check the asset has a title! If somehow an asset exists without one craft will not be able to resave it.
