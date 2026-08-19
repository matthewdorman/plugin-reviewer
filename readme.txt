=== Plugin Reviewer ===
Contributors: matthewdorman
Tags: plugins, audit, performance, maintenance, integrity
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Read-only evidence for reviewing a WordPress plugin stack.

== Description ==

Plugin Reviewer checks official WordPress core checksums; inventories standard, must-use, and drop-in plugins; cross-references public plugins with WordPress.org; provides explainable abandonment indicators; and audits autoloaded options using a candidate-orphan heuristic.

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

== Changelog ==

= 0.2.0 =
* Add a bounded, read-only WordPress core integrity scan with explicit coverage status.
* Protect exported CSV cells from spreadsheet formula interpretation.

= 0.1.0 =
* Initial public release.
