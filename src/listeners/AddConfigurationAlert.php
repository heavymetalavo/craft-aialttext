<?php

namespace heavymetalavo\craftaialttext\listeners;

use CraftCms\Cms\Cp\Events\CpAlertsResolving;
use CraftCms\Cms\Support\Html;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\services\AiAltTextService;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;

/**
 * Warns admins in the control panel when the provider or its API key isn't configured.
 */
class AddConfigurationAlert
{
    public function __construct(
        private AiAltTextService $service,
    ) {}

    public function handle(CpAlertsResolving $event): void
    {
        if (!currentUser()?->isAdmin()) {
            return;
        }

        $error = $this->service->getConfigurationError();

        if ($error === null) {
            return;
        }

        $event->alerts[] = AiAltText::t('{error} {link}', [
            'error' => e($error),
            'link' => Html::a(AiAltText::t('Open the settings'), cp_url('settings/plugins/ai-alt-text')),
        ]);
    }
}
