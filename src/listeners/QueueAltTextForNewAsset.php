<?php

namespace heavymetalavo\craftaialttext\listeners;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Enums\FileKind;
use CraftCms\Cms\Cp\RequestedSite;
use CraftCms\Cms\Element\Events\ElementLifecycleSaved;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\services\AiAltTextService;

/**
 * Queues alt text generation when a new image asset is uploaded, if the setting is enabled.
 */
class QueueAltTextForNewAsset
{
    public function __construct(
        private AiAltTextService $service,
    ) {}

    public function handle(ElementLifecycleSaved $event): void
    {
        $asset = $event->element;

        if (!$asset instanceof Asset) {
            return;
        }

        if (
            $event->isNew
            && $asset->kind === FileKind::Image->value
            && AiAltText::settings()->generateForNewAssets
        ) {
            $requestedSite = app(RequestedSite::class)->get();
            $this->service->createJob($asset, false, $requestedSite?->id ?? $asset->siteId);
        }
    }
}
