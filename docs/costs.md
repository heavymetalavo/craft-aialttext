# 💰 Costs

[← README](../README.md) · [Getting started](getting-started.md) · [AI providers](providers.md) · [Settings](settings.md) · [Console commands](console-commands.md) · [Images](images.md) · **Costs**

## 💰 Estimated cost per alt text

These are estimates, put together by an AI assistant (Claude) that ran the plugin on four JPEG photos, averaging about 2800 × 3000 pixels, with the default settings on 16 October 2026. The plugin scales images down before sending them, so a bigger original doesn't cost much more. Prices vary between providers, so check them on yours. Claude and GPT models are listed by their Anthropic and OpenAI names and the rest by their OpenRouter names.

| Model | Estimated cost per alt text | Alt texts per $1 | Notes |
|---|---|---|---|
| `gpt-6-luna` | $0.000056 | about 18,000 |  |
| `gpt-5.6-luna` | $0.00011 | about 8,800 |  |
| `google/gemini-3.5-flash-lite` | $0.00018 | about 5,600 |  |
| `claude-haiku-5-5` | $0.00023 | about 4,300 |  |
| `qwen/qwen3.8-flash` | $0.00034 | about 3,000 |  |
| `z-ai/glm-5.3-flash` | $0.00040 | about 2,500 |  |
| `deepseek/deepseek-v4.1-flash` | $0.00052 | about 1,900 |  |
| `meta/muse-glimmer-30b` | $0.0014 | about 740 |  |
| `google/gemini-3.8-flash` | $0.0014 | about 700 |  |
| `claude-haiku-4-5` | $0.0016 | about 630 | Anthropic can retire it from 15 October 2026, so check its deprecations page. |
| `x-ai/grok-4.3` | $0.0020 | about 510 |  |
| `x-ai/grok-4.7` | $0.0025 | about 410 |  |
| `mistralai/mistral-large-4-0` | $0.0037 | about 270 | Timed out on 3 of 4 photos, so the figure comes from one request. |
| `moonshotai/kimi-k3` | $0.0056 | about 180 |  |
| `meta/muse-spark-1.3` | $0.0059 | about 170 |  |
| `x-ai/grok-4.6` | $0.0072 | about 140 | Timed out on 3 of 4 photos, so the figure comes from one request. |

## ⚠️ Avoiding unexpected charges

If you are concerned about unexpected charges we recommend:
- **Prepay a small amount.** Add credit manually, about $5 at a time. At about 18,000 alt texts per $1 with `gpt-6-luna`, that goes a long way.
- **Turn off automatic top-ups**, so a mistake can only spend what's in the account: auto-reload (auto recharge) in OpenAI's [API billing overview](https://platform.openai.com/account/billing), auto-reload on the Anthropic Console's Billing page, auto top-up on OpenRouter. If you keep OpenAI's on, set its monthly recharge limit.
- **Set a spending limit where your provider has one**, e.g. a monthly spend limit on the Anthropic Console's Billing page, or a credit limit on each [OpenRouter key](https://openrouter.ai/keys). OpenAI's prepaid balance isn't an instant cutoff, so a small negative balance is possible.
- **Try a few assets first.** Select a handful in the **Assets** manager and generate for those, then check what they cost before running **Generate missing** or **Generate all**.
- **Prefer Generate missing.** **Generate all** regenerates every asset, including ones that already have alt text.
- **Count your sites.** The bulk actions and console commands cover every site unless you pick one, and with **Save translated results for each site** each site is a request of its own, so the cost multiplies by the number of sites.
- **Mind automatic generation.** With **Generate for new image assets** on, every upload and file replacement is a request, so a large upload is a large batch.
- **Keep the default `low` settings.** `low` image detail and reasoning effort keep token use down: on `gpt-6-luna`, a large photo used 350 input tokens at `low` and about 2,000 at `high`, which made each alt text about four times the cost, and `xhigh` reasoning effort about tripled it. Consider raising them if the results aren't good enough in testing.
- **Check your usage regularly** on your provider's usage page. OpenRouter's *Activity* page shows the cost of each request.
