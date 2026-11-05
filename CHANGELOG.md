# Changelog

## Unreleased — planned v2.0.0

This is a breaking package release; the next tag after v1.5.1 must be v2.0.0.

### Changed

- Require PHP 8.2 or later and Behat 4, without Behat 3 compatibility.
- Configure the package suite through `behat.php` and the official Behat 4 configuration API.
- Consume `libresign/behat-builtin-extension:^1.0`.
- Use `webmozart/assert` for runtime assertions; keep PHPUnit only in development requirements.
- Compare JSON semantically: ignore object member ordering, preserve array ordering and JSON types, and report parsing failures.
- Accept `Behat\Step\DataTable` and `Behat\Step\DocString` in step signatures. Custom context overrides must update their parameter types.
- Preserve the public Gherkin wording of generic Nextcloud/OCS/OCC steps.
- Reset command-output state before each scenario to avoid cross-scenario leakage.

### Verification before publishing

- Run the full Behat feature suite and PHPUnit regression tests on PHP 8.2–8.5.
- Run Psalm, PHP-CS-Fixer, and Composer platform checks.
- Run `bash tests/consumer-smoke.sh` on PHP 8.2. It installs a local version 2.0.0 candidate with `--no-dev`; it does not verify a published release.
- After the implementation PR is merged and verified on `main`, publish a signed tag/release `v2.0.0`.
- Verify `composer require libresign/nextcloud-behat:^2.0` without a `dev-*` constraint, then check Behat 4, context discovery, and the package feature suite.
- Only then close #124 and update LibreSign's `vendor-bin/behat` to consume the new release.
