<?php

/**
 * AI Alt Text config
 *
 * Copy this file to `config/ai-alt-text.php` and keep only the settings you want to set. Craft doesn't load this copy,
 * and the plugin's defaults aren't read from it; the values below just show them. A setting in your copy wins over the
 * one saved on the settings page, and can be set per environment with Craft's multi-environment config (`'*'`, `'dev'`
 * and so on). Docs: https://github.com/heavymetalavo/craft-aialttext/blob/5.x/docs/settings.md
 *
 * ⚠ The settings page shows this file's values, and saving it writes what's shown to project config. A plain value,
 * e.g. `'dev' => ['aiProvider' => 'openai']` or `App::env('AI_PROVIDER')`, would be saved as `openai` and reach
 * production with the project config, so the plugin keeps the previously saved value for those instead. An
 * environment variable reference, `'$AI_PROVIDER'`, is saved as it is, since it resolves per environment.
 */

use heavymetalavo\craftaialttext\models\Settings;

return [
    // Provider: 'anthropic', 'openai', 'langdock' or 'openrouter'. Only that provider's settings below are used
    'aiProvider' => '',

    // Anthropic
    'anthropicApiKey' => '', // e.g. '$ANTHROPIC_API_KEY'
    'anthropicModel' => '',
    'anthropicImageDetailLevel' => 'low', // 'low', 'medium' or 'high'

    // OpenAI
    'openAiApiKey' => '',
    'openAiModel' => '',
    'openAiImageInputDetailLevel' => 'low', // 'low', 'high', 'auto' or 'original'
    'openAiReasoningEffort' => 'low', // 'none', 'minimal', 'low', 'medium', 'high' or 'xhigh'

    // Langdock
    'langdockApiKey' => '',
    'langdockModelAuthor' => 'anthropic', // 'anthropic' or 'openai'
    'langdockModel' => '',
    'langdockImageInputDetailLevel' => 'low', // 'low', 'high' or 'auto', for the 'openai' author only
    'langdockReasoningEffort' => 'low', // 'none', 'minimal', 'low', 'medium', 'high' or 'xhigh', for the 'openai' author only

    // OpenRouter
    'openRouterApiKey' => '',
    'openRouterModel' => '', // e.g. 'anthropic/claude-haiku-5.5'
    'openRouterImageInputDetailLevel' => 'low', // 'low', 'high', 'auto' or 'original'
    'openRouterReasoningEffort' => 'low', // 'none', 'minimal', 'low', 'medium', 'high' or 'xhigh'

    // The prompt sent to the provider, which can use `{asset.…}` and `{site.…}` variables
    'prompt' => Settings::DEFAULT_PROMPT,

    // Generate alt text when an image is uploaded or its file is replaced
    'generateForNewAssets' => true,

    // Generate alt text for SVGs, which needs Craft's Imagick image driver
    'processSvgs' => false,

    // Save translated results for each site, following the volume's Alternative Text Translation Method
    'saveTranslatedResultsToEachSite' => false,

    // Always send images as base64, rather than as URLs for the provider to download
    'sendImagesAsBase64' => false,
];
