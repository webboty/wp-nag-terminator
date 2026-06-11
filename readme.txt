=== NAG Terminator ===
Contributors: tonyhartmann
Tags: admin, notice, nag, dismiss, hide
Requires at least: 5.5
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
GitHub Plugin URI: webboty/wp-nag-terminator

Hide (terminate) WordPress admin notice NAGs for yourself, or for everyone — with full restore history.

== Description ==

NAG Terminator lets you take control of the admin notice NAGs that pile up at the top of every WordPress admin page.

* Adds two small action links to every notice: "Hide for me" and "Hide for everyone" (admins only).
* Works on notices that have no built-in dismiss button.
* Persists dismissals — once hidden, a NAG stays hidden across reloads, browsers, and devices (for that user).
* "Hide for everyone" hides a NAG for every admin and staff user on the site, **even when WP core renders the notice with role-conditional text** (e.g. "update now" for admins vs. "notify the site administrator" for shop managers).
* A small `?` help button next to the actions opens a popover explaining what they actually do (they only hide the notice, nothing more).
* A blue "Terminated NAGs N" counter in the admin bar (for staff users) shows the total number of hidden NAGs at a glance; click it to open the NAG Terminator page.
* Tools → NAG Terminator page has clear tabs: My hidden NAGs, NAGs hidden for everyone, Log (read-only history of every hidden NAG), Documentation, and Settings.
* A 10-second "Undo" toast appears after dismissing, so a stray click is never fatal.
* Action-link visibility: always visible, or revealed on hover/focus only (in Settings).
* Per-user bypass: site owners can choose roles that keep seeing NAGs even when hidden for everyone.
* Retention policy: auto-purge log entries older than N days (configurable; default 365).
* Optional debug logging (off by default) for diagnosing issues.

== Installation ==

1. Upload the `wp-nag-terminator` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Visit Tools → NAG Terminator to see what's currently visible, restore any hidden NAGs, and adjust settings.

== Frequently Asked Questions ==

= How are NAGs identified? =

Each notice is fingerprinted from its normalized text + source hook. A second "prefix" fingerprint (first 30 chars of normalized text) is also stored so that role-conditional copy variations (e.g. WP core's update notice showing "update now" to admins and "notify the site administrator" to everyone else) still map to the same NAG. This means that even dynamic NAGs (e.g., ad-like notices with changing copy) get a stable ID until the text changes substantially.

= What happens to NAGs I hide for me if I switch computers? =

Per-user dismissals are stored in your WordPress user meta, so they follow you anywhere you log in.

= Can I undo a global hide? =

Yes. Admins can restore from Tools → NAG Terminator → "NAGs hidden for everyone" or from the Log. There's also a 10-second "Undo" toast right after hiding.

= What does the `?` icon do? =

It opens a popover explaining that the action links only hide the notice — they don't run, fix, or accept anything the notice says. The popover has a "Learn more" link that takes you to the in-plugin documentation tab.

= Does this slow down the admin? =

No. The output buffer used to detect NAGs is light, the CSS/JS only load on admin pages, and the admin-bar count is cached in a per-user transient so the bar is computed once per 5 minutes.

= I dismissed a NAG for everyone as admin but shop managers still see it. Why? =

WP core (and some plugins) renders the same notice with different text for admins vs non-admins. NAG Terminator handles this with a "prefix fingerprint" so a global dismiss propagates to all role-conditional renderings of the same notice.

== Screenshots ==

1. Each notice gets inline "Hide for me" and "Hide for everyone" action links plus a `?` help button.
2. The `?` help popover with a "Learn more" link to the documentation tab.
3. The blue "Terminated NAGs N" counter in the admin bar.

== Changelog ==

= 1.1.4 =
* Fix: per-user fingerprint drift — "Hide for everyone" now propagates across role-conditional text variations (e.g. WP core's "update now" vs. "notify the site administrator"). A new "prefix" fingerprint bridges the gap.

= 1.1.3 =
* Performance: admin-bar counter is now cached in a per-user transient (5 min TTL) with invalidation on every dismiss/restore. The counter callback is also skipped on the frontend entirely.
* New "Debug logging" setting (off by default) to help diagnose issues without spamming the error log.
* One-time backfill on upgrade: clears stale prefix fields in existing dismissals.

= 1.1.2 =
* (Internal): prefix algorithm refactored to djb2 + base36 so JS and PHP compute the same hash. No user-visible change.

= 1.1.1 =
* New "Terminated NAGs N" link in the admin bar (staff users only) with a blue count bubble that links to the NAG Terminator page.
* Action-link visibility setting: "Always visible" or "On hover/focus only" (with a smooth CSS transition).

= 1.1.0 =
* Friendlier action-link labels: "Hide for me" / "Hide for everyone".
* Inline `?` help button on every notice opens a modal that explains what the action links actually do, with a "Learn more" link to the in-plugin Documentation tab.
* New "Documentation" tab with what the plugin does, what each tab does, and tips.
* Renamed "Terminated for everyone" → "NAGs hidden for everyone".
* Renamed "Recycle bin" → "Log"; shows the original notice HTML (no action bar inside).
* Removed the "Currently visible" tab (was always empty on the page where you view it).
* Settings is now its own tab.
* Plugin URI + "View on GitHub" link on the Plugins page.
* Settings link in the Plugins row jumps straight to the Settings tab.
* Numerous bug fixes: Detector no longer strips log content, sanitize allowlist preserves notice divs, archive merge fixed.

= 1.0.0 =
* Initial release.
