# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- API keys now come only from Mage-OS AI Base. One **AI Service** setting under General picks a
  Google Gemini or OpenAI service; its key is used and its type picks the provider. This replaces
  the **Image Provider** setting and the per-provider **Credentials**, **API Key** and
  **AI Service** fields. `setup:upgrade` imports a key that was entered in a provider group as an
  AI Base row, selects the row of the provider that was selected, and removes the old settings.
- The configuration section is called **Image Generation** (was *AI Product Images*). Config paths
  and ACL ids are unchanged.
- All settings are default scope only. The Mago tools, the cleanup cron and the CLI never read
  website values, so the admin no longer offers them.
- Requires Mage-OS AI Base 1.1 and symfony/ai 0.15, and declares the Magento modules the code
  uses (2.4.8 or later). Magento Media Storage is no longer required.
- `ProviderConfigInterface` no longer has `getCredentialSource()` or the credential constants;
  a third-party provider using them needs updating.

### Fixed
- The module can be installed on Magento Open Source: it required `mage-os/*` core packages that
  only exist on the Mage-OS repository.
- A failing later call in a multi-image Gemini request no longer throws away the images earlier
  calls returned, and one image that can't be stored no longer discards the others.
- The daily cleanup handles every expired preview instead of at most 200, and a preview that
  can't be removed no longer blocks the rest.
- Preview files of deleted products are removed by the daily cleanup.

### Security
- Decrypted API keys are redacted from stack traces, and the Gemini base URL must be an http or
  https URL.
- Vulnerabilities can be reported privately, see [SECURITY.md](SECURITY.md).

### Added
- CI with check-extension (compile, coding standard, unit, integration and PHPStan level 8) and
  `composer audit`, Dependabot, and configs to run PHPStan, PHPCS and PHPUnit from the module root.

## [1.0.0] - 2026-10-24

### Added
- First release: product images from a gallery image and a prompt with Google Gemini, OpenAI or a
  developer-mode dummy provider, as previews that can be attached to the product gallery, through
  Mago tools and a CLI.

[Unreleased]: https://github.com/olivertar/m2_mago_ai_image/compare/1.0.0...HEAD
[1.0.0]: https://github.com/olivertar/m2_mago_ai_image/releases/tag/1.0.0
