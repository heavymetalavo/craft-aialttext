<?php

namespace heavymetalavo\craftaialttext\listeners;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Events\ElementActionsResolving;
use heavymetalavo\craftaialttext\elements\actions\GenerateAiAltText;

/**
 * Adds the bulk "Generate AI Alt Text" action to asset element indexes.
 */
class RegisterAssetElementActions
{
    public function handle(ElementActionsResolving $event): void
    {
        if ($event->elementType === Asset::class) {
            $event->actions[] = GenerateAiAltText::class;
        }
    }
}
