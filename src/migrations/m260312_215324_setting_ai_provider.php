<?php

use CraftCms\Cms\Cms;
use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Support\Facades\ProjectConfig;
use Illuminate\Support\Facades\Log;

/**
 * Defaults the AI provider to openai for installs that already had an OpenAI key.
 *
 * Returned as an anonymous class — see the note in the prompt migration for why a named
 * class in this namespace breaks under Craft 6's Laravel-based migrator.
 */
return new class extends Migration
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
};
