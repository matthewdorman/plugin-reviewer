=== Plugin Reviewer ===
Contributors: matthewdorman
Tags: plugins, themes, audit, performance, integrity
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Read-only evidence for reviewing a WordPress plugin stack.

== Description ==

Plugin Reviewer checks official WordPress core checksums; inventories plugins; cross-references public plugins with WordPress.org; provides explainable abandonment indicators; audits autoloaded options; and builds a bounded static PHP inventory for the active parent and child themes.

It does not deactivate, delete, or modify plugins or their data. The plugin is a flashlight, not a judge: a human reviews the evidence and decides what to do.

== Installation ==

1. Upload the `plugin-reviewer` folder to `/wp-content/plugins/`.
2. Activate Plugin Reviewer.
3. Open Tools > Plugin Reviewer.

== Frequently Asked Questions ==

= Does this plugin clean up options? =

No. It only reports evidence. Candidate orphan labels are heuristic and require human confirmation.

= Does any site data leave WordPress? =

Public plugin slugs and the installed WordPress version/package locale are queried against `api.wordpress.org`; these requests use WordPress HTTP handling and its normal user agent. No option values, file paths, file contents, usernames, telemetry, or report data are sent. Successful responses are cached.

= What does the core integrity scan cover? =

Expected core files are checked against the official checksums for the installed WordPress version and package locale. Unexpected files are enumerated only in `wp-admin` and `wp-includes`; `wp-content` and unrelated site-root files are intentionally excluded. Unsupported custom/development builds, unavailable checksums, unreadable files, symlinks, and scan limits produce an incomplete or unsupported status rather than a clean result.

= Does theme analysis execute theme code? =

No. It uses PHP tokenization only and never loads or executes a theme file. Active parent and child themes are reported separately. Dynamic callbacks remain unresolved, large functions.php signals are descriptive rather than vulnerabilities, and exclusions, read errors, and limits are shown in coverage notes.

== Changelog ==

= 0.3.0 =
* Add bounded static analysis for active parent and child theme PHP.
* Inventory class-heavy architectures, monolithic functions.php files, literal callbacks, includes, and common WordPress APIs with explicit coverage.

= 0.2.0 =
* Add a bounded, read-only WordPress core integrity scan with explicit coverage status.
* Protect exported CSV cells from spreadsheet formula interpretation.

= 0.1.0 =
* Initial public release.
