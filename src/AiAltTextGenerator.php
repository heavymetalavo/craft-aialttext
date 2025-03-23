<?php

namespace heavymetalavo\craftaialttext;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterElementDefaultTableAttributesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\helpers\Assets;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\Template;
use craft\web\View;
use craft\web\UrlManager;
use heavymetalavo\craftaialttext\elements\actions\GenerateAiAltText;
use heavymetalavo\craftaialttext\services\AiAltTextGeneratorService;
use heavymetalavo\craftaialttext\models\Settings;
use yii\base\Event;
use heavymetalavo\craftaialttext\services\OpenAiService;
use craft\events\RegisterUrlRulesEvent;

/**
 * AI Alt Text Generator Plugin
 * 
 * A Craft CMS plugin that generates alt text for images using OpenAI's vision models.
 * This plugin provides functionality to automatically generate descriptive alt text
 * for images in the Craft CMS asset library.
 * 
 * @property AiAltTextGeneratorService $aiAltTextGeneratorService The service for generating alt text
 * @property Settings $settings The plugin settings
 */
class AiAltTextGenerator extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => ['aiAltTextGeneratorService' => AiAltTextGeneratorService::class],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        // Register our services
        $this->setComponents([
            'aiAltTextGenerator' => AiAltTextGeneratorService::class,
            'openAi' => OpenAiService::class,
        ]);

        $this->attachEventHandlers();

        // Register the controller
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['ai-alt-text/generate'] = 'ai-alt-text/generate-ai-alt-text/generate';
                $event->rules['ai-alt-text/generate-multiple'] = 'ai-alt-text/generate-ai-alt-text/generate-multiple';
            }
        );

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function() {
            // ...
        });
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
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('ai-alt-text-generator/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }
}
