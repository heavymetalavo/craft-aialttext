<?php

namespace heavymetalavo\craftaialttext\migrations;

use Craft;
use craft\db\Migration;
use heavymetalavo\craftaialttext\AiAltText;

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
        $plugin = AiAltText::getInstance();
        if ($plugin === null) {
            return true;
        }

        $settings = $plugin->getSettings();

        // If provider is not set, but an OpenAI key exists, default to openai
        if (empty($settings->aiProvider) && !empty($settings->openAiApiKey)) {
            Craft::info('Migrating AI provider to "openai" based on existing API key.', __METHOD__);
            Craft::$app->getPlugins()->savePluginSettings($plugin, [
                'aiProvider' => 'openai'
            ]);
        }

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
