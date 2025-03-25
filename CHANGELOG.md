# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Support for base64 image encoding when public URLs aren't available
- Support for local file system access for assets without public URLs
- Better error handling for file system operations
- More detailed error messages for various failure scenarios

### Changed
- Updated validation to check for either public URL or file system access
- Improved error handling in `generateAltText` method
- Updated documentation to reflect new URL and file system access requirements
- Changed error logging to throw exceptions for better user feedback

### Fixed
- Fixed validation logic for asset kind checking
- Fixed error handling to properly surface issues to end users
- Fixed documentation to accurately reflect URL requirements

## [1.0.0] - 2024-03-24

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
