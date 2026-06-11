# AGENTS.md — NAG Terminator

WordPress plugin. PHP 7.4+, WP 5.5+. No build step, no package manager, no test runner, no linter, no CI in this repo. Everything ships as-is from this directory into `wp-content/plugins/`.

## Repo layout (only what matters)

- `wp-nag-terminator.php` — **main plugin file**. Defines constants (`WP_NAG_TERMINATOR_FILE`, `WP_NAG_TERMINATOR_DIR`, `WP_NAG_TERMINATOR_URL`, `WP_NAG_TERMINATOR_BASENAME`, `WP_NAG_TERMINATOR_SLUG`, `WP_NAG_TERMINATOR_VERSION`) and `require_once`s every class in `includes/` in this exact order: `class-plugin`, `class-installer`, `class-capabilities`, `class-storage`, `class-detector`, `class-suppressor`, `class-ajax`, `class-assets`, `class-admin-page`, `class-plugin-links`. **Add new classes here in dependency order** (consumers after their deps).
- `includes/class-*.php` — one class per file, all in namespace `WpNagTerminator`. `class-plugin.php` is the singleton bootstrap that wires them together (`Plugin::instance()` → `boot()`).
- `assets/css/admin.css`, `assets/js/admin.js` — enqueued on every admin page (not just the plugin page) so inline action buttons can attach to notices.
- `languages/wp-nag-terminator.pot` — translation template. Text domain is `wp-nag-terminator` (note the **hyphen**, not underscore). Domain path: `/languages`.
- `uninstall.php` — runs only on plugin **deletion** (not deactivation). Wipes options, per-user meta, archive, scheduled cron. Do not move data cleanup into `Installer::deactivate()` — deactivation intentionally keeps data.

## Architecture notes an agent would miss

- **No composer/autoloader.** Classes are loaded by explicit `require_once` in the main file. If you add a class, add the `require_once` line too. Do not introduce `spl_autoload_register` or a `vendor/` dir without a strong reason.
- **Singleton via `Plugin::instance()`** is hooked on `plugins_loaded`. All other components are constructed inside `boot()`. Use the existing `Plugin` instance — don't instantiate classes directly from new code paths.
- **Output-buffer detection.** `Detector` opens an `ob_start` at `admin_head` (priority 1) and closes at `admin_footer` (priority `PHP_INT_MAX`) and at `in_admin_header`. It strips notices that match a known dismissed fingerprint and injects `data-nag-id` on the rest. Don't add notice-mutating hooks that run outside this buffer — they'll be stripped or missed.
- **Fingerprinting has two keys per NAG**: a full fingerprint (normalized text + source hook) and a **prefix fingerprint** (first 30 chars of normalized text, hashed with djb2+base36). The prefix bridges role-conditional copy variants (e.g. WP core's "update now" vs "notify the site administrator"). The prefix algorithm must match between PHP and `assets/js/admin.js` — if you change one side, change the other, and add a backfill in `Plugin::maybe_backfill_prefixes()`.
- **Per-user dismissals** live in user meta key `wp_nag_terminator_dismissed` (`Storage::META_USER`). **Global dismissals** live in option `wp_nag_terminator_global_dismissed` (`Storage::OPTION_GLOBAL`). **Archive** is option `wp_nag_terminator_archive` capped at 500 entries (`Storage::ARCHIVE_MAX`). **Settings** is option `wp_nag_terminator_settings` (`Installer::OPTION_SETTINGS`) with keys: `retention_days` (int, 0=forever, default 365), `bypass_global_roles` (array of role slugs), `action_link_visibility` (`always`|`hover`), `enable_debug_logging` (0|1). **Backfill marker** is option `Storage::PREFIX_BACKFILL_OPTION` (autoload=no). Keep these names stable — they're user data.
- **AJAX nonce action is `wp_nag_terminator`** (`Ajax::NONCE_ACTION`). All three endpoints (`terminate`, `restore`, `delete_archive`) require nonce + logged-in user; admin-page "hide for everyone" further requires `manage_options` (see `class-capabilities.php`).
- **Cron hook** `wp_nag_terminator_purge_archive` runs daily and calls `Installer::purge_archive()`. Scheduled on activation, cleared on deactivation AND on uninstall.

## Conventions that differ from WP-plugin defaults

- Namespace prefix is `WpNagTerminator` (PascalCase, no underscore). File names use `class-kebab.php` (WordPress-ism, not PSR-4).
- All public-facing strings go through `__()` / `esc_html__()` etc. with text domain `wp-nag-terminator`. The `WpNagTerminator` JS object on `assets/js/admin.js` exposes an `i18n` block — keep new client-side strings there rather than hardcoding in JS.
- Settings are updated only through `Installer::update_setting()` (allowlisted keys). Don't write to the option directly from AJAX handlers.
- Debug logging is **off by default**. When adding `error_log()` calls, guard them on `Installer::get_settings()['enable_debug_logging']`.

## How to verify changes (no automated tests in repo)

1. **Lint-ish check**: run `php -l` on every modified `includes/*.php` and the main file. No PHP_CodeSniffer config exists; do not invent one.
2. **Smoke test in a real WP install**: symlink or copy the repo into `wp-content/plugins/wp-nag-terminator/`. The repo name **must match the slug** `wp-nag-terminator` (the `.gitignore` comment explicitly warns against symlinking back to a WP install from inside the repo).
3. **Test the full flow** as both admin and a non-admin role:
   - See a notice with no dismiss button → click "Hide for me" → reload → notice stays hidden → restore from Tools → NAG Terminator.
   - Click "Hide for everyone" as admin → log in as a different role that sees role-conditional text → notice stays hidden (prefix fingerprint).
   - 10-second Undo toast appears after dismiss.
4. **Check the admin bar counter** updates (it's cached in a per-user 5-min transient, so changes may lag — flush transient by toggling any dismiss/restore).
5. **Verify the prefix backfill** is idempotent: after the first run, `Storage::PREFIX_BACKFILL_OPTION` is set and the routine early-returns.

## Browser testing against the local demo site

Local WP install used for manual browser testing. The plugin is installed as a symlink so edits in this repo are live (no copy step).

- **URL**: `https://demo-site:8890/`
- **Source path**: `~/DevShare/PHP/Sites/demo-dev-site`
- **Plugin symlink**: `wp-content/plugins/wp-nag-terminator` → this repo
- **Trust the local TLS cert** in your browser / Playwright (it's a self-signed dev cert — `curl -k` or accept the warning once).

**Test accounts**

| User    | Password   | Role  |
|---------|------------|-------|
| `admin` | `admin123` | admin (has `manage_options`, can "Hide for everyone") |
| `admin2`| `admin123` | non-admin staff role — used to verify prefix fingerprinting bridges role-conditional notice copy |

**Test flow**

1. Log in as `admin` → confirm action links appear on a notice that has no native dismiss button (e.g. breakdance / cart-for-woocommerce admin notices in the demo).
2. Click "Hide for me" → reload → notice stays hidden. Confirm 10-second Undo toast.
3. Click "Hide for everyone" on a role-conditional notice (e.g. a WooCommerce update notice) → log out, log in as `admin2` → notice stays hidden (prefix fingerprint bridges the role-specific copy).
4. Tools → NAG Terminator → confirm hidden NAGs show in "My hidden NAGs" and "NAGs hidden for everyone"; restore from each.
5. Confirm the blue "Terminated NAGs N" counter in the admin bar updates (it's a per-user 5-min transient — toggle a dismiss/restore to flush it).

**Reset state between runs**

To clear dismissals for a fresh test run: deactivate + reactivate the plugin (clears cron, keeps data; use this to test backfill), or delete + reinstall to wipe everything (matches `uninstall.php`).

**Playwright / browser automation**

The repo's `.playwright-mcp/` directory holds local browser-test artifacts (gitignored). Headless runs against `https://demo-site:8890/` work once the self-signed cert is trusted in the browser context.

## Release / version bumps

- Bump `Version` in the main plugin file header **and** the `WP_NAG_TERMINATOR_VERSION` constant on the same line.
- Bump `Stable tag` in `readme.txt` to match.
- Add a changelog entry under `== Changelog ==` in `readme.txt` (newest on top, `= x.y.z =` header format).
- `.pot` file is regenerated manually — if you add strings, run WP-CLI's `wp i18n make-pot . languages/wp-nag-terminator.pot --domain=wp-nag-terminator` against a WP install.
- The Plugin Header has `GitHub Plugin URI: webboty/wp-nag-terminator` — releases go to that GitHub repo, not the WordPress.org plugin directory (no `update_uri`/SVN setup here).

## Files to read first when exploring

1. `wp-nag-terminator.php` — wiring + constants
2. `includes/class-plugin.php` — bootstrap
3. `includes/class-storage.php` — the data model (everything else stores through this)
4. `includes/class-detector.php` — how NAGs are found and fingerprinted
5. `assets/js/admin.js` — the inline action-bar wiring (mirror of the PHP detector)

## Out of scope / don't add

- No `composer.json`, `package.json`, `node_modules/`, or `vendor/` — don't add them.
- No CI workflow files exist. Don't add `.github/workflows/` unless explicitly asked.
- No unit tests, no `phpunit.xml`. Manual WP install smoke test only.
- `.playwright-mcp/` is gitignored local browser-test artifacts; ignore it.
