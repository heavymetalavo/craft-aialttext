# 🤖 AI providers

[← README](../README.md) · [Getting started](getting-started.md) · **AI providers** · [Settings](settings.md) · [Console commands](console-commands.md) · [Images](images.md) · [Costs](costs.md)

Setting up an API key for each provider, and choosing a model.

## Anthropic

1. Visit [https://console.anthropic.com/](https://console.anthropic.com/) and [sign up](https://console.anthropic.com/).
2. Navigate to **Settings → API Keys**.
3. Create a new API key and save it to an environment variable (`.env`).
4. Ensure you have credits in your account under **Billing**.

## Langdock

1. In [Langdock](https://langdock.com/), click your account name (bottom left), then go to **Settings → Products → API**. On the **Workspace API keys** tab tick only **Completion API**, which covers both the OpenAI and Anthropic endpoints this plugin uses (see the [Completion API overview](https://docs.langdock.com/en/developer/completion-api/completion-overview)), then click **Create API key**. Creating a workspace key needs a workspace admin. The **Personal API keys** tab on the same page also works, see [Personal API keys](https://docs.langdock.com/en/using-langdock/account/personal-api-keys).
2. Copy the new key and save it to an environment variable (`.env`).
3. Choose the **Model Author** of the model you want to use: `Anthropic` for models such as Claude, `OpenAI` for models such as GPT. Both use the same API key.

Requests go to Langdock's EU region, where Langdock hosts its OpenAI and Anthropic models. Dedicated Langdock deployments are not supported yet: requests always go to `api.langdock.com`.

## OpenRouter

1. Visit [https://openrouter.ai/](https://openrouter.ai/) and sign up or log in.
2. Make sure your account has some credit, a few dollars goes a long way with the small models.
3. Go to [Keys](https://openrouter.ai/keys), create a key, and give it a name and, if you like, a credit limit.
4. Copy the key and save it to an environment variable (`.env`), for example `OPENROUTER_API_KEY`.
5. In the plugin settings, set **OpenRouter API Key** to that variable, e.g. `$OPENROUTER_API_KEY`.
6. Set the **Model** to the model's OpenRouter name, which starts with the model's author, e.g. `anthropic/claude-haiku-5.5` or `openai/gpt-6-luna` (see [OpenRouter models](#-openrouter-models) for how to find ones that accept images).

OpenRouter takes the same request for every model, so there is no model author to choose and any model that accepts image input can be used. The plugin is built for and tested with OpenAI and Anthropic models. Models from other authors aren't supported, but [the ones tested](costs.md) worked unchanged, and how good the alt text is varies between models. Worth knowing:

- Some models think before they answer and are slow (Grok 4.6 and Mistral Large took over 40 seconds), so raise the request timeout in `config/guzzle.php` if you see a timeout error.
- A provider sometimes rate-limits a model for a moment ("temporarily rate-limited upstream"). Queued jobs wait and try again on their own, see [Troubleshooting](getting-started.md#-troubleshooting).
- New OpenRouter accounts can be limited to as few as 20 requests per minute for a model, which a bulk run will pass. Switching to a different model until the account has more history is the simplest fix, see [Troubleshooting](getting-started.md#-troubleshooting).
- A few models need a setting in your OpenRouter account, and the error says which and links to it.
- Requests send your primary site's URL as the `HTTP-Referer` header, with `heavymetalavo/craft-aialttext` as the title, so your OpenRouter logs list them under that app name. The app is marked hidden, which keeps it out of OpenRouter's public rankings. This works from the console and the queue as well, as long as the primary site's URL is a full URL (an environment variable that holds one is fine). A URL that uses the `@web` alias has no value in the console, so requests made there send none of these headers.

## OpenAI

1. Visit [https://platform.openai.com/](https://platform.openai.com/) and [sign up](https://platform.openai.com/signup) in the top-right.
2. Revisit [the API platform home page](https://platform.openai.com/) again
3. Click the ⚙️ icon in the top-right
4. Left menu > Organization > API keys > + Create new secret key (top-right)
5. Create a name and assign to a suitable project
6. Permissions > Restricted > set "Model capabilities" to "Write" and "Responses API" to "Write"
7. Save API key to an env var, you wont get to see it again!
8. Make sure you have a credit balance! Left menu > Organization > Billing, loading $5 is probably going to get you quite far, disabling auto recharge might be safer though that's up to you!

## 🧠 Model Options

All vision models should work. In our tests these small models hit the sweet spot between quality and cost:
- **Top pick:** `claude-haiku-5-5` (`anthropic/claude-haiku-5.5` on OpenRouter), on Anthropic, Langdock or OpenRouter. The most accurate in our tests, and the most consistent across languages.
- **Runner-up:** `google/gemini-3.5-flash-lite`, on OpenRouter only. Nearly as accurate, faster and a little cheaper.
- **Cheapest:** `gpt-6-luna`, on OpenAI or OpenRouter. Fine for most photos, but it got more details wrong on illustrations, logos and animations.

See [Estimated cost per alt text](costs.md) to compare providers and models.

To find out which models are capable of vision, check [the models page](https://platform.openai.com/docs/models), click into a model's detail page (e.g., [gpt-6-luna](https://platform.openai.com/docs/models/gpt-6-luna)) and look for "**Input**: Text, image" in the features columns at the top.

## 🌐 OpenRouter models

The plugin doesn't keep a list of vision models, since OpenRouter's catalogue changes every week. For what some popular ones cost, see [Estimated cost per alt text](costs.md).

**Check that a model accepts images**
- Open the [models page filtered to image input and text output](https://openrouter.ai/models?input_modalities=image&output_modalities=text). Every model listed can be used, and a model's own page shows the input modalities it accepts. Models on the page's *Image* tab generate images and return no text, so they can't write alt text.
- Or ask the API, which needs no key: `curl -s 'https://openrouter.ai/api/v1/models?input_modalities=image' | jq -r '.data[].id'` prints the ids to use as the **Model**.
- A model that can't read images is refused with "No endpoints found that support image input".
