<?php

namespace heavymetalavo\craftaialttext;

use CraftCms\Cms\Asset\Events\AssetReplaced;
use CraftCms\Cms\Cp\Events\CpAlertsResolving;
use CraftCms\Cms\Cp\SelectOptions;
use CraftCms\Cms\Element\Events\{ElementActionsResolving, ElementActionMenuItemsResolving, ElementLifecycleSaved};
use CraftCms\Cms\Form\Controls\{Combobox, Lightswitch, Text, Textarea};
use CraftCms\Cms\Form\{Form, FormContext};
use CraftCms\Cms\Form\Nodes\{Field, Group, Heading, MarkdownContent, Separator};
use CraftCms\Cms\Plugin\Plugin;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Facades\{Images, Plugins};
use heavymetalavo\craftaialttext\Commands\{GenerateAll, GenerateMissing, GenerateSingle, GenerateStats};
use heavymetalavo\craftaialttext\listeners\{AddAssetActionMenuItem, AddConfigurationAlert, QueueAltTextForNewAsset, RegenerateAltTextOnReplace, RegisterAssetElementActions, RestoreFailedJobDescription};
use heavymetalavo\craftaialttext\models\Settings;
use heavymetalavo\craftaialttext\utilities\AiAltTextUtility;
use Illuminate\Queue\Events\JobFailed;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * AI Alt Text Plugin
 *
 * A Craft CMS plugin that generates alt text for images using AI vision models.
 */
class AiAltText extends Plugin
{
    public string $schemaVersion = '1.2.0';
    public bool $hasCpSettings = true;

    /**
     * @var string Permission required to run the sweeping bulk actions (generate for all/missing
     * assets across sites).
     *
     * This is the permission Craft registers automatically for the bulk actions utility
     * (`utility:` + AiAltTextUtility::id()), so one checkbox under Utilities covers both
     * viewing the utility and executing its bulk actions.
     */
    public const PERMISSION_BULK_ACTIONS = 'utility:ai-alt-text-bulk-actions';

    /**
     * @var array The reasoning effort options, shared by the OpenAI, Langdock and OpenRouter settings
     */
    private const REASONING_EFFORT_OPTIONS = [
        ['label' => 'None', 'value' => 'none'],
        ['label' => 'Minimal', 'value' => 'minimal'],
        ['label' => 'Low', 'value' => 'low'],
        ['label' => 'Medium', 'value' => 'medium'],
        ['label' => 'High', 'value' => 'high'],
        ['label' => 'Extra High', 'value' => 'xhigh'],
    ];

    public array $commands = [
        GenerateSingle::class,
        GenerateMissing::class,
        GenerateAll::class,
        GenerateStats::class,
    ];

    protected array $utilities = [
        AiAltTextUtility::class,
    ];

    /**
     * Event listeners, registered by Craft's HasListeners concern.
     *
     * These have to be listener classes rather than closures: `bootPlugin()` is final as of
     * Craft 6 alpha.14, so there is no longer a hook in which to register closures.
     */
    protected array $events = [
        ElementActionsResolving::class => RegisterAssetElementActions::class,
        ElementActionMenuItemsResolving::class => AddAssetActionMenuItem::class,
        JobFailed::class => RestoreFailedJobDescription::class,
        ElementLifecycleSaved::class => QueueAltTextForNewAsset::class,
        AssetReplaced::class => RegenerateAltTextOnReplace::class,
        CpAlertsResolving::class => AddConfigurationAlert::class,
    ];

    /**
     * Translates a message and substitutes `{placeholder}` parameters.
     *
     * Craft's t() can't be relied on for the substitution. It asks the Yii translator first,
     * which returns the message untouched when the plugin's category has no registered source,
     * and then falls through to Laravel's __(), which only understands `:placeholder` syntax.
     * The net effect is that `{filename}` and friends reached the UI verbatim — the queue showed
     * jobs named "Generating alt text for {filename} (ID: {id}{siteMessageSuffix})".
     *
     * Translation still goes through t(), so a translation file would be picked up; only the
     * parameter substitution is done here.
     */
    public static function t(string $message, array $params = []): string
    {
        $translated = t($message, category: 'ai-alt-text');

        if ($params === []) {
            return $translated;
        }

        $replacements = [];
        foreach ($params as $key => $value) {
            $replacements['{' . $key . '}'] = (string) $value;
        }

        return strtr($translated, $replacements);
    }

    /**
     * Returns the plugin settings, with a fallback to loading directly from the project
     * config when the Plugins service registry hasn't been populated (e.g. in the queue
     * context where Plugin::create() may fail before registerPlugin() is called).
     */
    public static function settings(): Settings
    {
        $plugin = Plugins::getPlugin('ai-alt-text');

        if ($plugin instanceof self) {
            return $plugin->getSettings();
        }

        $settings = new Settings();
        $rawSettings = app(ProjectConfig::class)->get('plugins.ai-alt-text.settings') ?? [];

        if (!empty($rawSettings)) {
            $settings->setAttributes($rawSettings, false);
        }

        return $settings;
    }

    /**
     * @inheritdoc
     */
    protected static function createSettings(): Settings
    {
        return new Settings();
    }

    /**
     * The settings page, built with Craft's control panel forms.
     *
     * Only the chosen AI provider's fields are shown. The provider and Langdock Model Author fields
     * are reactive, so changing either posts the form back here, to be rebuilt for the new value.
     * A provider set from an environment variable shows its fields when the variable resolves to a
     * known provider, and every provider's fields when it doesn't. Hidden fields aren't posted, and
     * saving applies the posted values on top of the stored settings, so a hidden provider's
     * settings are kept.
     *
     * @inheritdoc
     */
    public function settingsForm(FormContext $context = new FormContext()): ?Form
    {
        $values = $context->values['settings'] ?? [];
        // An environment variable that isn't set parses to null
        $provider = (string) Env::parse($values['aiProvider'] ?? '');
        $author = (string) Env::parse($values['langdockModelAuthor'] ?? '');
        $isShown = fn(string $value, array $known, string $chosen) => !in_array($chosen, $known, true) || $chosen === $value;
        $providers = ['anthropic', 'openai', 'langdock', 'openrouter'];

        $envTriggers = SelectOptions::getEnvTextExpanderTriggers();
        $envOptions = SelectOptions::getEnvSuggestions();
        $envTip = t('Type `$` to choose an environment variable.');
        $envText = fn(string $path) => Text::make($path)->textExpanderTriggers($envTriggers);
        $envSelect = fn(string $path, array $options) => Combobox::make($path)->options([...$options, ...$envOptions])->showAllOnEmpty();

        return Form::make([
            Heading::make('instructions-heading', self::t('Instructions')),
            MarkdownContent::make('instructions', implode("\n", [
                self::t('[Bulk actions]({utilityUrl}) · [Plugin docs](https://github.com/heavymetalavo/craft-aialttext)', ['utilityUrl' => cp_url('utilities/ai-alt-text-bulk-actions')]),
                '',
                '1. ' . self::t('Check the plugin settings are suitable for your project (and your API key is added)'),
                '2. ' . self::t("Ensure each asset volume's field layout includes the native [Alternative Text field](https://craftcms.com/docs/5.x/reference/element-types/assets.html#alt-text), under **Settings → Assets → (volume) → Field Layout**"),
                '3. ' . self::t("While you're in the field layout, open the Alternative Text field's settings and check its **Translation Method**. That is what decides whether alt text can differ per site: sites that share alt text under it get one result between them. If you want every site to get alt text, also switch on **Save translated results for each site** below, otherwise generating only fills the site you ran it in and the sites that share its alt text"),
                '4. ' . self::t('Ensure your templates are updated to use the `alt` field, you could consider a fallback `asset.alt ?: asset.title` if that what was used before'),
                '5. ' . self::t('Then generate some AI Alt text from the [bulk actions utility]({utilityUrl}), the **Generate AI Alt Text** action for assets selected in the **Assets** manager, the action menu on a single asset\'s page, or by uploading a new asset (if the upload setting is enabled)', ['utilityUrl' => cp_url('utilities/ai-alt-text-bulk-actions')]),
                '6. ' . self::t('Alt text for a single asset, selected in the **Assets** manager or on its own page, is generated straight away. Multiple selected assets, the bulk actions utility, new uploads, and the other sites when **Save translated results for each site** is enabled are queued as jobs instead'),
            ])),

            Separator::make('settings-separator'),
            Heading::make('settings-heading', self::t('Settings')),

            Field::make(self::t('AI Provider'), $envSelect('aiProvider', [
                ['label' => 'Anthropic', 'value' => 'anthropic'],
                ['label' => 'OpenAI', 'value' => 'openai'],
                ['label' => 'Langdock', 'value' => 'langdock'],
                ['label' => 'OpenRouter', 'value' => 'openrouter'],
            ])->reactive())
                ->instructions(self::t("Choose which AI service to use for generating alt text. Only the chosen provider's settings are shown below."))
                ->tip($envTip),

            Group::make('provider-anthropic', [
                Field::make(self::t('Anthropic API Key'), $envText('anthropicApiKey')->placeholder('$ANTHROPIC_API_KEY'))
                    ->instructions(self::t('The API key from your [Anthropic Console account](https://console.anthropic.com/)'))
                    ->tip($envTip),
                Field::make(self::t('Anthropic Model'), $envText('anthropicModel')->placeholder('claude-haiku-5-5'))
                    ->instructions(self::t('The Anthropic model to use. [See available models list](https://docs.anthropic.com/en/docs/about-claude/models). E.g., `claude-haiku-5-5`.')),
                Field::make(self::t('Anthropic Image Detail Level'), $envSelect('anthropicImageDetailLevel', [
                    ['label' => 'Low (500px x 500px)', 'value' => 'low'],
                    ['label' => 'Medium (1000px x 1000px)', 'value' => 'medium'],
                    ['label' => 'High (1568px x 1568px)', 'value' => 'high'],
                ]))
                    ->instructions(self::t('Pre-scale the image to reduce [token cost and latency](https://platform.claude.com/docs/en/build-with-claude/vision#evaluate-image-size).')),
            ])
                ->label(self::t('Anthropic Settings'))
                ->hidden(!$isShown('anthropic', $providers, $provider))
                ->dependsOn('aiProvider'),

            Group::make('provider-openai', [
                Field::make(self::t('OpenAI API Key'), $envText('openAiApiKey')->placeholder('$OPENAI_API_KEY'))
                    ->instructions(self::t('The API key from your Open AI account'))
                    ->tip($envTip),
                Field::make(self::t('OpenAI Model'), $envText('openAiModel')->placeholder('gpt-6-luna'))
                    ->instructions(self::t('The OpenAI model used to generate the response. To find out which models are capable of vision OpenAI suggest to look [on the models page](https://platform.openai.com/docs/models) E.g., `gpt-6-luna`.')),
                Field::make(self::t('OpenAI Image Detail Level'), $envSelect('openAiImageInputDetailLevel', [
                    ['label' => 'Low - Fast, low-cost (512px x 512px)', 'value' => 'low'],
                    ['label' => 'High - Standard high-fidelity understanding', 'value' => 'high'],
                    ['label' => 'Original - Large, dense, spatially sensitive images (gpt-5.4+)', 'value' => 'original'],
                    ['label' => 'Auto - Let the model choose', 'value' => 'auto'],
                ]))
                    ->instructions(self::t('Controls the [detail level](https://developers.openai.com/api/docs/guides/images-vision#choose-an-image-detail-level) OpenAI uses to process the image. Large images are also pre-scaled to stay within API limits. `low` is fastest and cheapest.')),
                Field::make(self::t('OpenAI Reasoning Effort'), $envSelect('openAiReasoningEffort', self::REASONING_EFFORT_OPTIONS))
                    ->instructions(self::t('Controls the [reasoning effort](https://developers.openai.com/api/docs/guides/reasoning#reasoning-effort) value when using models that support reasoning like `o*` (e.g., `o1`) and `gpt-5*`. Lower effort favors speed and lower token usage. Which values work depends on the model: `gpt-5` models (like `gpt-5-nano` and `gpt-5-mini`) accept `minimal` but not `none` or `xhigh`, while newer models (like `gpt-6-luna`) accept `none` and `xhigh` but not `minimal`. `low` works on both.')),
            ])
                ->label(self::t('OpenAI Settings'))
                ->hidden(!$isShown('openai', $providers, $provider))
                ->dependsOn('aiProvider'),

            Group::make('provider-langdock', [
                Field::make(self::t('Langdock API Key'), $envText('langdockApiKey')->placeholder('$LANGDOCK_API_KEY'))
                    ->instructions(self::t('An API key from your [Langdock workspace](https://docs.langdock.com/en/developer/overview/api-introduction). One key works for both model authors.'))
                    ->tip($envTip),
                Field::make(self::t('Langdock Model Author'), $envSelect('langdockModelAuthor', [
                    ['label' => 'Anthropic (e.g. Claude)', 'value' => 'anthropic'],
                    ['label' => 'OpenAI (e.g. GPT)', 'value' => 'openai'],
                ])->reactive())
                    ->instructions(self::t('The author of the model below, so requests go to the matching Langdock API: `Anthropic` for models such as Claude, or `OpenAI` for models such as GPT. Only these two model authors are supported. If you choose an environment variable, it must resolve to `anthropic` or `openai`.')),
                Field::make(self::t('Langdock Model'), $envText('langdockModel'))
                    ->instructions(self::t('The model to use, as named in Langdock. It must have vision capabilities and come from the model author above. See how to list the models in your workspace for the [Anthropic](https://docs.langdock.com/en/developer/completion-api/anthropic) or [OpenAI](https://docs.langdock.com/en/developer/completion-api/openai-responses) API.')),
                Group::make('langdock-author-openai', [
                    Field::make(self::t('Langdock Image Detail Level'), $envSelect('langdockImageInputDetailLevel', [
                        ['label' => 'Low - Fast, low-cost (512px x 512px)', 'value' => 'low'],
                        ['label' => 'High - Standard high-fidelity understanding', 'value' => 'high'],
                        ['label' => 'Auto - Let the model choose (may use more tokens)', 'value' => 'auto'],
                    ]))
                        ->instructions(self::t('Controls the [detail level](https://developers.openai.com/api/docs/guides/images-vision#choose-an-image-detail-level) used to process the image. Large images are also pre-scaled to stay within API limits. `low` is fastest and cheapest.')),
                    Field::make(self::t('Langdock Reasoning Effort'), $envSelect('langdockReasoningEffort', self::REASONING_EFFORT_OPTIONS))
                        ->instructions(self::t('Controls the [reasoning effort](https://developers.openai.com/api/docs/guides/reasoning#reasoning-effort) value when using models that support reasoning like `o*` (e.g., `o1`) and `gpt-5*`. Lower effort favors speed and lower token usage. Which values work depends on the model: `gpt-5` models (like `gpt-5-nano` and `gpt-5-mini`) accept `minimal` but not `none` or `xhigh`, while newer models (like `gpt-6-luna`) accept `none` and `xhigh` but not `minimal`. `low` works on both.')),
                ])
                    ->hidden(!$isShown('openai', ['anthropic', 'openai'], $author))
                    ->dependsOn('langdockModelAuthor'),
            ])
                ->label(self::t('Langdock Settings'))
                ->hidden(!$isShown('langdock', $providers, $provider))
                ->dependsOn('aiProvider'),

            Group::make('provider-openrouter', [
                Field::make(self::t('OpenRouter API Key'), $envText('openRouterApiKey')->placeholder('$OPENROUTER_API_KEY'))
                    ->instructions(self::t('An API key from your [OpenRouter account](https://openrouter.ai/keys). The account needs credit.'))
                    ->tip($envTip),
                Field::make(self::t('OpenRouter Model'), $envText('openRouterModel')->placeholder('anthropic/claude-haiku-5.5'))
                    ->instructions(self::t("The model's name on OpenRouter, which starts with the model's author, e.g. `anthropic/claude-haiku-5.5` (our top pick) or `google/gemini-3.5-flash-lite`. It must accept image input: [browse the vision models](https://openrouter.ai/models?input_modalities=image&output_modalities=text).")),
                Field::make(self::t('OpenRouter Image Detail Level'), $envSelect('openRouterImageInputDetailLevel', [
                    ['label' => 'Low - Fast, low-cost (512px x 512px)', 'value' => 'low'],
                    ['label' => 'High - Standard high-fidelity understanding', 'value' => 'high'],
                    ['label' => 'Original - Large, dense, spatially sensitive images (gpt-5.4+)', 'value' => 'original'],
                    ['label' => 'Auto - Let the model choose (may use more tokens)', 'value' => 'auto'],
                ]))
                    ->instructions(self::t('Controls the [detail level](https://openrouter.ai/docs/guides/overview/multimodal/image-understanding) used to process the image. Large images are also pre-scaled to stay within API limits. `low` is fastest and cheapest. OpenAI and Gemini models use it, and in testing Qwen, GLM and Grok models ignored it. For Claude models it picks the size limit, as the Anthropic setting does.')),
                Field::make(self::t('OpenRouter Reasoning Effort'), $envSelect('openRouterReasoningEffort', self::REASONING_EFFORT_OPTIONS))
                    ->instructions(self::t('Controls the [reasoning effort](https://openrouter.ai/docs/api_reference/responses/reasoning) value when using OpenAI models that support reasoning like `o*` (e.g., `o1`) and `gpt-5*`. Lower effort favors speed and lower token usage. Which values work depends on the model: `gpt-5` models (like `gpt-5-nano`) accept `minimal` but not `none`, while newer models (like `gpt-6-luna`) accept `none` but, directly with OpenAI, not `minimal` (OpenRouter accepted it). `low` works on both. Other models are sent no reasoning setting.')),
            ])
                ->label(self::t('OpenRouter Settings'))
                ->hidden(!$isShown('openrouter', $providers, $provider))
                ->dependsOn('aiProvider'),

            Separator::make('generation-separator'),

            Field::make(self::t('Prompt'), Textarea::make('prompt')->rows(8)->placeholder((new Settings())->prompt))
                ->instructions(self::t('Sent to the AI provider as the system / instruction message. The field supports simple Asset Model and Site Model property values using `{asset.property}` and `{site.property}`. Consider `{site.languageName}` for the site\'s language display name (e.g. `English (United Kingdom)`) which can help with translations on non-english sites. Checkout [the docs](https://github.com/heavymetalavo/craft-aialttext/blob/6.x/docs/settings.md#-default-prompt) for the most up to date suggestion prompt template.')),
            Field::make(self::t('Generate for new image assets (on upload or file replacement)'), Lightswitch::make('generateForNewAssets'))
                ->instructions(self::t('If enabled, the plugin will automatically generate alt text when an image asset is created (on upload) or its file is replaced. On replacement, the alt text for the site the replacement was made in, and the sites that share it, is overwritten, since it describes the old image; other sites keep their existing alt text unless Save translated results for each site is enabled. Note that Craft fills alt text in from an image\'s embedded IPTC/XMP caption when one is present, and the generated text replaces it — leave this off if your photographers caption their images.')),
            Field::make(self::t('Process SVGs'), Lightswitch::make('processSvgs'))
                ->instructions(self::t('Attempt to generate alt text for SVG files when they are uploaded or batch processed.'))
                ->warning(Images::getIsImagick() ? null : self::t("Craft isn't using the Imagick image driver, which the plugin renders SVGs with, so SVGs are skipped.")),
            Field::make(self::t('Save translated results for each site'), Lightswitch::make('saveTranslatedResultsToEachSite'))
                ->instructions(self::t("Follows the asset volume's **Alternative Text Translation Method**: sites that share alt text (all sites on Not translatable, or sites in the same site group or language) get one result between them, from one request, in the language of the site it's generated for. When on, every site gets alt text. When off, only the current site and the sites that share its alt text do.")),
        ]);
    }
}
