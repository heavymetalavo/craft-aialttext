<?php

namespace heavymetalavo\craftaialttext\migrations;

use Craft;
use craft\db\Migration;

/**
 * m261019_225710_rename_langdock_model_family_to_model_author migration.
 *
 * Renames the stored `langdockModelFamily` setting to `langdockModelAuthor`, keeping its value (`anthropic`,
 * `openai`, or an environment variable that resolves to one of them).
 */
class m261019_225710_rename_langdock_model_family_to_model_author extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        // If the incoming project config already has the renamed setting, it was
        // produced by an environment that ran this migration and arrives through
        // the normal project config apply — don't write it again. (Its schema
        // version can't tell, as reverting the migration leaves that at 1.2.0.)
        if ($projectConfig->get('plugins.ai-alt-text.settings.langdockModelAuthor', true) !== null) {
            return true;
        }

        return $this->renameSetting('langdockModelFamily', 'langdockModelAuthor');
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        return $this->renameSetting('langdockModelAuthor', 'langdockModelFamily');
    }

    /**
     * Renames a stored setting, keeping its value, if it is stored.
     */
    private function renameSetting(string $from, string $to): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $settings = $projectConfig->get('plugins.ai-alt-text.settings') ?? [];

        if (!array_key_exists($from, $settings)) {
            return true;
        }

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            Craft::warning("Skipping the rename of the $from setting: project config is read-only on this environment (allowAdminChanges is false). Run the update on an environment that allows admin changes and deploy the resulting project config.", __METHOD__);
            return true;
        }

        Craft::info("Renaming the $from setting to $to.", __METHOD__);

        // Write the whole settings node back with only that key renamed, so no
        // other stored settings are lost. (Plugins::savePluginSettings() would
        // replace the node with only the keys passed to it.)
        $settings[$to] = $settings[$from];
        unset($settings[$from]);
        $projectConfig->set('plugins.ai-alt-text.settings', $settings, "Rename the AI Alt Text $from setting to $to");

        return true;
    }
}
