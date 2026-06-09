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

## Security

### Custom host and SSRF (known, accepted tradeoff)

When the PostHog region is set to "Self-hosted or reverse proxy," the admin
supplies a custom host URL. The server then makes requests to that URL: a
validation POST (`Modules\PostHog\Connection\Validator`) and, when server-side
events are enabled, event delivery via posthog-php. A server fetching an
admin-supplied URL is the classic shape of a Server-Side Request Forgery (SSRF)
vector — a crafted internal URL (e.g. a cloud metadata endpoint like
`http://169.254.169.254/...`) could be reached from inside the host's network.

This is **accepted as-is** for now, because:

- Only users with `manage_options` can set the custom host. Such a user can
  already install plugins and run arbitrary PHP, so this grants no new
  capability on a single-site install.
- The custom host is a documented, advertised feature. Hardening with
  `wp_safe_remote_*` / `wp_http_validate_url()` would block private/internal IPs
  and break legitimate self-hosted or reverse-proxy installs on internal
  networks.

If a stronger threat model is needed later (e.g. multisite with semi-trusted
site admins), the recommended middle ground is to reject only the cloud-metadata
link-local range (`169.254.169.254`) in the validator before saving — this
removes the highest-value SSRF target without breaking internal self-hosted
hosts.

## License

GPL-2.0-or-later.
