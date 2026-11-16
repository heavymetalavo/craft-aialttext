<?php

namespace heavymetalavo\craftaialttext\listeners;

use CraftCms\Cms\Element\Events\ElementActionMenuDescriptorsResolving;
use heavymetalavo\craftaialttext\services\AiAltTextService;

/**
 * Adds "Generate AI Alt Text" to each asset's action menu in the Inertia editor, which builds its menu from
 * descriptors rather than the action menu items AddAssetActionMenuItem adds to.
 */
class AddAssetActionMenuDescriptor
{
    public function __construct(
        private AiAltTextService $service,
    ) {}

    public function handle(ElementActionMenuDescriptorsResolving $event): void
    {
        $this->service->handleAssetActionMenuDescriptors($event);
    }
}
