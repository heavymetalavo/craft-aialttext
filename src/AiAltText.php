<?php

namespace heavymetalavo\craftaialttext;

use Craft;
use craft\base\{Element, Plugin};
use craft\elements\Asset;
use craft\events\{ModelEvent, RegisterElementActionsEvent, DefineMenuItemsEvent, RegisterComponentTypesEvent, RegisterCpAlertsEvent, RegisterUrlRulesEvent, ReplaceAssetEvent};
use craft\helpers\{App, Cp, Html, UrlHelper};
use craft\services\{Assets, ProjectConfig, Utilities};
use craft\web\{View, UrlManager};
use heavymetalavo\craftaialttext\elements\actions\GenerateAiAltText;
use heavymetalavo\craftaialttext\models\Settings;
use heavymetalavo\craftaialttext\services\{AiAltTextService, OpenAiService, AnthropicService, GoogleService, LangdockAnthropicService, LangdockOpenAiService, OpenRouterService, HuggingFaceService, CustomResponsesService, CustomMessagesService};
use heavymetalavo\craftaialttext\utilities\AiAltTextUtility;
use yii\base\Event;

/**
 * AI Alt Text Plugin
 *
 * A Craft CMS plugin that generates alt text for images using AI vision models.
 *
 * @property AiAltTextService $aiAltTextService The service for generating alt text
 * @property OpenAiService $openAiService
 * @property AnthropicService $anthropicService
 * @property GoogleService $googleService
 * @property LangdockAnthropicService $langdockAnthropicService
 * @property LangdockOpenAiService $langdockOpenAiService
 * @property OpenRouterService $openRouterService
 * @property HuggingFaceService $huggingFaceService
 * @property CustomResponsesService $customResponsesService
 * @property CustomMessagesService $customMessagesService
 * @property Settings $settings The plugin settings
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

    public static function config(): array
    {
        return [
            'components' => [
                'aiAltTextService' => AiAltTextService::class,
                'openAiService' => OpenAiService::class,
                'anthropicService' => AnthropicService::class,
                'googleService' => GoogleService::class,
                'langdockAnthropicService' => LangdockAnthropicService::class,
                'langdockOpenAiService' => LangdockOpenAiService::class,
                'openRouterService' => OpenRouterService::class,
                'huggingFaceService' => HuggingFaceService::class,
                'customResponsesService' => CustomResponsesService::class,
                'customMessagesService' => CustomMessagesService::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'aiAltTextService' => AiAltTextService::class,
            'openAiService' => OpenAiService::class,
            'anthropicService' => AnthropicService::class,
            'googleService' => GoogleService::class,
            'langdockAnthropicService' => LangdockAnthropicService::class,
            'langdockOpenAiService' => LangdockOpenAiService::class,
            'openRouterService' => OpenRouterService::class,
            'huggingFaceService' => HuggingFaceService::class,
            'customResponsesService' => CustomResponsesService::class,
            'customMessagesService' => CustomMessagesService::class,
        ]);

        // Register template path
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function($event) {
                $event->roots[$this->id] = $this->getBasePath() . '/templates';
            }
        );

        // Register controller routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['ai-alt-text/generate/single-asset'] = 'ai-alt-text/generate/single-asset';
                $event->rules['ai-alt-text/generate-all-assets'] = 'ai-alt-text/generate/generate-all-assets';
                $event->rules['ai-alt-text/generate-assets-without-alt-text'] = 'ai-alt-text/generate/generate-assets-without-alt-text';
            }
        );

        $this->attachEventHandlers();
    }

    private function attachEventHandlers(): void
    {
        // Register event handlers here ...
        // (see https://craftcms.com/docs/5.x/extend/events.html to get started)
        Event::on(
            Asset::class,
            Asset::EVENT_REGISTER_ACTIONS,
            function(RegisterElementActionsEvent $event) {
                $event->actions[] = GenerateAiAltText::class;
            }
        );

        // Add custom menu item to asset action dropdown
        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_ACTION_MENU_ITEMS,
            function(DefineMenuItemsEvent $event) {
                $this->aiAltTextService->handleAssetActionMenuItems($event);
            }
        );

        // Listen for asset creation/save events
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Asset $element */
                $asset = $event->sender;

                // Only process new assets that are images and if the setting is enabled
                if (
                    $event->isNew
                    && $asset->kind === Asset::KIND_IMAGE
                    && $this->getSettings()->generateForNewAssets
                ) {
                    // Save current site ID. Cp::requestedSite() returns null when the asset is
                    // saved via a console command / a queue job, which used to fail the save.
                    // createJob() falls back to the asset's own site, so pass the null through.
                    $currentSite = Cp::requestedSite();
                    // Pass current site ID to create a job
                    $this->aiAltTextService->createJob($asset, false, $currentSite?->id);
                }
            }
        );

        // Regenerate alt text when an asset's file is replaced, since the previous
        // alt text describes the old image
        Event::on(
            Assets::class,
            Assets::EVENT_AFTER_REPLACE_ASSET,
            function(ReplaceAssetEvent $event) {
                $asset = $event->asset;

                if (
                    $asset->kind === Asset::KIND_IMAGE
                    && $this->getSettings()->generateForNewAssets
                ) {
                    // Save current site ID. Cp::requestedSite() can return null (e.g. no
                    // resolvable CP site context) — createJob() falls back to the asset's
                    // own site in that case, so pass it through rather than bailing out.
                    $currentSite = Cp::requestedSite();
                    // generateAltText() always overwrites the site's alt, so the stale alt text is replaced
                    $this->aiAltTextService->createJob($asset, false, $currentSite?->id);
                }
            }
        );

        // Register Utility
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = AiAltTextUtility::class;
            }
        );

        // Warn admins when the provider or its API key isn't configured.
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_ALERTS,
            function(RegisterCpAlertsEvent $event) {
                if (!Craft::$app->getUser()->getIsAdmin()) {
                    return;
                }

                $error = $this->aiAltTextService->getConfigurationError();

                if ($error === null) {
                    return;
                }

                $event->alerts[] = Craft::t('ai-alt-text', '{error} {link}', [
                    'error' => Html::encode($error),
                    'link' => Html::a(
                        Craft::t('ai-alt-text', 'Open the settings'),
                        UrlHelper::cpUrl('settings/plugins/ai-alt-text')
                    ),
                ]);
            }
        );
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    public function beforeSaveSettings(): bool
    {
        // The settings page shows config/ai-alt-text.php's values for the settings it sets, so the form posts them and
        // Craft would save them to project config. A plain value, e.g. what App::env() returns, would then reach
        // other environments, so keep what was saved instead, or the default if nothing was. An environment variable
        // reference, e.g. '$AI_PROVIDER', is safe to save as it resolves per environment.
        $settings = $this->getSettings();
        $saved = Craft::$app->getProjectConfig()->get(ProjectConfig::PATH_PLUGINS . '.' . $this->handle . '.settings') ?? [];
        $defaults = new Settings();

        foreach ($this->configFileSettings() as $name => $value) {
            if (is_string($value) && str_starts_with($value, '$')) {
                continue;
            }

            if ($settings->hasProperty($name)) {
                $settings->$name = $saved[$name] ?? $defaults->$name;
            }
        }

        return parent::beforeSaveSettings();
    }

    /**
     * The settings config/ai-alt-text.php sets, which win over the ones saved on the settings page.
     */
    private function configFileSettings(): array
    {
        $config = Craft::$app->getConfig()->getConfigFromFile($this->handle);

        // The old name of the Langdock Model Author, still read, see Settings::setAttributes()
        if (isset($config['langdockModelFamily'])) {
            $config['langdockModelAuthor'] = $config['langdockModelFamily'];
        }

        return $config;
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate(
            'ai-alt-text/_settings',
            [
                'settings' => $this->getSettings(),
                // So their fields can say the file's values win
                'config' => $this->configFileSettings(),
                // Only fetched for the saved provider, so other sites' settings pages don't call them. Choosing the other one fetches its suggestions then
                'openRouterModelSuggestions' => App::parseEnv($this->getSettings()->aiProvider) === 'openrouter'
                    ? $this->openRouterService->visionModelSuggestions()
                    : [],
                'huggingFaceModelSuggestions' => App::parseEnv($this->getSettings()->aiProvider) === 'huggingface'
                    ? $this->huggingFaceService->visionModelSuggestions()
                    : [],
            ]
        );
    }
}
