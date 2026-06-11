# Security Audit — NAG Terminator v1.1.5

**Audit date:** 2026-06-11  
**Commit:** `7d82329` (HEAD)  
**Scope:** All tracked PHP, JS, CSS, and config files (excluding `.gitignore`-d `.playwright-mcp/` artifacts)

---

## Credential Leaks

**Result: CLEAN — no credentials, API keys, passwords, tokens, or secrets found anywhere in the repository.**

- No `.env` files, no hardcoded keys, no private URLs beyond the public GitHub repo.
- `.gitignore` properly excludes `.DS_Store`, `.idea/`, `.vscode/`, `*.swp`/`*.swo`, `*.log`, and `.playwright-mcp/`.
- `README.md` and `AGENTS.md` contain no secrets.

---

## Submitted-File Inventory

```
wp-nag-terminator.php          — Main plugin file, constants, require_once chain
uninstall.php                  — Runs on plugin deletion (not deactivation)
readme.txt                     — WP.org readme format, changelog, FAQ
README.md                      — GitHub markdown readme
AGENTS.md                      — Repo conventions for AI agents
assets/css/admin.css           — Frontend CSS (admin only)
assets/js/admin.js              — Frontend JS (admin only, jQuery)
includes/class-plugin.php      — Singleton bootstrap
includes/class-installer.php   — Activation, deactivation, settings, cron
includes/class-capabilities.php— Authorization checks
includes/class-storage.php     — Data layer (options, user meta, archive, caching)
includes/class-detector.php    — Output-buffer notice capture + fingerprinting
includes/class-suppressor.php  — WP 6.4+ admin_notice_args filter
includes/class-ajax.php        — AJAX handlers (terminate, restore, delete)
includes/class-assets.php      — CSS/JS enqueue + body-class injection
includes/class-admin-page.php  — Tools → NAG Terminator UI + admin-bar counter
includes/class-plugin-links.php— Plugin row action links
languages/wp-nag-terminator.pot— Translation template
```

---

## Authorization & Access Control

| Endpoint / Feature               | Capability Required      | Nonce? | Verdict |
| -------------------------------- | ------------------------ | ------ | ------- |
| AJAX `terminate` (user scope)    | `is_user_logged_in()`    | Yes    | OK      |
| AJAX `terminate` (global scope)  | `manage_options`         | Yes    | OK      |
| AJAX `restore` (user scope)      | `is_user_logged_in()`    | Yes    | OK      |
| AJAX `restore` (global scope)    | `manage_options`         | Yes    | OK      |
| AJAX `delete_archive`            | `manage_options`         | Yes    | OK      |
| Admin page "My hidden NAGs"      | `read`                   | N/A    | OK      |
| Admin page "Global/Log/Settings" | `manage_options`         | CSRF   | OK      |
| Settings form                    | `manage_options`         | Yes    | OK      |
| Admin bar counter                | `edit_posts` + `is_admin()` | N/A | OK      |
| Plugin action links              | Auto (WP core)           | N/A    | OK      |

All AJAX endpoints validate `nag_id` via regex `/^nag_[a-f0-9]{6,40}$/` before processing.

---

## Input Sanitization & Output Escaping

| Area                     | Method                                              | Verdict |
| ------------------------ | --------------------------------------------------- | ------- |
| `$_GET['tab']`           | `sanitize_key(wp_unslash(...))` + fallback           | OK      |
| `$_POST['nag_id']`       | `wp_unslash()` → `is_valid_nag_id()` regex validation | OK      |
| `$_POST['scope']`        | `sanitize_key()`                                     | OK      |
| `$_POST['excerpt']`      | `sanitize_text_field()`                              | OK      |
| `$_POST['content']`      | `Ajax::sanitize_notice_html()` (wp_kses with allowlist) | OK   |
| `$_POST['prefix']`       | `sanitize_text_field()`                               | OK      |
| `$_POST['bypass_global_roles']` | `array_map('sanitize_key', wp_unslash(...))`   | OK      |
| Settings retain/vis       | Type casting + enum validation                        | OK      |
| All HTML output           | `esc_html()`, `esc_attr()`, `esc_url()` consistently   | OK      |
| Processed buffer echo     | Auto-processed admin HTML (not user input)             | OK      |

**Notable:** `process_buffer()` output is echoed with `// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` — this is intentional and correct because the content is the captured admin page HTML (not user-submitted data).

---

## Database Operations & SQL Injection

**Result: No raw SQL queries in normal operation. All data access uses WordPress APIs (`get_option`, `update_option`, `get_user_meta`, `update_user_meta`, `delete_user_meta`).**

The only raw SQL is in the one-time backfill migration (`Plugin::maybe_backfill_prefixes()`):

```php
$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
        Storage::META_USER
    )
);
```

- Uses `$wpdb->prepare()` with `%s` placeholder — no injection vector.
- Idempotent: guarded by `PREFIX_BACKFILL_OPTION` flag, runs exactly once on upgrade.
- Backfill only unsets stale `prefix` keys in existing dismissal metadata; no data loss.

---

## CSRF Protection

| Form / Action                    | Method                                           | Verdict |
| -------------------------------- | ------------------------------------------------ | ------- |
| Settings form                    | `wp_nonce_field()` + `check_admin_referer()`       | OK      |
| AJAX endpoints (all 3)          | `check_ajax_referer(..., 'nonce', false)`         | OK      |
| Admin bar node                   | Read-only link, no state change                    | OK      |

---

## XSS Review

**Result: No XSS vectors found.**

- All user-controlled data output is escaped (`esc_html`, `esc_attr`, `esc_url`).
- Stored notice HTML in archive is sanitized via `wp_kses` with an explicit tag/attribute allowlist.
- The allowlist permits `target` on `<a>` tags but does not enforce `rel="noopener"` when `target="_blank"` is present. This is a **low-risk hardening gap** (`class-ajax.php:201-207`). Attack surface is limited to admin users viewing stored notice HTML from other plugins.

### KSES Allowlist (for stored notice HTML)

Allowed tags: `div`, `p`, `span`, `a`, `strong`, `em`, `b`, `i`, `br`, `code`, `pre`, `small`, `ul`, `ol`, `li`, `button`, `svg`, `path`.

Allowed attributes per element are explicitly enumerated. `wp_kses` strips `javascript:` protocol from `href` attributes automatically.

---

## Performance Analysis

### Frontend (non-admin) Requests

**Zero impact.** No hooks fire. No database queries. No output buffering. No CSS/JS enqueued. The plugin boots on `plugins_loaded` (class instantiation + hook registration only) but all hooks are admin-only:

- `admin_head`, `admin_enqueue_scripts`, `admin_body_class` → admin only
- `admin_bar_menu` → guarded with `if ( ! is_admin() ) { return; }`
- `wp_ajax_*` → AJAX only
- `admin_menu`, `plugin_action_links_*` → admin only

### Admin Requests

| Operation                          | Cost        | Notes                                                            |
| ---------------------------------- | ----------- | ---------------------------------------------------------------- |
| Output buffer (admin_head→shutdown)| ~memory     | Holds full page HTML. Same overhead as any notice-modifying plugin. |
| `process_buffer()` regex scan      | ~0.5-2ms    | Two `preg_replace_callback` calls on full page. Linear in page size. |
| Per-notice fingerprint (x N)       | ~0.05ms/N   | sha1 + 6 preg_replace calls per notice.                          |
| Admin bar counter                  | 0 (cached)  | 5-min transient. Cache miss: 2 DB reads then cached.             |
| Admin bar counter invalidations    | Instant     | Per-user transients deleted on dismiss/restore; global version bump invalidates all. |

**Buffer now lives from `admin_head` to `shutdown`** (commit `88adf1c`). Previously it was `admin_head` → `admin_footer`. The extra hold time (a few ms) is negligible. The benefit: notices rendered after `admin_footer` by plugins like WooCommerce Anti-Fraud are now captured.

### Transient-Based Count Caching

```
Key:   wp_nag_terminator_count_{user_id}_v{global_version}
TTL:   300 seconds (5 minutes)
Invalidation:
  - Per-user dismiss/restore → delete_transient(key)
  - Global dismiss/restore/archive → bump global_version (invalidates ALL user caches)
```

---

## Detector Output-Buffer Lifecycle (v1.1.5)

```
admin_head / in_admin_header (prio 1)
  → start_buffer()
    → guard: is_admin(), !DOING_AJAX, can_dismiss_for_user()
    → ob_start()  (no callback — plain capture)
    → $buffering = true

  [ ... entire request lifecycle ... ]
  [ all admin hooks fire: admin_notices, admin_footer, etc. ]
  [ all echoed output accumulates in the buffer ]

shutdown (prio -PHP_INT_MAX)
  → end_buffer()
    → guard: $buffering flag
    → ob_get_clean()  → pulls all captured HTML
    → process_buffer() → strip dismissed, inject action bars
    → echo $processed → sends to browser
    → $buffering = false

  [ other shutdown callbacks run after ours (higher priority) ]
```

Edge cases handled:
- Fatal error: `register_shutdown_function` still fires → cleanup runs.
- Buffer already closed by another plugin: `ob_get_level() <= 0` → skip.
- User not logged in: `start_buffer()` never fires → `end_buffer()` returns early.
- AJAX request: `DOING_AJAX` guard blocks buffer start.
- Log content preservation: Placeholder-based masking before notice processing, restored after.

---

## Issues Summary

### Fixed (all prior audit findings resolved)

| # | Original Finding | Commit | Fix |
|---|-----------------|--------|-----|
| 1 | `error_log()` debug spam | `6d93b4a` | Removed; replaced with opt-in debug logging setting |
| 2 | Admin bar DB query on frontend | `6d93b4a` | `is_admin()` guard added |
| 3 | No count caching | `6d93b4a` | 5-min transient with version-based invalidation |
| 4 | Dead `$nonce` in AJAX handler | `07e78e2` | Line removed |
| 5 | Dead `&$detected_ref` in closure | `07e78e2` | Removed from `use` clause |
| 6 | `@ob_end_flush()` error suppression | `07e78e2` | Replaced with `ob_get_clean()` + `echo` |
| 7 | `auto_archive` dead setting check | `07e78e2` | Condition removed — always archives now |
| 8 | Duplicate `fields` array key in uninstall.php | `07e78e2` | Fixed |
| 9 | Orphaned JS hover code | `07e78e2` | Removed; replaced by CSS body-class approach |

### Remaining (low risk)

| # | Finding | Location | Severity | Recommendation |
|---|---------|----------|----------|----------------|
| 1 | `target="_blank"` in KSES allowlist without `rel="noopener"` enforcement | `class-ajax.php:201-207` | LOW | Consider post-processing stored HTML to add `rel="noopener noreferrer"` on links with `target="_blank"`. Note: content is rendered in the admin area only, not public-facing. |

---

## Site-Breaking Risk Assessment

**Risk: VERY LOW**

- Plugin activation only writes settings options with safe defaults.
- Plugin deactivation preserves all data (intentional).
- Plugin uninstallation only fires on explicit `Delete` from Plugins screen (guarded by `WP_UNINSTALL_PLUGIN`).
- Output buffer is defensive: checks `is_admin()`, `buffering` flag, buffer level, and empty content.
- If the buffer fails entirely (e.g. `ob_start()` returns false), notices simply pass through unmodified — the admin still works, just without action bars.
- No fatal errors in the hot path. All PHP 7.4+ features used are stable (return types, `??`, `<=>`).
- No dependency on external services or libraries beyond WordPress core and jQuery (bundled with WP).

---

## Verdict

**Plu gin is production-ready.** No credential leaks. No SQL injection. No XSS vectors. Proper authorization on all endpoints. Performance impact is isolated to admin requests and is negligible. The one remaining low-severity item (KSES `target="_blank"` hardening) is a defense-in-depth improvement, not a vulnerability.
