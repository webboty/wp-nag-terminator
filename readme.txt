=== NAG Terminator ===
Contributors: tonyhartmann
Tags: admin, notice, nag, dismiss, hide
Requires at least: 5.5
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hide (terminate) WordPress admin notice NAGs for yourself, or for everyone — with full restore history.

== Description ==

NAG Terminator lets you take control of the admin notice NAGs that pile up at the top of every WordPress admin page.

* Adds two small action links to every notice: "Hide for me" and "Hide for everyone" (admins only).
* Works on notices that have no built-in dismiss button.
* Persists dismissals — once hidden, a NAG stays hidden across reloads, browsers, and devices (for that user).
* "Hide for everyone" hides a NAG for every admin and staff user on the site.
* A small `?` help button next to the actions opens a popover explaining what they actually do (they only hide the notice, nothing more).
* Tools → NAG Terminator page has clear tabs: My hidden NAGs, NAGs hidden for everyone, Log (read-only history of every hidden NAG), Documentation, and Settings.
* A 10-second "Undo" toast appears after dismissing, so a stray click is never fatal.
* Per-user bypass: site owners can choose roles that keep seeing NAGs even when hidden for everyone.
* Retention policy: auto-purge log entries older than N days (configurable; default 365).

== Installation ==

1. Upload the `wp-nag-terminator` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Visit Tools → NAG Terminator to see what's currently visible, restore any hidden NAGs, and adjust settings.

== Frequently Asked Questions ==

= How are NAGs identified? =

Each notice is fingerprinted from its normalized text + source hook. This means that even dynamic NAGs (e.g., ad-like notices with changing copy) get a stable ID until the text changes substantially.

= What happens to NAGs I hide for me if I switch computers? =

Per-user dismissals are stored in your WordPress user meta, so they follow you anywhere you log in.

= Can I undo a global hide? =

Yes. Admins can restore from Tools → NAG Terminator → "NAGs hidden for everyone" or from the Log. There's also a 10-second "Undo" toast right after hiding.

= What does the `?` icon do? =

It opens a popover explaining that the action links only hide the notice — they don't run, fix, or accept anything the notice says. The popover has a "Learn more" link that takes you to the in-plugin documentation tab.

= Does this slow down the admin? =

No. The output buffer used to detect NAGs is light, and the CSS/JS only load on admin pages.

== Screenshots ==

1. Each notice gets inline "Hide for me" and "Hide for everyone" action links plus a `?` help button.
2. The `?` help popover with a "Learn more" link to the documentation tab.
3. Tools → NAG Terminator with the My hidden NAGs, NAGs hidden for everyone, and Log tabs.

== Changelog ==

= 1.0.0 =
* Initial release.
