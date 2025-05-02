<?php

namespace heavymetalavo\craftaialttext;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\DefineMenuItemsEvent;
use craft\web\View;
use craft\web\UrlManager;
use heavymetalavo\craftaialttext\elements\actions\GenerateAiAltText;
use heavymetalavo\craftaialttext\services\AiAltTextService;
use heavymetalavo\craftaialttext\models\Settings;
use yii\base\Event;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\Cp;

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
                $event->rules['ai-alt-text/generate-all-assets'] = 'ai-alt-text/generate/generate-all-assets';
                $event->rules['ai-alt-text/generate-assets-without-alt-text'] = 'ai-alt-text/generate/generate-assets-without-alt-text';
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
        // Get current site
        $currentSite = Craft::$app->getSites()->getCurrentSite();
        $sites = Craft::$app->getSites()->getAllSites();
        
        // Initialize totals
        $totalAssetsWithAltTextForAllSites = 0;
        $totalAssetsWithoutAltTextForAllSites = 0;
        $siteAltTextCounts = [];
        
        // Manually count assets for each site to avoid problematic database queries
        foreach ($sites as $site) {
            $siteAltTextCounts[$site->id] = [
                'total' => 0,
                'with' => 0,
                'without' => 0
            ];

            try {
                // Use Craft's asset service to get all assets rather than direct SQL
                $assets = Asset::find()
                    ->kind(Asset::KIND_IMAGE)
                    ->siteId($site->id)
                    ->status(null)  // Get all assets regardless of status
                    ->all();
                
                $imageAssetCount = count($assets);
                $withAltCount = 0;
                $withoutAltCount = 0;
                
                // For each asset, check if it has alt text
                foreach ($assets as $asset) {
                    // Check alt text
                    $altText = $asset->alt;
                    if ($altText !== null && trim($altText) !== '') {
                        $withAltCount++;
                    } else {
                        $withoutAltCount++;
                    }
                }
                
                // Store counts for this site
                $siteAltTextCounts[$site->id] = [
                    'total' => $imageAssetCount,
                    'with' => $withAltCount,
                    'without' => $withoutAltCount
                ];
                
                // Add to totals
                $totalAssetsWithAltTextForAllSites += $withAltCount;
                $totalAssetsWithoutAltTextForAllSites += $withoutAltCount;
                
                Craft::info("Site {$site->name}: Total: {$imageAssetCount}, With Alt: {$withAltCount}, Without Alt: {$withoutAltCount}", __METHOD__);
            } catch (\Exception $e) {
                Craft::error("Error counting assets for site {$site->name}: " . $e->getMessage(), __METHOD__);
            }
        }
        
        // For backward compatibility with the template
        $totalAssets = $siteAltTextCounts[$currentSite->id]['total'] ?? 0;
        $totalAssetsWithAltText = $siteAltTextCounts[$currentSite->id]['with'] ?? 0;
        $totalAssetsWithoutAltText = $siteAltTextCounts[$currentSite->id]['without'] ?? 0;
        
        return Craft::$app->view->renderTemplate(
            'ai-alt-text/_settings',
            [
                'plugin' => $this,
                'settings' => $this->getSettings(),
                'totalAssets' => $totalAssets,
                'totalAssetsWithAltText' => $totalAssetsWithAltText,
                'totalAssetsWithoutAltText' => $totalAssetsWithoutAltText,
                'totalAssetsWithAltTextForAllSites' => $totalAssetsWithAltTextForAllSites,
                'totalAssetsWithoutAltTextForAllSites' => $totalAssetsWithoutAltTextForAllSites,
                'currentSite' => $currentSite,
                'sites' => $sites,
                'siteAltTextCounts' => $siteAltTextCounts,
            ]
        );
    }
}
