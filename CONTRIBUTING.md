# Contributing to CHIP for Formidable Forms

Thank you for your interest in contributing! This document outlines how to set up the development environment, our coding standards, and how to verify your changes.

## Development Setup

### Prerequisites

- PHP 7.4 or higher (8.0+ recommended)
- Composer (for PHPCS/WPCS)
- A local WordPress installation with Formidable Forms 6.35 or newer (free or Pro)

### Installation

1. Clone the repository into your WordPress `plugins/` directory:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/CHIPAsia/chip-for-formidable-forms.git
   cd chip-for-formidable-forms
   ```

2. Install PHP dependencies:
   ```bash
   composer install --no-interaction --prefer-dist
   ```

## Coding Standards

We follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/). Run the linter before submitting a PR:

```bash
# PHP CodeSniffer (WordPress standards)
composer lint

# Auto-fix what can be fixed
composer lint:fix

# PHP Compatibility check
vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4 --extensions=php --ignore=vendor,node_modules,assets .
```

### Key Rules

- Use tabs for indentation (not spaces)
- Maximum line length: 120 characters
- Prefix every global function, class and hook with `frm_chip` / `FrmChip` / `FRM_CHIP`
- Always sanitize input and escape output
- Include `defined( 'ABSPATH' ) || die();` guard at the top of every PHP file
- Text domain: `chip-for-formidable-forms`
- Never call `openssl_pkey_free()` — deprecated in PHP 8.0+
- `phpcs` must exit clean; the ruleset treats warnings as failures

### Architecture Notes

CHIP is registered as a **gateway** on Formidable's shared payments layer via the
`frm_payment_gateways` filter, not as a standalone form action. That means:

- CHIP rides Formidable's existing `frm_payments` and `frm_subscriptions` tables.
  **Never create a second payments table** — it would collide with the core
  gateway rows.
- `FrmChipActionsController::trigger_gateway()` is the entry point Formidable
  calls; the `class` value in the gateway definition resolves to it.
- Payment status changes go through `FrmTransLitePaymentsController::change_payment_status()`
  so Formidable's own `payment-success` / `payment-failed` triggers still fire.
- Settlement lives in `FrmChipSettlement` and is shared by the return handler and
  the callback handler. It must stay idempotent: either path can arrive first.

## Submitting Changes

1. Create a feature branch from `main`:
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. Make your changes and write clear, concise commit messages

3. Ensure the linter passes:
   ```bash
   composer lint
   ```

4. Push your branch and open a Pull Request against `main`

## Verifying Changes

There is no PHPUnit suite. Verify against a real WordPress with Formidable
Forms active:

1. Activate both Formidable Forms and this plugin.
2. Confirm **Formidable → Global Settings → CHIP** renders and saves.
3. Add a **Collect a Payment** action to a form, select **CHIP**, and confirm the
   CHIP panel appears for **both** one-time and recurring payment types.
4. Submit the form with test credentials and confirm the payer reaches the CHIP
   checkout, then that the entry is marked paid on return.
5. Confirm a refund is offered on a completed CHIP payment.

Use the test Brand ID and Secret Key while developing. Never commit credentials.

## Version Numbering

We follow [Semantic Versioning](https://semver.org/) adapted for WordPress plugins:

| Level | When to Bump | Example |
|---|---|---|
| **Major (X)** | Breaking changes, dropped PHP/WP support, major refactors | `2.0.0` |
| **Minor (Y)** | New features, new payment methods, new hooks | `1.1.0` |
| **Patch (Z)** | Bug fixes, security patches, compatibility bumps | `1.0.1` |

### Manual Version Bump Checklist

- [ ] `chip-for-formidable-forms.php` — `Version: X.Y.Z` header
- [ ] `chip-for-formidable-forms.php` — `FRM_CHIP_MODULE_VERSION` constant
- [ ] `chip-for-formidable-forms.php` — `Requires at least` / `Requires PHP` still match readme.txt
- [ ] `readme.txt` — `Stable tag: X.Y.Z`
- [ ] `readme.txt` — `Tested up to:` updated if needed (MAJOR.MINOR only)
- [ ] `readme.txt` — the `== Changelog ==` section carries **only** the version being shipped
- [ ] `changelog.txt` — new version entry with date (this file keeps the full history)
- [ ] `composer.json` — `version` field
- [ ] `README.md` — requirements still match the declared floors
- [ ] `.wordpress-org/screenshot-N.png` — exists for every screenshot `readme.txt` names
- [ ] Run `composer lint` and fix any issues
- [ ] Run `./scripts/check-release-metadata.sh` — it checks that the floors agree across the plugin header, `readme.txt`, `README.md` and `phpcs.xml`, that the version triplet agrees, that `readme.txt` holds exactly one changelog entry, and that every declared screenshot has a file
- [ ] Commit, then tag: `git tag -a vX.Y.Z -m "Release X.Y.Z" && git push origin vX.Y.Z`

> **`readme.txt` carries only the current release.** WordPress.org renders the changelog from `readme.txt`, so it must hold exactly one version entry; `changelog.txt` keeps the full history.

## Questions?

Open an issue on GitHub or reach out to the CHIP developer community.
