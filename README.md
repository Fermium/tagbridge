# Tagbridge – Deep Integration for PostHog

An independent PostHog integration for WordPress. Connect your PostHog project,
configure what gets tracked, and load PostHog on your site, with no code.

Tagbridge is not affiliated with, endorsed by, or sponsored by PostHog.

This is the developer README. End-user documentation lives in `readme.txt`.

## Architecture

Two layers with a hard line between them:

- **`src/Core/`** — platform-agnostic PHP with **zero WordPress function calls**.
  Plain data in, plain data out (host resolution, the identity resolver). This is
  the reusable heart of the plugin.
- **`src/Platform/`** — WordPress glue. Adapts WordPress (hooks, options, users,
  admin UI) to the Core.

## Requirements

- **Runtime:** PHP 8.2+, WordPress 5.8+. WooCommerce is optional.
- **Dev:** PHP 8.2+ and Composer, Node 18+, and Docker (for `wp-env`).

## Local development

```bash
composer install   # PHP dev tools + posthog-php
npm install        # wp-env + build tooling
npm run env:start  # WordPress at http://localhost:8888 (admin / password)
```

### Quality checks

```bash
composer lint       # PHPCS (WordPress Coding Standards)
composer lint:fix   # auto-fix
composer test:unit  # PHPUnit unit tests (Core)
```

### Build the distributable ZIP

```bash
bin/build-zip.sh    # produces dist/tagbridge.zip (production files only)
```

## Repository layout

```
tagbridge.php            Main plugin file: header, guards, bootstrap
uninstall.php           Uninstall cleanup
readme.txt              WordPress.org readme
src/Core/               Platform-agnostic logic (no WP calls)
src/Platform/           WordPress glue
assets/                 Admin CSS/JS
languages/              Translation template (.pot)
tests/                  PHPUnit tests
```

## License

GPL-2.0-or-later.
