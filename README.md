# 🤖 💬 AI Alt Text

Generate suitable alt text for CraftCMS Asset Images using OpenAI's API.

## Requirements

This plugin requires Craft CMS 5.6.0 or later, and PHP 8.2 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

### From the Plugin Store

Go to the Plugin Store in your project's Control Panel and search for "Ai Alt Text". Then press "Install".

### With Composer

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

## Field Requirements

This plugin requires a native Craft field for alt text with the handle `alt` to be added to all asset volumes where you want to generate alt text. The plugin will use this field to store the generated alt text.

To add this field:
1. Go to **Settings → Assets → Volume name → + Add → search for the `alt` field and click → save**
2. Scroll to Field Layout section
3. Click the `+ Add` button
4. Search for the `alt` field and click 
5. Save changes to the volume
6. Update your templates to reference the alt field

## Plugin Settings

After installation, configure the plugin at **Settings → AI Alt Text**:

### OpenAI API Settings

- **API Key** - Your OpenAI API key. You can get one from [OpenAI's API Platform](https://platform.openai.com/api-keys).

- **Model** - The OpenAI model to use for generating alt text. Recommended models that support vision:
  - `gpt-4o-mini` - Good balance of quality and cost
  - `gpt-4o` - Most advanced model with best results (recommended)
  - There are probably others, OpenAI suggest to look [on the models page](https://platform.openai.com/docs/models) (which doesn't actually list any)

- **Prompt** - The text prompt sent to the AI to generate alt text. Default:
  ```
  Generate a brief (roughly 150 characters maximum) alt text description focusing on the main subject and overall composition. Do not add a prefix of any kind (e.g. alt text: AI content) so the value is suitable for the alt text attribute value of the image.
  ```

### Image Settings

- **Image Detail Level** - How detailed the image analysis should be:
  - `low` - Less detailed, faster and cheaper (default to protect against unexpected costs)
  - `high` - More detailed, slower and more expensive (higher resolution analysis)
  - `auto` - Let OpenAI decide

For more information about these settings, refer to the [OpenAI API documentation](https://platform.openai.com/docs/guides/images).

## How to Use

Once configured, there are several ways to generate alt text for your assets:

### Bulk Generation

Generate alt text for single multiple assets:

1. Go to the **Assets** section in the Control Panel to view the table of assets
2. Select the checkboxes of the assets you want to generate alt text for
3. Click the **Actions** button and select **Generate AI Alt Text**
4. The plugin will queue jobs to generate alt text for each selected asset

## Troubleshooting

- If the plugin returns errors about API authentication, verify your API key.
- For "bad request" errors, ensure your selected model supports vision capabilities.
- Alt text generation is processed through Craft's queue system for bulk operations, so check the queue if generation seems to be taking a long time.
- Any errors should be logged, check your queue log files!

## Disclaimer

We take no responsibility for excessive API token usage that may result from code mistakes, bugs, or security vulnerabilities within this plugin.

If you are concerned about unexpected charges we recommend:
- Set up rate limits and spending caps at the API account level in your [OpenAI account settings](https://platform.openai.com/account/billing/limits)
- Start with smaller batches when using bulk generation until you're comfortable with the costs
- Consider using the default `low` detail setting, which significantly reduces token usage
- Monitor your OpenAI API usage regularly

## Support

If you encounter any issues or have questions, please submit them on [GitHub](https://github.com/heavymetalavo/craft-aialttext/issues).
