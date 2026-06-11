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

* Adds two small action links to every notice: "Hide for me" and "Terminate for everyone" (admins only).
* Works on notices that have no built-in dismiss button.
* Persists dismissals — once hidden, a NAG stays hidden across reloads, browsers, and devices (for that user).
* "Terminate for everyone" hides a NAG for every admin and staff user on the site.
* Stores an archive (recycle bin) of every dismissed NAG, so you can always restore a NAG that turns out to matter.
* A 10-second "Undo" toast appears after dismissing, so a stray click is never fatal.
* Tools → NAG Terminator page lists everything currently visible, your hidden NAGs, globally terminated NAGs, and the full recycle bin.
* Per-user bypass: site owners can choose roles that keep seeing NAGs even when globally terminated.
* Retention policy: auto-purge archive entries older than N days (configurable; default 365).

== Installation ==

1. Upload the `wp-nag-terminator` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Visit Tools → NAG Terminator to see what's currently visible, restore any hidden NAGs, and adjust settings.

== Frequently Asked Questions ==

= How are NAGs identified? =

Each notice is fingerprinted from its normalized text + source hook. This means that even dynamic NAGs (e.g., ad-like notices with changing copy) get a stable ID until the text changes substantially.

= What happens to NAGs I hide for me if I switch computers? =

Per-user dismissals are stored in your WordPress user meta, so they follow you anywhere you log in.

= Can I undo a global termination? =

Yes. Admins can restore from Tools → NAG Terminator → "Terminated for everyone" or from the recycle bin. There's also a 10-second "Undo" toast right after dismissal.

= Does this slow down the admin? =

No. The output buffer used to detect NAGs is light, and the CSS/JS only load on admin pages.

== Screenshots ==

1. Each notice gets inline "Hide for me" and "Terminate for everyone" action links.
2. Tools → NAG Terminator lists everything hidden and lets you restore it.
3. The recycle bin keeps a history of all dismissed NAGs.

== Changelog ==

= 1.0.0 =
* Initial release.
