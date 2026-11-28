# 🤖 AI providers

[← README](../README.md) · [Getting started](getting-started.md) · **AI providers** · [Settings](settings.md) · [Console commands](console-commands.md) · [Images](images.md) · [Costs](costs.md)

Setting up an API key for each provider, and choosing a model.

## Anthropic

1. Visit [https://console.anthropic.com/](https://console.anthropic.com/) and [sign up](https://console.anthropic.com/).
2. Navigate to **Settings → API Keys**.
3. Create a new API key and save it to an environment variable (`.env`).
4. Ensure you have credits in your account under **Billing**.

## OpenAI

1. Sign up at [platform.openai.com](https://platform.openai.com/signup), then click the ⚙️ icon in the top right.
2. Go to **Organization → API keys → Create new secret key**, name it and choose a project.
3. Under **Permissions**, choose **Restricted** and set **Model capabilities** and **Responses API** to **Write**.
4. Save the key to an environment variable (`.env`): it's only shown once. Then add credit under **Organization → Billing**: $5 goes a long way.

## Google

Gemini models, called directly with an API key from Google AI Studio, through Google's [Interactions API](https://ai.google.dev/gemini-api/docs/interactions-overview).

1. Visit [Google AI Studio](https://aistudio.google.com/apikey) and sign in with a Google account.
2. Click **Create API key**, and choose a Google Cloud project for it (AI Studio can create one).
3. Copy the key and save it to an environment variable (`.env`), for example `GOOGLE_API_KEY`, then set **Google API Key** to `$GOOGLE_API_KEY`.
4. Set **Google Model** to a Gemini model's name that accepts images, e.g. `gemini-3.5-flash-lite`, which is the default. See [Google's models](https://ai.google.dev/gemini-api/docs/models).
5. A free key is rate limited, and Google's terms say how it uses content sent on the free tier. For a production site, set up billing for the project in AI Studio.

Worth knowing:

- Every request asks Google not to store the request and its response (`store: false`). Google otherwise keeps them for 1 day on the free tier and 55 days on a paid one.
- Images are always sent as base64, scaled so the long edge is at most 1024px. GIFs, which Gemini doesn't read, are sent as JPGs.
- There is no image detail or reasoning setting, so each model uses its own thinking default.
- Google Cloud's Vertex AI, which signs in with a service account rather than an API key, is not supported. Gemini models are also available through [OpenRouter](#openrouter), as `google/…`.

## Langdock

1. In [Langdock](https://langdock.com/), click your account name (bottom left), then go to **Settings → Products → API**. On the **Workspace API keys** tab tick only **Completion API**, which covers both the OpenAI and Anthropic endpoints this plugin uses (see the [Completion API overview](https://docs.langdock.com/en/developer/completion-api/completion-overview)), then click **Create API key**. Creating a workspace key needs a workspace admin. The **Personal API keys** tab on the same page also works, see [Personal API keys](https://docs.langdock.com/en/using-langdock/account/personal-api-keys).
2. Copy the new key and save it to an environment variable (`.env`).
3. Choose the **Langdock Model Author** of the model you want to use: `Anthropic` for models such as Claude, `OpenAI` for models such as GPT. Both use the same API key.

Requests go to Langdock's EU region, where Langdock hosts its OpenAI and Anthropic models. Dedicated Langdock deployments are not supported yet: requests always go to `api.langdock.com`.

## OpenRouter

1. Visit [https://openrouter.ai/](https://openrouter.ai/) and sign up or log in.
2. Make sure your account has some credit, a few dollars goes a long way with the small models.
3. Go to [Keys](https://openrouter.ai/keys), create a key, and give it a name and, if you like, a credit limit.
4. Copy the key and save it to an environment variable (`.env`), for example `OPENROUTER_API_KEY`.
5. In the plugin settings, set **OpenRouter API Key** to that variable, e.g. `$OPENROUTER_API_KEY`.
6. Set the **OpenRouter Model** to the model's OpenRouter name, which starts with the model's author, e.g. `anthropic/claude-haiku-5.5` or `openai/gpt-6-luna` (see [OpenRouter models](#-openrouter-models) for how to find ones that accept images). A free model, one whose name ends in `:free`, is chosen here too.
7. Optionally set **OpenRouter Routing**: **Default** weighs price, speed and reliability, and tries another provider if one fails; **Fastest** and **Cheapest** add OpenRouter's [`:nitro` and `:floor` suffixes](https://openrouter.ai/docs/guides/routing/model-variants), which can use endpoints billed at their own rate (priority, costing more, for Fastest; flex, costing less, for Cheapest). A model that already ends in `:nitro`, `:floor`, `:exacto` or `:free` is used as it is, so a free model only has **Default**. Picking a particular provider isn't supported.

OpenRouter takes the same request for every model, so there is no model author to choose and any model that accepts image input can be used. The plugin is built for and tested with OpenAI and Anthropic models. Models from other authors aren't supported, but [the ones tested](costs.md) worked unchanged, and how good the alt text is varies between models. Worth knowing:

- Some models think before they answer and are slow (Grok 4.6 and Mistral Large took over 40 seconds), so raise the request timeout in `config/guzzle.php` if you see a timeout error.
- A provider sometimes rate-limits a model for a moment ("temporarily rate-limited upstream"). Queued jobs wait and try again on their own, see [Troubleshooting](getting-started.md#-troubleshooting).
- New OpenRouter accounts can be limited to as few as 20 requests per minute for a model, which a bulk run will pass. Switching to a different model until the account has more history is the simplest fix, see [Troubleshooting](getting-started.md#-troubleshooting).
- A few models need a setting in your OpenRouter account, and the error says which and links to it.
- Requests send your primary site's URL as the `HTTP-Referer` header, with `heavymetalavo/craft-aialttext` as the title, so your OpenRouter logs list them under that app name. The app is marked hidden, which keeps it out of OpenRouter's public rankings. This works from the console and the queue as well, as long as the primary site's URL is a full URL (an environment variable that holds one is fine). A URL that uses the `@web` alias has no value in the console, so requests made there send none of these headers.

## Hugging Face

Hugging Face's [Inference Providers](https://huggingface.co/docs/inference-providers/index) router reaches open-weight models run by inference providers (the services that host them, such as DeepInfra and Together) with one Hugging Face token, billed at their own prices with no extra fee. For these models it uses [OpenRouter's routing](https://huggingface.co/docs/inference-providers/routing): each request goes to the inference provider with the best mix of price, speed and reliability, and is retried on another one if it fails.

1. Visit [https://huggingface.co/](https://huggingface.co/) and sign up or log in.
2. Make sure the account has Inference Providers credit: PRO accounts get some each month, otherwise buy some under **Billing**.
3. Create a [fine-grained token with the "Make calls to Inference Providers" permission](https://huggingface.co/settings/tokens/new?ownUserPermissions=inference.serverless.write&tokenType=fineGrained).
4. Copy the token and save it to an environment variable (`.env`), for example `HF_TOKEN`, then set **Hugging Face Token** to `$HF_TOKEN`.
5. Set **Hugging Face Model** to a vision model's id, e.g. `deepseek-ai/DeepSeek-V4.1-Flash-1017`. The dropdown suggests the vision models the router serves, with the cheapest provider's price per million input tokens, cached for a day, or [browse them on Hugging Face](https://huggingface.co/models?pipeline_tag=image-text-to-text&inference_provider=all).
6. Optionally set **Hugging Face Routing**: **Default** lets the router weigh price, speed and reliability and retry elsewhere, **Fastest** and **Cheapest** pick by throughput or price, or choose one of the providers listed for the model, cheapest first, such as `deepinfra`. It's added to the model as a suffix, e.g. `…:cheapest`, unless the model already has one.

This provider uses Hugging Face's Chat Completions API, not its Responses API: the Responses API is in beta and rejects a request over about 100 KB, which an image scaled for a vision model usually passes. Images are always sent as base64, as for OpenRouter, because in testing some models answered that they saw no image, with no error, when they couldn't use an image URL. There is no image detail or reasoning setting, so each model uses its own reasoning defaults, but the request always includes an image `detail` value: `low` for images 512px or smaller and `auto` for larger ones. How good the alt text is varies between models more than on the other providers.

## Custom endpoints

For a gateway or provider the plugin doesn't support, which takes OpenAI's Responses API, Anthropic's Messages API, the Chat Completions API or Google's Interactions API request unchanged, as Langdock does. Custom endpoints aren't supported: the plugin is only tested with the other providers on this page, so test one before relying on it. Use `https`: the endpoint receives each image and the API key, so only point it at a gateway you trust.

1. Set **AI Provider** to **Custom endpoint**.
2. Set **Custom Endpoint Base URL** to the URL the requests go under, without `/responses`, `/messages`, `/chat/completions` or `/interactions` at the end, e.g. `https://gateway.example.com/v1`.
3. Set **Custom Endpoint API Format**:
   - **Responses API** posts to `{base URL}/responses`, the request OpenAI, Langdock's GPT models and OpenRouter take, with the key as a Bearer token (`Authorization: Bearer …`).
   - **Messages API** posts to `{base URL}/messages`, the request Anthropic and Langdock's Claude models take, with the key sent both as `x-api-key` and as a Bearer token, and `anthropic-version: 2023-06-01`.
   - **Chat Completions API** posts to `{base URL}/chat/completions`, the request OpenAI, OpenRouter, Hugging Face and many other hosts take, with the key as a Bearer token.
   - **Interactions API** posts to `{base URL}/interactions`, the request Google's Gemini API takes, with the key sent as `x-goog-api-key`.
4. Set the API key and the model, as the endpoint names it.

Images are always sent as base64, as a gateway may not be able to fetch URLs. With the Responses API and the Chat Completions API, the image detail level is `low`, images are scaled to 1024px on the long edge, and no reasoning setting is sent; with the Messages API, images are scaled as Anthropic's are at the `low` detail level; with the Interactions API, images are scaled as for Google.

## 🧠 Model Options

All vision models should work. In our tests these small models hit the sweet spot between quality and cost:
- **Top pick:** `claude-haiku-5-5` (`anthropic/claude-haiku-5.5` on OpenRouter), on Anthropic, Langdock or OpenRouter. The most accurate in our tests, and the most consistent across languages.
- **Runner-up:** `gemini-3.5-flash-lite` (`google/gemini-3.5-flash-lite` on OpenRouter), on Google or OpenRouter. Nearly as accurate, faster and a little cheaper.
- **Cheapest:** `gpt-6-luna`, on OpenAI or OpenRouter. Fine for most photos, but it got more details wrong on illustrations, logos and animations.

See [Estimated cost per alt text](costs.md) to compare providers and models.

To find out which models are capable of vision, check [the models page](https://platform.openai.com/docs/models), click into a model's detail page (e.g., [gpt-6-luna](https://platform.openai.com/docs/models/gpt-6-luna)) and look for "**Input**: Text, image" in the features columns at the top.

## 🌐 OpenRouter models

The plugin doesn't keep a list of vision models, since OpenRouter's catalogue changes every week. For what some popular ones cost, see [Estimated cost per alt text](costs.md).

**Check that a model accepts images**
- Start typing in the **OpenRouter Model** field: it suggests every model that takes images and gives only text, with its price per million input tokens, except `:batch` ones, which can't be used here. The list is cached for a day.
- Open the [models page filtered to image input and text output](https://openrouter.ai/models?input_modalities=image&output_modalities=text). Every model listed can be used, and a model's own page shows the input modalities it accepts. Models on the page's *Image* tab generate images and return no text, so they can't write alt text.
- Or ask the API, which needs no key: `curl -s 'https://openrouter.ai/api/v1/models?input_modalities=image&output_modalities=text' | jq -r '.data[] | select(.architecture.output_modalities == ["text"]) | .id' | grep -v ':batch$'` prints the ids to use as the **OpenRouter Model**.
- A model that can't read images is refused with "No endpoints found that support image input".
