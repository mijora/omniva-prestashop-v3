# Changelog

## [Unreleased]
### Fixed
- Fixed an error in registering international shipments when the product names of the order contain UTF-8 characters

## [3.0.1] - 2026-07-28
### Fixed
- Fixed the module activation check to use the module ID instead of the hook

### Improved
- Added a check during upgrade to see if the module is added to all required hooks

## [3.0.0] - 2026-07-03
Initial release. This is a standalone module for PrestaShop 8.0+ (including PS 9), built based on the Omniva Shipping v2.3.5 module.

### Features
- PrestaShop 8.0 and 9.x support
- Symfony/XLIFF translation system
- Modern hooks: `displayCarrierExtraContent`, `displayAdminOrderMain`, `actionEmailSendBefore`
- Automatic cleanup of obsolete files during upgrade
- PHP 7.4+ codebase (typed properties, strict types)
