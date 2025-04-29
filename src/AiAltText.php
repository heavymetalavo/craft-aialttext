<?php

namespace heavymetalavo\craftaialttext;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\ElementEvent;
use craft\events\ModelEvent;
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
use heavymetalavo\craftaialttext\jobs\GenerateAiAltText as GenerateAiAltTextJob;
use heavymetalavo\craftaialttext\services\AiAltTextService;
use heavymetalavo\craftaialttext\models\Settings;
use yii\base\Event;
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
                $this->aiAltTextService->handleAssetActionMenuItems($event);
            }
        );
        
        // Listen for asset creation/save events
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Asset $element */
                $element = $event->sender;
                
                // Only process new assets that are images and if the setting is enabled
                if (
                    $event->isNew 
                    && $element->kind === Asset::KIND_IMAGE
                    && $this->getSettings()->generateForNewAssets
                ) {
                    $elementsService = Craft::$app->getElements();
                    $queue = Craft::$app->getQueue();
                    // Check if there's already a job for this element
                    $existingJobs = $queue->getJobInfo();
                    $hasExistingJob = false;
                    foreach ($existingJobs as $job) {
                        if (isset($job['description']) && strpos($job['description'], "Element: {$element->id}") !== false) {
                            $hasExistingJob = true;
                            break;
                        }
                    }

                    if ($hasExistingJob) {
                        Craft::$app->getSession()->setNotice(Craft::t('ai-alt-text', "{$element->filename} (ID: {$element->id}) is already being processed within an existing queued job. Please wait for the existing job to finish before attempting to process it again."));
                        return;
                    }

                    if ($element->kind !== Asset::KIND_IMAGE) {
                        Craft::$app->getSession()->setNotice(Craft::t('ai-alt-text', "{$element->filename} (ID: {$element->id}) is not an image"));
                        return;
                    }

                    $saveTranslatedResultsToEachSite = AiAltText::getInstance()->settings->saveTranslatedResultsToEachSite;

                    // If we're saving results to each site and translated results for each site, we need to queue a job for each site
                    if ($saveTranslatedResultsToEachSite) {
                        foreach (Craft::$app->getSites()->getAllSites() as $site) {

                            $queue->push(new GenerateAiAltTextJob([
                                'description' => Craft::t('ai-alt-text', 'Generating alt text for {filename} (Element: {id}, Site: {siteId})', [
                                    'filename' => $element->filename,
                                    'id' => $element->id,
                                    'siteId' => $site->id,
                                ]),
                                'elementId' => $element->id,
                                'siteId' => $site->id,
                            ]));
                        }
                    }
                    
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
