<?php

namespace heavymetalavo\craftaialttext\listeners;

use CraftCms\Cms\Element\Events\ElementActionMenuItemsResolving;
use heavymetalavo\craftaialttext\services\AiAltTextService;

/**
 * Adds "Generate AI Alt Text" to each asset's action dropdown.
 */
class AddAssetActionMenuItem
{
    public function __construct(
        private AiAltTextService $service,
    ) {}

    public function handle(ElementActionMenuItemsResolving $event): void
    {
        $this->service->handleAssetActionMenuItems($event);
    }
}
