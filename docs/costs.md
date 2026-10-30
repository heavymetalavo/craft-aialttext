# 💰 Costs

[← README](../README.md) · [Getting started](getting-started.md) · [AI providers](providers.md) · [Settings](settings.md) · [Console commands](console-commands.md) · [Images](images.md) · **Costs**

## 💰 Estimated cost per alt text

These are estimates, put together by an AI assistant (Claude) that ran the plugin on four JPEG photos, averaging about 2800 × 3000 pixels, with the default settings on 16 to 20 October 2026. The plugin scales images down before sending them, so a bigger original doesn't cost much more. Prices vary between providers, so check them on yours. Claude and GPT models are listed by their Anthropic and OpenAI names and the rest by their OpenRouter names. A few OpenRouter models also have a free version, with `:free` on the end of the name (the last rows are the most popular ones that accept images). It costs nothing but is [rate limited](https://openrouter.ai/docs/api/reference/limits), to 20 requests a minute and a daily maximum. Not all of them work in the plugin, as the notes say. The plugin doesn't handle rate limits yet, so a bulk run will pass them and leave failed jobs in the queue. If you want to use one, retry those jobs from the queue once you're back under the limit.

| Model | Estimated cost per alt text | Approx alt texts per $1 | Notes |
|---|---|---|---|
| `gpt-6-luna` with `none` reasoning effort | $0.000049 | 20,000 | Measured on 23 October over 61 requests on 33 varied images, relative to `low`. It misread logo text more often than at `low`. |
| `gpt-6-luna` | $0.000056 | 18,000 | At the default `low` reasoning effort. |
| `gpt-6-luna` with `medium` reasoning effort | $0.000078 | 13,000 | Measured on 23 October over 61 requests on 33 varied images, relative to `low`. |
| `gpt-6-luna` with `high` reasoning effort | $0.000098 | 10,000 | Measured on 23 October over 61 requests on 33 varied images, relative to `low`. |
| `xiaomi/mimo-v2.6-flash` | $0.00010 | 9,700 |  |
| `gpt-5.6-luna` | $0.00011 | 8,800 |  |
| `gpt-6-luna` with `xhigh` reasoning effort | $0.00012 | 8,100 | Measured on 23 October over 61 requests on 33 varied images, relative to `low`. |
| `google/gemini-3.5-flash-lite` | $0.00018 | 5,600 |  |
| `claude-haiku-5-5` | $0.00023 | 4,300 |  |
| `qwen/qwen3.8-flash` | $0.00034 | 3,000 |  |
| `deepseek/deepseek-v4.1-flash-1017` | $0.00035 | 2,800 | Released 17 October. [DeepSeek's announcement](https://api-docs.deepseek.com/news/news261017/) claims faster task completion and shorter outputs than the previous version, DeepSeek V4.1 Flash. On DeepSeek's own coding benchmark (DeepSWE v1.1) its `max` effort scored 77.0%, ahead of GPT-6 Astra, Claude Opus 5 and Claude Fable 5 in their chart, at a fraction of the cost per task. |
| `z-ai/glm-5.3-flash` | $0.00040 | 2,500 |  |
| `deepseek/deepseek-v4.1-flash` | $0.00052 | 1,900 |  |
| `gpt-6-sol` | $0.0011 | 870 | Costs about 20 times more than `gpt-6-luna`. |
| `google/gemini-4-argon` | $0.0012 | 850 |  |
| `meta/muse-glimmer-30b` | $0.0014 | 740 |  |
| `google/gemini-3.8-flash` | $0.0014 | 700 |  |
| `claude-haiku-4-5` | $0.0016 | 630 | Anthropic can retire it from 15 October 2026, so check its deprecations page. |
| `x-ai/grok-4.3` | $0.0020 | 510 |  |
| `x-ai/grok-4.7` | $0.0025 | 410 |  |
| `claude-sonnet-5-5` | $0.0035 | 290 | Costs about 15 times more than `claude-haiku-5-5`. |
| `mistralai/mistral-large-4-0` | $0.0037 | 270 | Timed out on 3 of 4 photos, so the figure comes from one request. |
| `moonshotai/kimi-k3` | $0.0056 | 180 |  |
| `gpt-6-astra` | $0.0057 | 180 | Costs about 100 times more than `gpt-6-luna`. |
| `meta/muse-spark-1.3` | $0.0059 | 170 |  |
| `claude-opus-5-5` | $0.0068 | 150 | Costs about 30 times more than `claude-haiku-5-5`. |
| `x-ai/grok-4.6` | $0.0072 | 140 | Timed out on 3 of 4 photos, so the figure comes from one request. |
| `minimax/minimax-m3.1-flash:free` | Free | – | Released 19 October. [MiniMax reports](https://www.minimax.io/blog/minimax-m31f) 73.7% on DeepSWE v1.1, about level with Claude Opus 5 and GPT-6 Astra but behind DeepSeek V4.1 Flash 1017 (77.0% in [DeepSeek's chart](https://api-docs.deepseek.com/news/news261017/)), at about 30 times lower token prices than Opus 5 and 70 times lower than Astra, and the same as DeepSeek V4.1 Flash 1017. Only a free version is listed so far (a paid one isn't out yet), and it is [rate limited](https://openrouter.ai/docs/api/reference/limits). |
| `nvidia/nemotron-3.5-super-vl:free` | Free | – |  |
| `dots-studio/dots-3-note-preview:free` | Free | – | Timed out on 2 of 4 photos |
| `google/gemma-4-31b-it:free` | Free | – | Rate limited upstream by Google when tested, which fails the queue job. |
| `google/gemma-4-26b-a4b-it:free` | Free | – | Smaller version of Gemma 4 31B. Also rate limited upstream by Google when tested. |

## ⚠️ Avoiding unexpected charges

If you are concerned about unexpected charges we recommend:
- **Prepay a small amount.** Add credit manually, about $5 at a time. At about 18,000 alt texts per $1 with `gpt-6-luna`, that goes a long way.
- **Turn off automatic top-ups**, so a mistake can only spend what's in the account: auto-reload (auto recharge) in OpenAI's [API billing overview](https://platform.openai.com/account/billing), auto-reload on the Anthropic Console's Billing page, auto top-up on OpenRouter. If you keep OpenAI's on, set its monthly recharge limit.
- **Set a spending limit where your provider has one**, e.g. a monthly spend limit on the Anthropic Console's Billing page, or a credit limit on each [OpenRouter key](https://openrouter.ai/keys). OpenAI's prepaid balance isn't an instant cutoff, so a small negative balance is possible.
- **Try a few assets first.** Select a handful in the **Assets** manager and generate for those, then check what they cost before running **Generate missing** or **Generate all**.
- **Prefer Generate missing.** **Generate all** regenerates every asset, including ones that already have alt text.
- **Count your sites.** The bulk actions and console commands cover every site unless you pick one, and with **Save translated results for each site** each site is a request of its own, so the cost multiplies by the number of sites.
- **Mind automatic generation.** With **Generate for new image assets** on, every upload and file replacement is a request, so a large upload is a large batch.
- **Keep Animation frames at 1 unless you need more.** Above 1, each animated GIF or WebP is sent as that many images, so with the default **Animation strategy** it costs roughly that many times as much as a still image. A film strip costs about as much as one large image. One request per frame costs the most: a request for each frame and one more to combine them. Other images aren't affected.
- **Keep the default `low` settings.** `low` image detail and reasoning effort keep token use down: on `gpt-6-luna`, a large photo used 350 input tokens at `low` and about 2,000 at `high`, which made each alt text about four times the cost, and `xhigh` reasoning effort about doubled it. Consider raising them if the results aren't good enough in testing.
- **Check your usage regularly** on your provider's usage page. OpenRouter's *Activity* page shows the cost of each request.
