<?php

namespace heavymetalavo\craftaialttext\migrations;

use Craft;
use craft\db\Migration;

/**
 * m260312_215324_setting_ai_provider migration.
 */
class m260312_215324_setting_ai_provider extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        // If the incoming project config was produced by an environment that
        // already ran this migration, the new value arrives through the normal
        // project config apply — don't write it again.
        $schemaVersion = $projectConfig->get('plugins.ai-alt-text.schemaVersion', true);
        if ($schemaVersion !== null && version_compare($schemaVersion, '1.1.0', '>=')) {
            return true;
        }

        $settings = $projectConfig->get('plugins.ai-alt-text.settings') ?? [];

        // If provider is not set, but an OpenAI key exists, default to openai
        if (!empty($settings['aiProvider'] ?? null) || empty($settings['openAiApiKey'] ?? null)) {
            return true;
        }

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            Craft::warning('Skipping AI provider migration: project config is read-only on this environment (allowAdminChanges is false). Run the update on an environment that allows admin changes and deploy the resulting project config.', __METHOD__);
            return true;
        }

        Craft::info('Migrating AI provider to "openai" based on existing API key.', __METHOD__);

        // Write the whole settings node back with only `aiProvider` changed, so
        // no other stored settings are lost. (Plugins::savePluginSettings()
        // would replace the node with only the keys passed to it.)
        $settings['aiProvider'] = 'openai';
        $projectConfig->set('plugins.ai-alt-text.settings', $settings, 'Set AI Alt Text provider to openai for existing OpenAI installs');

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260312_215324_setting_ai_provider cannot be reverted.\n";
        return false;
    }
}
