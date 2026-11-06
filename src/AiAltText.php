<?php

namespace heavymetalavo\craftaialttext;

use CraftCms\Cms\Asset\Events\AssetReplaced;
use CraftCms\Cms\Element\Events\{ElementActionsResolving, ElementActionMenuItemsResolving, ElementLifecycleSaved};
use CraftCms\Cms\Plugin\Plugin;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use CraftCms\Cms\Support\Facades\Plugins;
use heavymetalavo\craftaialttext\Commands\{GenerateAll, GenerateMissing, GenerateSingle, GenerateStats};
use CraftCms\Cms\View\Events\CpTemplateRootsResolving;
use heavymetalavo\craftaialttext\listeners\{AddAssetActionMenuItem, QueueAltTextForNewAsset, RegenerateAltTextOnReplace, RegisterAssetElementActions, RegisterCpTemplateRoot, RestoreFailedJobDescription};
use heavymetalavo\craftaialttext\models\Settings;
use heavymetalavo\craftaialttext\utilities\AiAltTextUtility;
use Illuminate\Queue\Events\JobFailed;

use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * AI Alt Text Plugin
 *
 * A Craft CMS plugin that generates alt text for images using AI vision models.
 */
class AiAltText extends Plugin
{
    public string $schemaVersion = '1.1.0';
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
        CpTemplateRootsResolving::class => RegisterCpTemplateRoot::class,
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
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return template('ai-alt-text/_settings', [
            'settings' => $this->getSettings(),
        ]);
    }
}
