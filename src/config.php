<?php

/**
 * AI Alt Text config.php
 *
 * This file exists only as a template for the AI Alt Text settings.
 * It does nothing on its own.
 *
 * Don't edit this file, instead copy it to 'craft/config' as 'ai-alt-text.php'
 * and uncomment the settings you want to set there. A setting you set wins over
 * the one saved on the settings page. The plugin's defaults aren't read from this
 * file, the values below just show them.
 *
 * Once copied to 'craft/config', this file will be multi-environment aware as
 * well, so you can have different settings groups for each environment, just as
 * you do for 'general.php'
 *
 * ⚠ The settings page shows this file's values, and saving it writes what's shown to project config. A plain value,
 * e.g. `'dev' => ['aiProvider' => 'openai']` or `App::env('AI_PROVIDER')`, would be saved as `openai` and reach
 * production with the project config, so the plugin keeps the previously saved value for those instead. An
 * environment variable reference, `'$AI_PROVIDER'`, is saved as it is, since it resolves per environment.
 *
 * Docs: https://github.com/heavymetalavo/craft-aialttext/blob/5.x/docs/settings.md
 */

return [
    '*' => [
        // The AI provider to use: 'anthropic', 'openai', 'langdock', 'openrouter' or 'huggingface'. Only that provider's settings below are used.
        //'aiProvider' => '',

        // The Anthropic API key. Use an environment variable reference, e.g. '$ANTHROPIC_API_KEY'.
        //'anthropicApiKey' => '',

        // The Anthropic model to use, e.g. 'claude-haiku-5-5'.
        //'anthropicModel' => '',

        // The size Anthropic images are scaled to: 'low', 'medium' or 'high'.
        //'anthropicImageDetailLevel' => 'low',

        // The OpenAI API key. Use an environment variable reference, e.g. '$OPENAI_API_KEY'.
        //'openAiApiKey' => '',

        // The OpenAI model to use, e.g. 'gpt-6-luna'.
        //'openAiModel' => '',

        // The OpenAI image detail level: 'low', 'high', 'auto' or 'original'.
        //'openAiImageInputDetailLevel' => 'low',

        // The reasoning effort for OpenAI reasoning models: 'none', 'minimal', 'low', 'medium', 'high' or 'xhigh'. Which values work depends on the model.
        //'openAiReasoningEffort' => 'low',

        // The Langdock API key. Use an environment variable reference, e.g. '$LANGDOCK_API_KEY'.
        //'langdockApiKey' => '',

        // The author of the Langdock model, which decides the API requests are sent to: 'anthropic' (models such as Claude) or 'openai' (models such as GPT).
        //'langdockModelAuthor' => 'anthropic',

        // The Langdock model to use, as named in Langdock.
        //'langdockModel' => '',

        // The Langdock image detail level: 'low', 'high' or 'auto'. Only used with the 'openai' model author.
        //'langdockImageInputDetailLevel' => 'low',

        // The reasoning effort for Langdock's 'openai' model author: 'none', 'minimal', 'low', 'medium', 'high' or 'xhigh'.
        //'langdockReasoningEffort' => 'low',

        // The OpenRouter API key. Use an environment variable reference, e.g. '$OPENROUTER_API_KEY'.
        //'openRouterApiKey' => '',

        // The OpenRouter model to use, as OpenRouter names it, e.g. 'anthropic/claude-haiku-5.5'.
        //'openRouterModel' => '',

        // How OpenRouter picks the provider that runs the model: '' (its default), 'fastest' (:nitro) or 'cheapest' (:floor).
        //'openRouterRouting' => '',

        // The OpenRouter image detail level: 'low', 'high', 'auto' or 'original'.
        //'openRouterImageInputDetailLevel' => 'low',

        // The reasoning effort for OpenRouter: 'none', 'minimal', 'low', 'medium', 'high' or 'xhigh'.
        //'openRouterReasoningEffort' => 'low',

        // The Hugging Face token. Use an environment variable reference, e.g. '$HF_TOKEN'.
        //'huggingFaceApiKey' => '',

        // The Hugging Face model id, e.g. 'deepseek-ai/DeepSeek-V4.1-Flash-1017'.
        //'huggingFaceModel' => '',

        // Which inference provider runs the Hugging Face model: '' (Hugging Face chooses), 'fastest', 'cheapest', or a provider such as 'deepinfra'.
        //'huggingFaceRouting' => '',

        // The prompt sent to the provider, which can use `{asset.…}` and `{site.…}` variables.
        //'prompt' => \heavymetalavo\craftaialttext\models\Settings::DEFAULT_PROMPT,

        // Generate alt text when an image is uploaded or its file is replaced.
        //'generateForNewAssets' => true,

        // Generate alt text for SVGs, which needs Craft's Imagick image driver.
        //'processSvgs' => false,

        // How many frames of an animated GIF or WebP to send, evenly spaced. The settings page allows 1 to 20; this file
        // can set more, up to the provider's limit on images in one request, e.g. 100 for Anthropic's 200k-context models.
        //'animationFrames' => 3,

        // Save translated results for each site, following the volume's Alternative Text Translation Method.
        //'saveTranslatedResultsToEachSite' => false,

        // Always send images as base64, rather than as URLs for the provider to download.
        //'sendImagesAsBase64' => false,

        // How many times a queued job that hits a provider's rate limit is retried before it fails. 0 turns retrying off.
        //'maxRateLimitRetries' => 5,
    ],

    // Settings for one environment win over the ones above, e.g. a cheap model while developing. Uncomment the one for
    // your provider (see docs/costs.md). The provider and its API key come from the settings or the '*' group.
    //'dev' => [
    //    // OpenAI
    //    'openAiModel' => 'gpt-6-luna',
    //
    //    // OpenRouter
    //    'openRouterModel' => 'openai/gpt-6-luna',
    //
    //    // Anthropic
    //    'anthropicModel' => 'claude-haiku-5-5',
    //
    //    // Langdock
    //    'langdockModelAuthor' => 'openai',
    //    'langdockModel' => 'gpt-6-luna',
    //],
];
