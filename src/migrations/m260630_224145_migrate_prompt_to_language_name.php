<?php

use CraftCms\Cms\Cms;
use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Support\Facades\ProjectConfig;
use Illuminate\Support\Facades\Log;

/**
 * Upgrades the stored `prompt` setting to the new default that uses the
 * `{site.languageName}` token, but ONLY when the stored value verbatim-matches
 * one of the default prompts shipped by a previous release. A verbatim match
 * means the user never customised the prompt, so upgrading them to the current
 * default is safe; any customised prompt is left untouched.
 *
 * Returned as an anonymous class, which is the Laravel migration convention Craft 6
 * inherits. A named class in this namespace would be PSR-4 autoloadable *and* then
 * `require`d again by the migrator, which fails with "Cannot redeclare class"; the
 * migrator also derives an expected class name from the filename that would never
 * match a hand-written one.
 */
return new class extends Migration
{
    /**
     * Every default prompt shipped by a previous release, oldest first.
     * Frozen here on purpose — do not reference the live Settings default,
     * which may change again in a future release.
     */
    private const OLD_DEFAULT_PROMPTS = [
        // 2025-03-23 (initial release)
        'Please provide a detailed description of this image that would be suitable as alt text. Focus on the visual elements, context, and purpose of the image.',
        // 2025-03-23
        'Generate a brief (roughly 150 characters maximum) alt text description focusing on the main subject and overall composition. Do not add a prefix of any kind (e.g. alt text: AI content) so the value is suitable for the alt text attribute value of the image.',
        // 2025-03-31 (added {site.language})
        'Generate a brief (roughly 150 characters maximum) alt text description focusing on the main subject and overall composition. Do not add a prefix of any kind (e.g. alt text: AI content) so the value is suitable for the alt text attribute value of the image. Output in {site.language}',
        // 2025-06-27 (rewrite; also reintroduced 2026-03-04 without the gender clause)
        'Describe the image provided, make it suitable for an alt text description (roughly 150 characters maximum). Consider transparency within the image if supported by the file type, e.g. don\'t suggest it has a dark background if it is transparent. Do not add a prefix of any kind (e.g. alt text: AI content) so the value is suitable for the alt text attribute value of the image. Output in {site.language}',
        // 2025-08-19 (added the gender clause; reinstated 2026-03-14)
        'Describe the image provided, make it suitable for an alt text description (roughly 150 characters maximum). Consider transparency within the image if supported by the file type, e.g. don\'t suggest it has a dark background if it is transparent. Do not add a prefix of any kind (e.g. alt text: AI content) so the value is suitable for the alt text attribute value of the image. When describing a person do not assume their gender. Output in {site.language}',
        // 2026-03-19 (immediately-previous default)
        'Describe the image provided (roughly 150 characters). The output MUST be suitable for use directly as an HTML alt attribute value. Consider transparency within the image if supported by the file type, e.g. don\'t suggest it has a dark background if it is transparent. When describing a person do not assume their gender. Do not add a prefix of any kind (e.g. "#", "alt text:", "An image of", "A photo of"). Do not wrap the output in quotes. Output in the language: {site.language}',
    ];

    /**
     * The new default introduced by this release.
     */
    private const NEW_DEFAULT_PROMPT = 'Describe the image provided (roughly 150 characters). The output MUST be suitable for use directly as an HTML alt attribute value. Consider transparency within the image if supported by the file type, e.g. don\'t suggest it has a dark background if it is transparent. When describing a person do not assume their gender. Do not add a prefix of any kind (e.g. "#", "alt text:", "An image of", "A photo of"). Do not wrap the output in quotes. Output in the language: {site.languageName} (BCP 47: {site.language})';

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

        if (!in_array($settings['prompt'] ?? null, self::OLD_DEFAULT_PROMPTS, true)) {
            return;
        }

        if (!Cms::config()->allowAdminChanges) {
            Log::warning('Skipping prompt migration: project config is read-only on this environment (allowAdminChanges is false). Run the update on an environment that allows admin changes and deploy the resulting project config.');
            return;
        }

        Log::info('Migrating prompt setting to the new default using {site.languageName}.');

        // Write the whole settings node back with only `prompt` changed, so no
        // other stored settings are lost. (Saving plugin settings with a partial
        // array would replace the node with only the keys passed to it.)
        $settings['prompt'] = self::NEW_DEFAULT_PROMPT;
        ProjectConfig::set('plugins.ai-alt-text.settings', $settings, 'Update AI Alt Text default prompt to use {site.languageName}');
    }

    /**
     * @inheritdoc
     */
    public function down(): void
    {
        // Cannot be reverted
    }
};
