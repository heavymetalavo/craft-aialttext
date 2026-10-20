# ⚙️ Settings

[← README](../README.md) · [Getting started](getting-started.md) · [AI providers](providers.md) · **Settings** · [Console commands](console-commands.md) · [Images](images.md) · [Costs](costs.md)

After installation, configure the plugin at **Settings → AI Alt Text**:

## 📊 Settings overview

| Setting | Description |
|---------|-------------|
| **AI Provider** | Choose between OpenAI, Anthropic, Langdock or OpenRouter. |
| **OpenAI/Anthropic/Langdock/OpenRouter API Key** | Your provider's API key. |
| **Model** | The AI model to use (e.g., `gpt-6-luna` or `claude-haiku-5-5`). For Langdock, use the model's name in Langdock. For OpenRouter, use its OpenRouter name, e.g. `anthropic/claude-haiku-5.5` or `openai/gpt-6-luna`. |
| **Langdock Model Author** | Langdock only. `Anthropic` (models such as Claude) or `OpenAI` (models such as GPT). It must be the author of the model, and decides which of Langdock's compatible APIs requests are sent to. |
| **Langdock Image Detail Level** | Langdock with the `OpenAI` model author only. Works like the OpenAI image detail level, `low`, `high` or `auto`. |
| **Langdock Reasoning Effort** | Langdock with the `OpenAI` model author only. Works like **OpenAI Reasoning Effort**, see [the options below](#-openai-reasoning-effort-options). |
| **OpenRouter Image Detail Level** | OpenRouter only. Works like the OpenAI image detail level, `low`, `high`, `original` or `auto`. OpenAI and Gemini models use it, and in testing Qwen, GLM and Grok models ignored it. For Claude models it picks the size limit, as the Anthropic setting does. |
| **OpenRouter Reasoning Effort** | OpenRouter only. Works like **OpenAI Reasoning Effort**, see [the options below](#-openai-reasoning-effort-options). Only sent to OpenAI reasoning models. |
| **Detail Level**| How detailed the image analysis should be (controls resolution/scaling). |
| **OpenAI Reasoning Effort**| The reasoning effort level for OpenAI reasoning models. |
| **Prompt** | The instructions sent to the AI provider as the system / instruction message (example [below](#-default-prompt)). Supports `{asset.property}` and `{site.property}` variables, plus `{site.languageName}` for the language's display name (e.g. `English (United Kingdom)`). |
| **Propagate** | Whether the asset should be saved across all of its supported sites, if enabled it could save the same initial alt text value across all sites. |
| **Generate for new image assets (on upload or file replacement)** | Automatically generate alt text when new assets are created, or when an asset's file is replaced. On replacement, the alt text for the site the replacement was made in is overwritten, since it describes the old image; other sites keep their existing alt text unless **Save translated results for each site** is enabled. |
| **Process SVGs** | Attempt to generate alt text for SVG files when they are uploaded or batched processed. |
| **Save translated results for each site** | Save translated results to translatable fields for each site. |

## 💬 Default prompt

This is sent to the provider as the system / instruction message (Anthropic `system`, OpenAI `instructions`); the image is sent in the user turn with a short trigger. `{site.languageName}` resolves to the language's display name and `{site.language}` to its BCP 47 language tag (e.g. `en-GB`), so the default pairs them - edit the parenthesised format freely.

> Describe the image provided (roughly 150 characters). The output MUST be suitable for use directly as an HTML alt attribute value. Consider transparency within the image if supported by the file type, e.g. don't suggest it has a dark background if it is transparent. When describing a person do not assume their gender. Do not add a prefix of any kind (e.g. "#", "alt text:", "An image of", "A photo of"). Do not wrap the output in quotes. Output in the language: {site.languageName} (BCP 47: {site.language})

## 🔍 Image detail options

**OpenAI:**
- `low` - Fast, low-cost (512px x 512px) (default)
- `high` - Standard high-fidelity understanding
- `original` - Large, dense, spatially sensitive images (gpt-5.4+)
- `auto` - Let the model choose

**Anthropic:**
- `low` - 500px x 500px (recommended default)
- `medium` - 1000px x 1000px
- `high` - 1568px x 1568px

## 🧠 OpenAI Reasoning Effort options

Controls how much time the model spends "thinking" before generating a response (only applicable to OpenAI reasoning models like `o*` (e.g. `o1`) and `gpt-5*`). The default is `low`.
- `none`
- `minimal`
- `low`
- `medium`
- `high`
- `xhigh`

Which values work depends on the model, as tested on 19 October 2026:

| Models | Accepted | Refused |
|---|---|---|
| `gpt-5` models, e.g. `gpt-5-nano`, `gpt-5-mini` | `minimal`, `low`, `medium`, `high` | `none`, `xhigh` |
| Newer models, e.g. `gpt-6-luna`, `gpt-5.6-luna` | `none`, `low`, `medium`, `high`, `xhigh` | `minimal` |

`low` works on both, which is why it's the default. Langdock behaves like OpenAI. OpenRouter was more forgiving with `openai/gpt-6-luna`, where every value worked, but it still refused `none` on `openai/gpt-5-nano`. A refused value fails the request with an error naming the values the model supports.

**Langdock** with the `Anthropic` model author has no image detail setting: images are scaled to the `low` size (500px x 500px) and always sent as base64, since Langdock does not accept image URLs. With the `OpenAI` model author, **Langdock Image Detail Level** (`low`, `high` or `auto`) and **Langdock Reasoning Effort** work like the OpenAI settings above (both default to `low`).

**OpenRouter** always sends images as base64. It scales them as OpenAI does, except for Claude models (`anthropic/…`), which are scaled as on the Anthropic API: the detail level picks the long-edge limit (500px at `low`, 1000px at `auto`, 1568px at `high` or `original`) as the Anthropic setting does, and a token budget of about 1,600 tokens (about 1.2 megapixels) also applies, which is what sets the size of larger photos. OpenAI models use **OpenRouter Image Detail Level** and **OpenRouter Reasoning Effort** (both default to `low`). The image detail level is sent to every model, and Gemini models also change how many tokens they use with it, while Qwen, GLM and Grok models ignored it in testing. The reasoning effort is only sent to OpenAI reasoning models, and other models use their own defaults.

For more information, refer to the [OpenAI](https://platform.openai.com/docs/guides/images) and [Anthropic](https://docs.anthropic.com/en/docs/build-with-claude/vision) documentation.

## 🔐 Permissions

Bulk generation is gated by the **AI Alt Text Bulk Actions** utility permission, which Craft registers automatically for the plugin's utility. Grant it to the relevant user groups under **Settings → Users → (group or user) → Permissions → Utilities** after installing. Admins always have it. One checkbox covers viewing the utility and running its "Generate all" / "Generate missing" actions.

Generating for a single asset doesn't have its own permission - it's available to anyone who can save the asset in question (for assets uploaded by other users this requires the "Save assets uploaded by other users" volume permission, matching what they could edit manually). Automatic generation on upload or file replacement is controlled by the "Generate for new image assets" setting alone.
