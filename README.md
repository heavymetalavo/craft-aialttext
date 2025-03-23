# 🤖 💬 AI Alt Text

Generate suitable alt text for CraftCMS Asset Images using OpenAI's API.

## 📋 Requirements

This plugin requires Craft CMS 5.6.0 or later, and PHP 8.2 or later.

## 📥 Installation

You can install this plugin from the Plugin Store or with Composer.

### 🛒 From the Plugin Store

Go to the Plugin Store in your project's Control Panel and search for "Ai Alt Text". Then press "Install".

### 📦 With Composer

Open your terminal and run the following commands:

```bash
# tell Composer to load the plugin
composer require heavymetalavo/craft-aialttext
# or
ddev composer require heavymetalavo/craft-aialttext

# tell Craft to install the plugin
./craft plugin/install ai-alt-text
# or
ddev craft plugin/install ai-alt-text
```

## 🏷️ Field Requirements

This plugin requires a native CraftCMS field for alt text with the handle `alt` to be added to all asset volumes where you want to generate alt text. The plugin will use this field to store the generated alt text.

To add this field:
1. Go to **Settings → Assets → Volume name → + Add → search for the `alt` field and click → save**
2. Scroll to Field Layout section
3. Click the `+ Add` button
4. Search for the `alt` field and click 
5. Save changes to the volume
6. Update your templates to use the new `alt` field

## 🚀 How to Use

1. Check the plugin settings are suitable for your project
2. Go to the **Assets** section in the Control Panel to view the table of assets
3. Select the checkboxes of all the assets you want to generate alt text for
4. Click the **Actions** button and select **Generate AI Alt Text**
5. The plugin will queue jobs to generate alt text for each selected asset

![CraftCMS asset library table with two assets selected and the 'Generate AI Alt Text' option visible in the dropdown.](craft-aialttext-example.png)

## ⚙️ Plugin Settings

After installation, configure the plugin at **Settings → AI Alt Text**:

### 📊 Settings overview

| Setting | Description | Default |
|---------|-------------|---------|
| **API Key** | Your OpenAI API key. You can get one from [OpenAI's API Platform](https://platform.openai.com/api-keys). | None (required) |
| **Model** | The OpenAI model to use for generating alt text. | `gpt-4o-mini` |
| **Prompt** | The text prompt sent to the AI to generate alt text. | See below |
| **Image Detail Level** | How detailed the image analysis should be. | `low` |

#### 🧠 Model Options
Models that support vision capabilities:
- `gpt-4o-mini` - Fast, affordable small model for focused tasks (default)
- `gpt-4o` - Fast, intelligent, flexible GPT model
- `o1` - High-intelligence reasoning model

To find out which models are capable of vision, check [the models page](https://platform.openai.com/docs/models), click into a model's detail page (e.g., [GPT-4o mini](https://platform.openai.com/docs/models/gpt-4o-mini)) and look for "**Input**: Text, image" in the features columns at the top.

#### 💬 Default Prompt
```
Generate a brief (roughly 150 characters maximum) alt text description focusing on the main subject and overall composition. Do not add a prefix of any kind (e.g. alt text: AI content) so the value is suitable for the alt text attribute value of the image.
```

#### 🔍 Image Detail Options
- `low` - Less detailed, faster and cheaper (default to protect against unexpected costs)
- `high` - More detailed, slower and more expensive (higher resolution analysis)
- `auto` - Let OpenAI decide

For more information about these settings, refer to the [OpenAI API documentation](https://platform.openai.com/docs/guides/images).

## 🛠️ Troubleshooting

- If the plugin returns errors about API authentication, verify your API key.
- For "bad request" errors, ensure your selected model supports vision capabilities.
- Alt text generation is processed through Craft's queue system for bulk operations, so check the queue if generation seems to be taking a long time.
- Any errors _should_ be logged, check your queue.log files!

## ⚠️ Disclaimer

We've taken some steps to try prevent unexpected costs with default plugin settings (e.g. detail: `low` and model: `gpt-4o-mini`) though we take no responsibility for excessive API token usage that may result from mistakes, bugs, or security vulnerabilities within this plugin so use at your own risk.

If you are concerned about unexpected charges we recommend:
- Set up rate limits and spending caps at the API account level in your [OpenAI account settings](https://platform.openai.com/account/billing/limits)
- Start with smaller batches when using bulk generation until you're comfortable with the costs
- Consider using the default `low` setting, which significantly reduces token usage
- Monitor your OpenAI API usage regularly

### 📈 Example Usage Statistics

When testing using the default settings (`gpt-4o-mini` model, `low` detail level):

| Metric | Value |
|--------|-------|
| March budget | $0.03 / $120 |
| Total tokens used | 163,713 |
| Total requests | 29 |

## 🙋 Support

If you encounter any issues or have questions, please submit them on [GitHub](https://github.com/heavymetalavo/craft-aialttext/issues).
