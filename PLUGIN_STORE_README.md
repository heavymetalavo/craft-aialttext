# 🤖 💬 AI Alt Text
  
Generate alt text for CraftCMS Asset Images using OpenAI's API.

[Plugin Store](https://plugins.craftcms.com/ai-alt-text?craft5) | [GitHub Repository](https://github.com/heavymetalavo/craft-aialttext)

## Video demo

[Watch on GitHub](https://github.com/heavymetalavo/craft-aialttext?tab=readme-ov-file#video-demo)

## 📋 Requirements

This plugin requires: 
- Craft CMS 5.0.0 or later
- PHP 8.2 or later
- An OpenAI API key

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

## 🤖 Setup OpenAI API Key

1. Visit [https://platform.openai.com/](https://platform.openai.com/) and [sign up](https://platform.openai.com/signup) in the top-right.
2. Revisit [the API platform home page](https://platform.openai.com/) again
3. Click the ⚙️ icon in the top-right
4. Left menu > Organization > API keys > + Create new secret key (top-right)
5. Create a name and assign to a suitable project
6. Permissions > Restricted > set "Model capabilities" to "Write" and "Responses API" to "Write"
7. Save API key to an env var, you wont get to see it again!
8. Make sure you have a credit balance! Left menu > Organization > Billing, loading $5 is probably going to get you quite far, disabling auto recharge might be safer though that's up to you!

## 🚀 How to use

1. Check the plugin settings are suitable for your project (and your API key is added)
2. Ensure your volumes have the native `alt` field assigned to the field layout
3. Ensure your templates are updated to use the `alt` field, you could consider a fallback `asset.alt ?: asset.title` if that what was used before
4. Then generate some AI Alt text by performing one of the following actions:
    1. Triggering a bulk action in the bulk actions table
    2. For individual or a group of specific assets find them in the <strong>Assets</strong> manager section</a> clicking the checkbox on a row, clicking the cog icon to reveal the Element actions menu and select <strong>Generate AI Alt Text</strong>
    3. When viewing a single asset's page, open the action menu and select <strong>Generate AI Alt Text</strong>
    4. Upload a new asset (if the upload setting is enabled)
5. The plugin will queue jobs to generate alt text for each selected asset

![The Bulk Actions table in the AI Alt Text settings page](https://raw.githubusercontent.com/heavymetalavo/craft-aialttext/main/src/bulk-actions.png)

![The CraftCMS assets manager with two assets selected and the 'Generate AI Alt Text' option visible in the active element actions menu](https://raw.githubusercontent.com/heavymetalavo/craft-aialttext/main/src/assets-manager.png)

![The active actions menu when viewing a single asset shows the 'Generate AI Alt Text' option](https://raw.githubusercontent.com/heavymetalavo/craft-aialttext/main/src/single-asset.png)

Example twig:

```twig
{% set asset = craft.assets.one() %}
<img src="{{ asset.url }}" alt="{{ asset.alt ?: asset.title }}">
```

## ⚙️ Plugin settings

After installation, configure the plugin at **Settings → AI Alt Text**:

### 📊 Settings overview

| Setting | Description | Default |
|---------|-------------|---------|
| **OpenAI API Key** | Your OpenAI API key. You can get one from [OpenAI's API Platform](https://platform.openai.com/api-keys). | None (required) |
| **Prompt** | The text prompt sent to the AI to generate alt text. Supports `{asset.property}` and `{site.property}` | See below |
| **Open AI Model** | The OpenAI model to use for generating alt text. | `gpt-4.1-nano` |
| **Open AI Image Input Detail Level** | How detailed the image analysis should be. | `low` |
| **Propagate** | Whether the asset should be saved across all of its supported sites, if enabled it could save the same initial alt text value across all sites. | `false` |
| **Generate for new image assets (on upload)** | Whether to automatically generate alt text when new assets are created. | `false` |
| **Save translated results for each site** | Whether to save translated results to an Asset's translatable alt text field for each site. | `false` |

#### 🧠 Model Options
Some models that support vision capabilities:
- `gpt-4.1-nano` - Fast, affordable small model for focused tasks (default)
- `gpt-4o` - Fast, intelligent, flexible GPT model
- `o1` - High-intelligence reasoning model

To find out which models are capable of vision, check [the models page](https://platform.openai.com/docs/models), click into a model's detail page (e.g., [GPT-4.1-nano](https://platform.openai.com/docs/models/gpt-4.1-nano)) and look for "**Input**: Text, image" in the features columns at the top.

#### 💬 Default prompt

> Generate a brief (roughly 150 characters maximum) alt text description focusing on the main subject and overall composition. Do not add a prefix of any kind (e.g. alt text: AI content) so the value is suitable for the alt text attribute value of the image. Output in {site.language}

#### 🔍 Image detail options
- `low` - Less detailed, faster and cheaper (default to protect against unexpected costs)
- `high` - More detailed, slower and more expensive (higher resolution analysis)
- `auto` - Let OpenAI decide

For more information about these settings, refer to the [OpenAI API documentation](https://platform.openai.com/docs/guides/images).

## 🏷️ Field requirements

This plugin requires a native CraftCMS field for alt text with the handle `alt` to be added to all asset volumes where you want to generate alt text. The plugin will use this field to store the generated alt text.

To add this field:
1. Go to **Settings → Assets → Volume name → + Add → search for the `alt` field and click → save**
2. Scroll to Field Layout section
3. Click the `+ Add` button
4. Search for the `alt` field and click 
5. Save changes to the volume
6. Update your templates to use the new `alt` field

## Limitations

- The OpenAI API has [image input requirements](https://platform.openai.com/docs/guides/images-vision?api-mode=responses#image-input-requirements) which have changed in the past month (2025-05), however these requirements don't appear to be enforced, e.g. sending a base64 image above required image dimensions will be accepted by the API.
- Where an unsupported file type is requested the plugin will attempt an image transform to a jpg to be sent instead
- The plugin checks a file's mimetype to see if it's valid, [a filename which contains the wrong extension could return the wrong file type until Craft v5.8.0 is released](https://github.com/craftcms/cms/issues/17246#issuecomment-2873706369)
- If an asset's dimensions are larger than the dimensions required by the API an image transform is sent instead
- If an asset has no URL (private) and requires a transform (e.g. if the original asset is an unsupported mime type, or, the dimensions are too large) the plugin [cannot retrieve the transform's file contents](https://github.com/craftcms/cms/issues/17238#issuecomment-2873206148) to send a base64 encoded version of the image to the OpenAI API.
- Where an alternative image transformer is used, e.g. when an application is hosted on [Servd](https://servd.host) and assets are processed through their asset platform this may not support svg -> raster transforms

### Supported file types	

- PNG (.png)
- JPEG (.jpeg and .jpg)
- WEBP (.webp)
- Non-animated GIF (.gif)

### Size limits	

- Up to 20MB per image
- Low-resolution: 512px x 512px
- High-resolution: 768px (short side) x 2000px (long side)

### Other requirements	

- No watermarks or logos
- No text
- No NSFW content
- Clear enough for a human to understand

## 🛠️ Troubleshooting

- If the plugin returns errors about API authentication, verify your API key.
- For "bad request" errors, ensure your selected model supports vision capabilities.
- Alt text generation is processed through Craft's queue system for bulk operations, so check the queue if generation seems to be taking a long time.
- Any errors _should_ be logged, check your queue.log files!

## ⚠️ Disclaimer

We've taken some steps to try prevent unexpected costs with default plugin settings (e.g. detail: `low` and model: `gpt-4.1-nano`) though we take no responsibility for excessive API token usage that may result from mistakes, bugs, or security vulnerabilities within this plugin so use at your own risk.

If you are concerned about unexpected charges we recommend:
- Set up rate limits and spending caps at the API account level in your [OpenAI account settings](https://platform.openai.com/account/billing/limits)
- Start with smaller batches when using bulk generation until you're comfortable with the costs
- Consider using the default `low` setting, which significantly reduces token usage
- Monitor your OpenAI API usage regularly

### 📈 Example usage statistics

When testing using the default settings (`gpt-4o-mini` model, `low` detail level):

| Metric | Value |
|--------|-------|
| March budget | $0.03 / $120 |
| Total tokens used | 163,713 |
| Total requests | 29 |

## 🙋 Support

If you encounter any issues or have questions, please submit them on [GitHub](https://github.com/heavymetalavo/craft-aialttext/issues).

## Credits

The eye icon used in this project is from [SVG Repo](https://www.svgrepo.com/svg/193488/eye) and is available under the CC0 1.0 Universal (Public Domain) license.
