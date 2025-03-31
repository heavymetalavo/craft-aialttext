# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2025-03-31

### Added
- Adding feature for supporting using field handles within the plugin's prompt setting value

## [1.0.8] - 2025-03-31

### Fixed
- Fixed issue where plugin would save the result to an Asset's translatable alt text field for every Craft Site

## [1.0.7] - 2025-03-28

### Changed
- Updated support to include Craft CMS v5.0.0 after testing
- Updated support to include php 8.2 after testing
- Updated README.md with improved documentation
- Updated composer.json with improved package requirements

## [1.0.6] - 2025-03-28

### Fixed
- Fixed issue with queue job checking not properly detecting existing jobs
- Fixed error handling for duplicate job processing
- Fixed job description format for better job tracking
- Fixed error messages to be more descriptive and include asset IDs

## [1.0.5] - 2025-03-28

### Fixed
- Fixed issue with image format validation not properly handling non-accepted formats
- Fixed image dimension validation to only transform when exceeding OpenAI's limits
- Fixed error handling for image format conversion
- Fixed validation for asset file system access

## [1.0.4] - 2025-03-25

### Fixed
- Fixed issue with image format conversion not being applied correctly
- Fixed image dimension handling to only resize when exceeding OpenAI's limits
- Fixed base64 encoding for local file system access
- Fixed error handling for file system operations

## [1.0.3] - 2025-03-24

### Fixed
- Fixed issue with base64 encoding for local file system access
- Fixed error handling for file system operations
- Fixed validation for asset file system access

## [1.0.2] - 2025-03-24

### Fixed
- Fixed issue with URL accessibility checking
- Fixed error handling for remote URL access
- Fixed validation for public URL access

## [1.0.1] - 2025-03-24

### Fixed
- Fixed issue with image format validation
- Fixed error handling for unsupported image formats
- Fixed validation for asset kind checking

## [1.0.0] - 2025-03-23

### Added
- Initial release
- AI-powered alt text generation using OpenAI's GPT-4 Vision model
- Bulk processing of multiple images
- Queue integration for background processing
- Customizable settings for prompt and model selection
- Accessibility-focused alt text generation
- Detailed logging and error reporting

### Changed
- None (initial release)

### Fixed
- None (initial release)
