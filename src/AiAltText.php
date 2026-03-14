<?php

namespace heavymetalavo\craftaialttext;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\{ModelEvent, RegisterElementActionsEvent, DefineMenuItemsEvent, RegisterComponentTypesEvent, RegisterUrlRulesEvent};
use craft\helpers\Cp;
use craft\services\Utilities;
use craft\web\{View, UrlManager};
use heavymetalavo\craftaialttext\elements\actions\GenerateAiAltText;
use heavymetalavo\craftaialttext\models\Settings;
use heavymetalavo\craftaialttext\services\{AiAltTextService, OpenAiService, AnthropicService};
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
 * @property Settings $settings The plugin settings
 */
class AiAltText extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'aiAltTextService' => AiAltTextService::class,
                'openAiService' => OpenAiService::class,
                'anthropicService' => AnthropicService::class,
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
                    // Save current site ID
                    $currentSite = Cp::requestedSite();
                    // Pass current site ID to create a job
                    AiAltText::getInstance()->aiAltTextService->createJob($asset, false, $currentSite->id);
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
    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate(
            'ai-alt-text/_settings',
            [
                'settings' => $this->getSettings(),
            ]
        );
    }
}
