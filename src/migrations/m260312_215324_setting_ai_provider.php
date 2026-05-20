<?php

namespace heavymetalavo\craftaialttext\migrations;

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Support\Facades\Plugins;
use heavymetalavo\craftaialttext\AiAltText;
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
        $plugin = app(AiAltText::class);

        $settings = $plugin->getSettings();

        // If provider is not set, but an OpenAI key exists, default to openai
        if (empty($settings->aiProvider) && !empty($settings->openAiApiKey)) {
            Log::info('Migrating AI provider to "openai" based on existing API key.');
            Plugins::savePluginSettings($plugin, [
                'aiProvider' => 'openai',
            ]);
        }
    }

    /**
     * @inheritdoc
     */
    public function down(): void
    {
        // Cannot be reverted
    }
}
