<?php

namespace heavymetalavo\craftaialttext;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Enums\FileKind;
use CraftCms\Cms\Asset\Events\AssetReplaced;
use CraftCms\Cms\Cp\RequestedSite;
use CraftCms\Cms\Element\Events\{ElementActionsResolving, ElementActionMenuItemsResolving, ElementLifecycleSaved};
use CraftCms\Cms\Plugin\Plugin;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use CraftCms\Cms\Queue\JobProgress;
use CraftCms\Cms\Support\Facades\Plugins;
use heavymetalavo\craftaialttext\Commands\{GenerateAll, GenerateMissing, GenerateSingle, GenerateStats};
use heavymetalavo\craftaialttext\elements\actions\GenerateAiAltText;
use heavymetalavo\craftaialttext\jobs\GenerateAiAltText as GenerateAiAltTextJob;
use heavymetalavo\craftaialttext\models\Settings;
use heavymetalavo\craftaialttext\services\AiAltTextService;
use heavymetalavo\craftaialttext\utilities\AiAltTextUtility;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;

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
     * @inheritdoc
     */
    public function bootPlugin(): void
    {
        parent::bootPlugin();

        // Register element actions for Assets
        Event::listen(function (ElementActionsResolving $event): void {
            if ($event->elementType === Asset::class) {
                $event->actions[] = GenerateAiAltText::class;
            }
        });

        // Add "Generate AI Alt Text" item to each asset's action dropdown
        Event::listen(function (ElementActionMenuItemsResolving $event): void {
            app(AiAltTextService::class)->handleAssetActionMenuItems($event);
        });

        // Craft 6's StoreFailed listener marks failed jobs with description=null, clearing the
        // job name from the queue manager UI. This listener runs after StoreFailed (registered
        // later) and restores the description so failed jobs remain identifiable.
        Event::listen(function (JobFailed $event): void {
            $payload = $event->job->payload();
            $uuid = $payload['uuid'] ?? null;
            if (!$uuid) {
                return;
            }

            $commandData = $payload['data']['command'] ?? '';
            if (empty($commandData)) {
                return;
            }

            try {
                $job = unserialize($commandData);
            } catch (\Throwable) {
                return;
            }

            if (!$job instanceof GenerateAiAltTextJob) {
                return;
            }

            app(JobProgress::class)->failed(
                uid: $uuid,
                description: $job->getDescription(),
                error: $event->exception->getMessage(),
            );
        });

        // Auto-queue on new image upload when setting is enabled
        Event::listen(function (ElementLifecycleSaved $event): void {
            $asset = $event->element;

            if (!$asset instanceof Asset) {
                return;
            }

            if (
                $event->isNew
                && $asset->kind === FileKind::Image->value
                && self::settings()->generateForNewAssets
            ) {
                $requestedSite = app(RequestedSite::class)->get();
                app(AiAltTextService::class)->createJob($asset, false, $requestedSite?->id ?? $asset->siteId);
            }
        });

        // Regenerate alt text when an asset's file is replaced, since the previous
        // alt text describes the old image
        Event::listen(function (AssetReplaced $event): void {
            $asset = $event->asset;

            if (
                $asset->kind === FileKind::Image->value
                && self::settings()->generateForNewAssets
            ) {
                // RequestedSite can resolve to null (e.g. no resolvable CP site context) —
                // createJob() falls back to the asset's own site in that case, so pass it
                // through rather than bailing out.
                $requestedSite = app(RequestedSite::class)->get();
                // Force regeneration so the stale alt text is overwritten
                app(AiAltTextService::class)->createJob($asset, false, $requestedSite?->id, false, true);
            }
        });
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
