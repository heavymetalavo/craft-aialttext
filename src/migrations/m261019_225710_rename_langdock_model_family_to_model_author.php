<?php

use CraftCms\Cms\Cms;
use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Support\Facades\ProjectConfig;
use Illuminate\Support\Facades\Log;

/**
 * Renames the stored `langdockModelFamily` setting to `langdockModelAuthor`, keeping its value (`anthropic`,
 * `openai`, or an environment variable that resolves to one of them).
 *
 * Returned as an anonymous class, which is the Laravel migration convention Craft 6 inherits. A named class in
 * this namespace would be PSR-4 autoloadable *and* then `require`d again by the migrator, which fails with
 * "Cannot redeclare class".
 */
return new class extends Migration
{
    /**
     * @inheritdoc
     */
    public function up(): void
    {
        // If the incoming project config already has the renamed setting, it was
        // produced by an environment that ran this migration and arrives through
        // the normal project config apply — don't write it again. (Its schema
        // version can't tell, as reverting the migration leaves that at 1.2.0.)
        if (ProjectConfig::get('plugins.ai-alt-text.settings.langdockModelAuthor', true) !== null) {
            return;
        }

        $this->renameSetting('langdockModelFamily', 'langdockModelAuthor');
    }

    /**
     * @inheritdoc
     */
    public function down(): void
    {
        $this->renameSetting('langdockModelAuthor', 'langdockModelFamily');
    }

    /**
     * Renames a stored setting, keeping its value, if it is stored.
     */
    private function renameSetting(string $from, string $to): void
    {
        $settings = ProjectConfig::get('plugins.ai-alt-text.settings') ?? [];

        if (!array_key_exists($from, $settings)) {
            return;
        }

        if (!Cms::config()->allowAdminChanges) {
            Log::warning("Skipping the rename of the $from setting: project config is read-only on this environment (allowAdminChanges is false). Run the update on an environment that allows admin changes and deploy the resulting project config.");
            return;
        }

        Log::info("Renaming the $from setting to $to.");

        // Write the whole settings node back with only that key renamed, so no
        // other stored settings are lost. (Saving plugin settings with a partial
        // array would replace the node with only the keys passed to it.)
        $settings[$to] = $settings[$from];
        unset($settings[$from]);
        ProjectConfig::set('plugins.ai-alt-text.settings', $settings, "Rename the AI Alt Text $from setting to $to");
    }
};
