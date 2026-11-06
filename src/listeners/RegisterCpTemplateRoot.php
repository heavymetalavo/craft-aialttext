<?php

namespace heavymetalavo\craftaialttext\listeners;

use CraftCms\Cms\View\Events\CpTemplateRootsResolving;

/**
 * Registers `src/templates` as the control panel template root for this plugin, so
 * `ai-alt-text/_settings` and `ai-alt-text/_utility` resolve.
 *
 * Craft's own HasViews concern is meant to do this, but its listener calls
 * `self::getInstance()` from inside a trait — `self` binds to the abstract
 * CraftCms\Cms\Plugin\Plugin rather than the concrete plugin, so the container is asked
 * for an abstract class and no root ever gets registered. Declaring our own listener
 * sidesteps that; it is harmless if the concern starts working, since it would register
 * the same directory under the same handle.
 */
class RegisterCpTemplateRoot
{
    public function handle(CpTemplateRootsResolving $event): void
    {
        $event->roots['ai-alt-text'] = dirname(__DIR__) . '/templates';
    }
}
