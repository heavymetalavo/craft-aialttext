# Release Notes for AI Alt Text

## 5.1.0 - 2026-08-23

- Fixed existing alt text being lost if a save failed. When **Propagate** is off, generation blanks the alt value in a preliminary save before writing the new one; if that second save failed, the asset was left with an empty alt value instead of what it had before. Both saves now run in one transaction, so a failure leaves the previous value intact.

## 5.0.0 - 2026-07-24

> {note} **This is not a typical major release.** The jump from 1.10.0 to 5.0.0 is a version-numbering change rather than a rewrite: now that Craft 4 is supported, the plugin's major version tracks the major Craft version it supports, so each Craft version has its own release line - 4.x for Craft 4, 5.x for Craft 5. Install the line that matches your Craft version.

> {warning} This release introduces permission checks. Automatic generation on upload or file replacement is unchanged, but users who generate alt text manually now need permission to save the asset in the relevant volume, plus the **AI Alt Text Bulk Actions** utility permission for the utility's "Generate all" / "Generate missing" actions. Grant it under **Settings → Users → (group or user) → Permissions → Utilities**.

- Added a permission requirement to the "Generate all" / "Generate missing" utility actions: the **AI Alt Text Bulk Actions** utility permission (the one Craft registers automatically for the utility, under **Settings → Users → (group or user) → Permissions → Utilities**). Grant it to the relevant user groups after updating, otherwise those actions will be unavailable. The element action is deliberately not permission-gated beyond being able to save each selected asset, and on-upload generation is controlled by the plugin setting alone.
- Added a general CP access (`accessCp`) requirement to the "Generate all" / "Generate missing" utility actions, alongside the AI Alt Text Bulk Actions permission.
- Added alt text generation when an image asset's file is replaced (when the "Generate for new image assets" setting is enabled). The alt text for the site the replacement was made in is overwritten, since it describes the old image; other sites keep their existing alt text unless "Save translated results for each site" is enabled.
- Added a **Coverage** column to the bulk actions utility's table, showing each site's alt text coverage as a percentage preceded by a small progress ring, matching the figures the `stats` console command reports.
- Added a filled-circle glyph alongside each coverage figure in the `stats` console command, colour-coded like the utility's progress ring using the colours a terminal can show. The colour is dropped when colour output is disabled or the output isn't a terminal, leaving the plain glyph.
- Updated the element action and single-asset action to use Craft's own save authorization (`canSave()`), so generating alt text for an asset uploaded by another user requires the "Save assets uploaded by other users" volume permission - matching what the user could edit manually.
- Updated the "Generate AI Alt Text" element action to skip any assets the user doesn't have permission to save.
- Updated the "Generate AI Alt Text" element action to report how many assets were queued and how many were skipped for lack of permission, instead of showing an unqualified success message.
- Updated the bulk action buttons to submit via secure (CSRF-protected) POST forms instead of plain links, and the bulk actions to reject any non-POST request.
- Updated alt text generation to fail with a clear message if the requested site no longer exists (e.g. a queued job running after a site deletion), instead of silently generating with the asset's own site's language.
- Updated the bulk actions utility and the `stats` console command to label their figures as **image assets**, and to state that only image assets are counted - other kinds (videos, PDFs, audio) never receive alt text and are excluded. Images in formats the AI provider doesn't currently support are still counted, so they surface as missing rather than being hidden.
- Updated the `stats` console command to count assets of any status, matching the utility, so its totals no longer disagree with the utility's when disabled assets exist.
- Updated the `stats` console command to print a proper table with column headings and right-aligned figures, instead of repeating a label before every value on each row. The all-sites row leads the table, as it does in the utility.
- Removed the second site-ID argument from the `ai-alt-text/generate/single` console command; pass the site via `--site-id` (or `-s`) instead, consistent with the other commands, so `single 123 2` becomes `single 123 --site-id=2`. A second argument is now ignored.
- Updated the bulk actions utility's table to scroll horizontally instead of overflowing the page at narrower viewport widths, and made the scrollable region keyboard-reachable.
- Renamed the utility from **AI Alt Text** to **AI Alt Text Bulk Actions** to better describe what it does.
- Reduced log noise by trimming lengthy base64 image data from the OpenAI debug logs.
- Prevented a possible infinite loop in the bulk generation console command when a batch size of zero or less was supplied.
- Fixed the console commands reading the wrong alt text value: `stats` and `missing` filtered on the site-agnostic `assets.alt` column instead of the per-site value, so `stats` reported identical figures for every site (disagreeing with the utility), and `missing` silently skipped assets that were missing alt text for a site whenever the asset's own alt value was set.
- Fixed a rare error in the element action when a selected asset could not be reloaded for the current site.
- Fixed a bug where the queue job's error handling didn't catch the plugin's own generation errors, due to catching the wrong `Exception` base class - they would surface as unhandled queue failures instead of the intended logged/described error.
- Fixed a bug where, after a base64 fallback, later assets processed by the same queue worker would unnecessarily skip straight to base64 encoding.
- Fixed a bug where a non-JSON error response from the Anthropic API could hide the original error behind a confusing secondary one.
- Fixed a bug where an OpenAI request failure without a response (e.g. a connection-level error) could obscure the original error.
- Fixed the `stats` console command pointing at a non-existent command (`ai-alt-text-cli/missing`) in its closing tip; it now suggests `ai-alt-text/generate/missing`.
- Fixed the `single` and `stats` console commands advertising `--batch-size`, `--verbose` and `--force` in their `--help` output, which they ignore; those options are now only offered by the `missing` and `all` commands, which actually use them.
- Fixed the `--force` option's description, which claimed it forced regeneration of existing alt text. It skips confirmation prompts - whether existing alt text is regenerated depends on the command (`all` vs `missing`).

## 1.10.0 - 2026-07-07

> {note} If you have a custom **prompt** value and work with non-English language sites, you might want to update it manually to adopt the `{site.languageName}` variable which can return more reliable results in the desired language. Installs still using any former default prompt values are migrated automatically.

- Changed the default prompt to name the target language explicitly - `{site.languageName} (BCP 47: {site.language})`, e.g. `Norwegian (BCP 47: no)`. The previous default ended in a bare code (`Output in the language: no` for Norwegian), which a model could misread as the English word "no" and answer in the wrong language.
- Added a `{site.languageName}` prompt variable that resolves to the language's display name only.
- The prompt is now sent as the system/instruction message for both providers - Anthropic via `system`, and OpenAI via the Responses API top-level `instructions` parameter. The user turn now carries only the image and the shared generation trigger.
- Fixed a bug where saving a setting from a migration could replace all other stored plugin settings (API keys, provider, model, etc.) in project config. Both the new prompt migration and the existing AI provider migration now merge the single changed setting into the stored settings instead, and skip safely (with a warning) on environments where `allowAdminChanges` is disabled instead of failing the update.
- Bumped the plugin schema version so pending migrations are actually detected and run by Craft's updater.
- Fixed a bug where root-relative asset/transform URLs (e.g. from a site with a path-only base URL like `/en`, or during console/queue requests) were sent unresolved to the AI provider and the base64 fallback, causing both to fail. They are now resolved against the primary site's host.

## 1.9.1 - 2026-06-09
- Removed the plugin-level preflight check before sending a request with an image URL to an AI provider. A CDN (e.g. TwicPics) could reject the preflight request from the plugin despite the file being publicly available and accepted by an AI provider.
- Updated base64 fallback behavior so original asset file contents are only sent when their MIME type is accepted by the AI provider.
-  Fixed a bug where root-relative local asset URLs could include a multi-site path segment, resulting in URLs like `domain.com/en/local/image.jpg` instead of `domain.com/local/image.jpg`.

## 1.9.0 - 2026-06-01
- Added support for AVIF, HEIC & HEIF file types, which are converted to PNG before being sent to the AI provider. Conversion occurs depending on the image driver's ability to process those file types. Unsupported assets are skipped gracefully.
- Fixed a bug where the base64 fallback request would use parameters from an already-transformed asset, causing format conversion to be skipped and the original (unsupported) MIME type to be sent on the retry.

## 1.8.1 - 2026-05-06
- Fixed a bug where processing SVGs is now consistently enforced regardless of how alt text generation is triggered (upload event, bulk action, element action menu, or console command).
- Fixed a bug where SVG assets that are not publicly accessible to an AI provider and are sent in a fallback request as base64 could send the original SVG file contents instead of a rasterised version.

## 1.8.0 - 2026-05-04

- Adding new settings field for managing OpenAI reasoning model effort value
- Updating OpenAI API request payload to include reasoning effort value (where reasoning model is used, e.g. `gpt-5*` or `o*`)
- Updating info level logs to debug level to reduce logging noise
- Added a new `processSvgs` setting which now serves as the primary control for whether SVG assets are processed by the plugin. This logic is now decoupled from Craft's global `transformSvgs` setting.
- Updated error handling to gracefully skip SVGs when `processSvgs` is `false` and complete queue jobs successfully instead of throwing exceptions.

## 1.7.1 - 2026-03-25

- Adding a fallback for OpenAI and Anthropic when the image URL is reachable from Craft but unreachable from the provider: a fallback attempt sends the image as base64 instead for round 2 🥊
- Fixing issue for local volumes where image urls could be sent to AI providers as relative URLs, now converts to absolute site URLs before making a request.
- Updating the OpenAI vision `maxFileSizeMb` threshold from 20MB to 512MB to align with new API limit.
- Updating HTTP client timeouts to 30 seconds to support processing larger images.
- Replacing inline fully qualified class names with `use` declarations

## 1.7.0 - 2026-03-19

- Adding functionality to support a new AI Provider (Anthropic)
- Adding new setting field to choose AI Provider (OpenAI or Anthropic)
- Adding automatic image scaling for Anthropic to account for 5MB payload limits and pixel area token costs
- Adding automatic image scaling for OpenAI to account for 20MB payload limits and patch budget constraints
- Added support for new OpenAI detail levels `original` & `auto` option for OpenAI (gpt-5.4+ models)
- Updated bulk actions table and buttons to be migrated to the CraftCMS Dashboard utilities area for improved performance and visibility
- Updated shared logic for image transformation and base64 encoding across providers
- Updated error handling for API request failures and asset accessibility checks
- Updated settings page setup instructions & field instructions and placeholders to be more clear
- Updated all use declartions to be grouped if possible
- Updated plugin services to be registered as plugin components so we can access via the plugin instance rather than via instantiating each time we require them.
- Updated all request/response model class property casing to use camelcase instead of snake case
- Updated request models to no longer use a method to progressively build the payload on each setter
- Updated default prompt in readme and settings to be more concise and direct for intended purpose, to not assuming gender, and to avoid random prefixes.
- Updated `generateForNewAssets` setting to be true by default
- Fixed bug where alt text would propagate across sites despite propagate setting being disabled
- Fixed TypeError in OpenAI error handling where a non-array error response would crash `parseResponse()`

## 1.6.2 - 2025-08-19

- Updating default model to be `gpt-5-nano`
- Updating default prompt to include when describing a person do not assume their gender

## 1.6.1 - 2025-06-27

- Updating fallback format to png where source image is svg to preserve transparency
- Fixed issue where sometimes passing the detail parameter in payloads for images smaller than 512x512 which can *sometimes* result in a failed OpenAI API response especially where the hosted Asset may be on Craft Cloud CDN.
- Updating default prompt as the prior prompt could cause hallucinations on smaller sized images

## 1.6.0 - 2025-06-17

- Adding new console commands for bulk actions:

| Command | Description |
|---------|-------------|
| `ai-alt-text/generate/stats` | Show alt text coverage statistics |
| `ai-alt-text/generate/missing` | Queue jobs for assets without alt text (recommended) |
| `ai-alt-text/generate/all` | Queue jobs for ALL assets (⚠️ overwrites existing alt text) |
| `ai-alt-text/generate/single <id>` | Queue job for a specific asset ID |

## 1.5.8 - 2025-06-15

- Optimised queries used to build table data on settings pages to support sites with large numbers of assets.

## 1.5.7 - 2025-05-18
- Fixed issue when processing images with square aspect ratios - where long side could be under 2000px but the short side could still be larger than 768px (the API limit) and would bypass image transforms generation with accepted dimensions.

## 1.5.6 - 2025-05-17

- Fixed issue where running **Generate all** or **Generate missing** bulk actions could generate alt text for each site **Save translated results for each site** setting was enabled

## 1.5.5 - 2025-05-16

- Update some thrown exceptions to instead become Craft wanings, turns out the API may accept other files anyway!
- Adding new test & exception for private assets with no url and unsupported mime type which cannot be transformed
- Adding test to check file size is under 20MB API limit, in super unlikely scenario where it is larger and within the required dimensions perform a transform where quality is reduced further
- Adding new test & exception for private assets with no url but require a transform as Craft does not support retreiving file contents for transforms
- Adding limitations to readme
- Update logic to support new API dimensions limitation "768px (short side) x 2000px (long side)"
- Update all queue job titles, notices and errors to only contain site ID if there is more than 1 Site
- Update bulk actions table to only show 1 "total" row where there is only 1 site

## 1.5.4 - 2025-05-09

- Removing unused variable `$extension` missed from removing the extension tests in v1.5.3

## 1.5.3 - 2025-05-09
- Improve bulk action notice wording
- Improved logic to not skip generating alt text for an asset where a job is in the queue but it has a failed status
- Replacing `preSaveAsset` setting with `propagate` setting, `preSaveAsset` tried to resolve an issue where the same value could be saved over multiple sites. Could sometimes cause errors e.g. `Failed to pre-save asset: filename.png`, 
- Replacing native `file_get_contents` function with `$assets->getContents` in animated gif test, which is more reliable across asset different platforms
- Removing tests to check an asset's file extension which is not a reliable way to ascertain if the file will be accepted by the OpenAI API
- Updating tests to check an asset's mime type to ascertain if an image transform to a different format is required before it is sent to OpenAI API
- Added new test to check if resulting transform which will be sent to OpenAI is accepted mime type
- Added new test to check if SVGs can be transformed to an accepted mime type

## 1.5.2 - 2025-05-06

- Updating changelog formatting slightly to test supporting Craft's `Utilities → Updates` screen 

## 1.5.1 - 2025-05-05

- Fixed issue where apps with 1 x Site cannot see any bulk action table rows

## 1.5.0 - 2025-05-02

- Added new bulk actions features to generate AI alt text for all assets in a Site
- Added new bulk actions features to generate AI alt text for all assets missing alt text in a Site
- Added new bulk actions features to generate AI alt text for all assets across all Sites
- Added new bulk actions features to generate AI alt text for all assets missing alt text across all Sites
- Improving instructions within settings template
- Improving instruction within README

## 1.4.1 - 2025-05-02

- Fixed issue where uploading a new asset via the current Site would only generate alt text for the default Site

## 1.4.0 - 2025-05-01

- Adding new feature to generate AI alt text on the `ELEMENT::EVENT_AFTER_SAVE` event
- Adding new setting to allow users to generate alt text on upload
- Updating setting descriptions to be more concise
- Refactored logic within the plugin to re-use code, removing dupe code
- Improved main logic within service method to generate alt text for current site off-queue so results can be visualised near immediately
- Refactoring code to be suitable for php8.2
- Removing unused imported classes
- Improved logic so current siteId could be passed through and saved before others
- Updating variables to be more consice, e.g. now $asset instead of $element

## 1.3.2 - 2025-04-25

- Fixed issue where detail setting value would not be used
- Improved logging to return error messages from API so they can be visualized when a queue job has an error

## 1.3.1 - 2025-04-16

- Added immediate processing of alt text generation for single assets in the asset editor view
- Added automatic window refresh after successful alt text generation in the asset editor
- Updated default model from `gpt-4o-mini` to `gpt-4.1-nano` for improved performance
- Refactored asset action menu items logic into service method for better code organization

## 1.3.0 - 2025-04-03

- Added ability to generate alt text directly from the asset dropdown menu in the Control Panel
- Improved error handling and user feedback during alt text generation
- Enhanced queuing process with clearer messages for existing jobs
- Fixed typo in AiAltText.php for proper UI updates after queuing actions
- Fixed variable references in GenerateAiAltText.php for existing job detection
- Fixed event handling for asset actions in the Control Panel

## 1.2.1 - 2025-04-02

- Fixed issue where private remote assets contents could not be retrieved to generate base64 payload
- Updated documentation with clearer model capabilities, prompt structure, and image detail options

## 1.2.0 - 2025-04-01

- Adding feature for supporting generating alts for multi sites.
- Enhanced README.md with improved clarity on plugin usage and configuration

## 1.1.0 - 2025-03-31

- Adding feature for supporting using field handles within the plugin's prompt setting value

## 1.0.8 - 2025-03-31

- Fixed issue where plugin would save the result to an Asset's translatable alt text field for every Craft Site

## 1.0.7 - 2025-03-28

- Updated support to include Craft CMS v5.0.0 after testing
- Updated support to include php 8.2 after testing
- Updated README.md with improved documentation
- Updated composer.json with improved package requirements

## 1.0.6 - 2025-03-28

- Fixed issue with queue job checking not properly detecting existing jobs
- Fixed error handling for duplicate job processing
- Fixed job description format for better job tracking
- Fixed error messages to be more descriptive and include asset IDs

## 1.0.5 - 2025-03-28

- Fixed issue with image format validation not properly handling non-accepted formats
- Fixed image dimension validation to only transform when exceeding OpenAI's limits
- Fixed error handling for image format conversion
- Fixed validation for asset file system access

## 1.0.4 - 2025-03-25

- Fixed issue with image format conversion not being applied correctly
- Fixed image dimension handling to only resize when exceeding OpenAI's limits
- Fixed base64 encoding for local file system access
- Fixed error handling for file system operations

## 1.0.3 - 2025-03-24

- Fixed issue with base64 encoding for local file system access
- Fixed error handling for file system operations
- Fixed validation for asset file system access

## 1.0.2 - 2025-03-24

- Fixed issue with URL accessibility checking
- Fixed error handling for remote URL access
- Fixed validation for public URL access

## 1.0.1 - 2025-03-24

- Fixed issue with image format validation
- Fixed error handling for unsupported image formats
- Fixed validation for asset kind checking

## 1.0.0 - 2025-03-23

- Initial release
- AI-powered alt text generation using OpenAI's GPT-4 Vision model
- Bulk processing of multiple images
- Queue integration for background processing
- Customizable settings for prompt and model selection
- Accessibility-focused alt text generation
- Detailed logging and error reporting
