# Release Notes for AI Alt Text

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
