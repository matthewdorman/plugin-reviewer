=== Plugin Reviewer ===
Contributors: matthewdorman
Tags: plugins, audit, performance, maintenance
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Read-only evidence for reviewing a WordPress plugin stack.

== Description ==

Plugin Reviewer inventories standard, must-use, and drop-in plugins; cross-references public plugins with WordPress.org; provides explainable abandonment indicators; and audits autoloaded options using a candidate-orphan heuristic.

It does not deactivate, delete, or modify plugins or their data. The plugin is a flashlight, not a judge: a human reviews the evidence and decides what to do.

== Installation ==

1. Upload the `plugin-reviewer` folder to `/wp-content/plugins/`.
2. Activate Plugin Reviewer.
3. Open Tools > Plugin Reviewer.

== Frequently Asked Questions ==

= Does this plugin clean up options? =

No. It only reports evidence. Candidate orphan labels are heuristic and require human confirmation.

= Does any site data leave WordPress? =

No site data or telemetry is sent. Public plugin slugs are queried against `api.wordpress.org` and responses are cached.

== Changelog ==

= 0.1.0 =
* Initial public release.
