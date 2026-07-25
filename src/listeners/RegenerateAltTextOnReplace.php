<?php

namespace heavymetalavo\craftaialttext\listeners;

use CraftCms\Cms\Asset\Enums\FileKind;
use CraftCms\Cms\Asset\Events\AssetReplaced;
use CraftCms\Cms\Cp\RequestedSite;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\services\AiAltTextService;

/**
 * Regenerates alt text when an image asset's file is replaced, since the existing alt text
 * describes the old image.
 */
class RegenerateAltTextOnReplace
{
    public function __construct(
        private AiAltTextService $service,
    ) {}

    public function handle(AssetReplaced $event): void
    {
        $asset = $event->asset;

        if (
            $asset->kind === FileKind::Image->value
            && AiAltText::settings()->generateForNewAssets
        ) {
            // RequestedSite can resolve to null (e.g. no resolvable CP site context) —
            // createJob() falls back to the asset's own site in that case, so pass it
            // through rather than bailing out.
            $requestedSite = app(RequestedSite::class)->get();
            // Force regeneration so the stale alt text is overwritten
            $this->service->createJob($asset, false, $requestedSite?->id, false, true);
        }
    }
}
