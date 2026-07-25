<?php

namespace heavymetalavo\craftaialttext\migrations;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Support\Facades\ProjectConfig;
use Illuminate\Support\Facades\Log;

/**
 * m260312_215324_setting_ai_provider migration.
 */
class m260312_215324_setting_ai_provider extends Migration
{
    /**
     * @inheritdoc
     */
    public function up(): void
    {
        // If the incoming project config was produced by an environment that
        // already ran this migration, the new value arrives through the normal
        // project config apply — don't write it again.
        $schemaVersion = ProjectConfig::get('plugins.ai-alt-text.schemaVersion', true);
        if ($schemaVersion !== null && version_compare($schemaVersion, '1.1.0', '>=')) {
            return;
        }

        $settings = ProjectConfig::get('plugins.ai-alt-text.settings') ?? [];

        // If provider is not set, but an OpenAI key exists, default to openai
        if (!empty($settings['aiProvider'] ?? null) || empty($settings['openAiApiKey'] ?? null)) {
            return;
        }

        if (!Cms::config()->allowAdminChanges) {
            Log::warning('Skipping AI provider migration: project config is read-only on this environment (allowAdminChanges is false). Run the update on an environment that allows admin changes and deploy the resulting project config.');
            return;
        }

        Log::info('Migrating AI provider to "openai" based on existing API key.');

        // Write the whole settings node back with only `aiProvider` changed, so
        // no other stored settings are lost. (Saving plugin settings with a partial
        // array would replace the node with only the keys passed to it.)
        $settings['aiProvider'] = 'openai';
        ProjectConfig::set('plugins.ai-alt-text.settings', $settings, 'Set AI Alt Text provider to openai for existing OpenAI installs');
    }

    /**
     * @inheritdoc
     */
    public function down(): void
    {
        // Cannot be reverted
    }
}
