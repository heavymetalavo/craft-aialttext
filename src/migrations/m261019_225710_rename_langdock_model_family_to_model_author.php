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

        // If the incoming project config was produced by an environment that
        // already ran this migration, the renamed setting arrives through the
        // normal project config apply — don't write it again.
        $schemaVersion = $projectConfig->get('plugins.ai-alt-text.schemaVersion', true);
        if ($schemaVersion !== null && version_compare($schemaVersion, '1.2.0', '>=')) {
            return true;
        }

        $settings = $projectConfig->get('plugins.ai-alt-text.settings') ?? [];

        if (!array_key_exists('langdockModelFamily', $settings)) {
            return true;
        }

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            Craft::warning('Skipping Langdock Model Author migration: project config is read-only on this environment (allowAdminChanges is false). Run the update on an environment that allows admin changes and deploy the resulting project config.', __METHOD__);
            return true;
        }

        Craft::info('Renaming the langdockModelFamily setting to langdockModelAuthor.', __METHOD__);

        // Write the whole settings node back with only that key renamed, so no
        // other stored settings are lost. (Plugins::savePluginSettings() would
        // replace the node with only the keys passed to it.)
        $settings['langdockModelAuthor'] = $settings['langdockModelFamily'];
        unset($settings['langdockModelFamily']);
        $projectConfig->set('plugins.ai-alt-text.settings', $settings, 'Rename the AI Alt Text Langdock Model Family setting to Langdock Model Author');

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m261019_225710_rename_langdock_model_family_to_model_author cannot be reverted.\n";
        return false;
    }
}
