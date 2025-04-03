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
use craft\events\DefineMenuItemsEvent;
use craft\helpers\Assets;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\Template;
use craft\web\View;
use craft\web\UrlManager;
use craft\enums\MenuItemType;
use heavymetalavo\craftaialttext\elements\actions\GenerateAiAltText;
use heavymetalavo\craftaialttext\services\AiAltTextService;
use heavymetalavo\craftaialttext\models\Settings;
use yii\base\Event;
use heavymetalavo\craftaialttext\services\OpenAiService;
use craft\events\RegisterUrlRulesEvent;

/**
 * AI Alt Text Plugin
 *
 * A Craft CMS plugin that generates alt text for images using OpenAI's vision models.
 * This plugin provides functionality to automatically generate descriptive alt text
 * for images in the Craft CMS asset library.
 *
 * @property AiAltTextService $aiAltTextService The service for generating alt text
 * @property Settings $settings The plugin settings
 */
class AiAltText extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => ['aiAltTextService' => AiAltTextService::class],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        // Register the service
        $this->setComponents([
            'aiAltTextService' => AiAltTextService::class,
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
            }
        );

        $this->attachEventHandlers();

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

        // Add custom menu item to asset action dropdown
        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_ACTION_MENU_ITEMS,
            function(DefineMenuItemsEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;
                $view = Craft::$app->getView();

                // Check if this is an image asset
                if ($asset->kind === 'image') {
                    // Add the "Generate AI Alt Text" action to the dropdown
                    $customActionId = sprintf('action-generate-ai-alt-%s', mt_rand());
                    $event->items[] = [
                        'type' => MenuItemType::Button,
                        'id' => $customActionId,
                        'icon' => 'language', // Use a relevant icon
                        'label' => Craft::t('ai-alt-text', 'Generate AI Alt Text'),
                    ];

                    // Register the JavaScript for the action
                    $view->registerJsWithVars(fn($id, $assetId, $siteId) => <<<JS
$('#' + $id).on('activate', () => {
  // Show a loading spinner in the UI
  Craft.cp.displayNotice(Craft.t('ai-alt-text', 'Queueing AI alt text generation...'));
  
  // Make an AJAX request to your controller action
  Craft.sendActionRequest('POST', 'ai-alt-text/generate/single-asset', {
    data: {
      assetId: $assetId,
      siteId: $siteId,
    }
  })
  .then((response) => {
    if (response.data.success) {
        Craft.cp.displayNotice(Craft.t('ai-alt-text', response.data.message));
      
      // Refresh the element editor if it's open
      if (Craft.elementEditor && Craft.elementEditor.assetId == $assetId) {
        Craft.elementEditor.reloadForm();
      }
      
      // Refresh the elements in the current view if possible
      if (Craft.cp.elementIndex) {
        Craft.cp.elementIndex.updateElements();
      } 
      return;
    }
    throw new Error(response.data.message);
  })
  .catch((error) => {
    console.log('catch', JSON.stringify(error));
    Craft.cp.displayError(Craft.t('ai-alt-text', 'Failed to queue alt text generation: ') + 
      (error?.message || 'Unknown error'));
  });
});
JS, [
                        $view->namespaceInputId($customActionId),
                        $asset->id,
                        $asset->siteId,
                    ]);
                }
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
                'plugin' => $this,
                'settings' => $this->getSettings(),
            ]
        );
    }
}
