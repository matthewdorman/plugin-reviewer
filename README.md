# Plugin Reviewer

**[Download the installable plugin ZIP](https://github.com/matthewdorman/plugin-reviewer/releases/latest/download/plugin-reviewer.zip)**

Plugin Reviewer gives WordPress administrators a read-only WordPress core
integrity check, an inventory of installed plugins, public WordPress.org
maintenance signals, explainable abandonment indicators, and an
autoloaded-options report. It does not repair core, deactivate plugins, delete
options, or change site configuration.

> Download `plugin-reviewer.zip` from the release link above. Do **not** use
> GitHub's automatically generated “Source code” archives; those contain
> contributor tools and are not the supported WordPress installation package.

## Install and run the audit

1. Download `plugin-reviewer.zip` from the [latest GitHub Release](https://github.com/matthewdorman/plugin-reviewer/releases/latest).
2. Sign in to WordPress as an administrator.
3. Go to **Plugins → Add New Plugin → Upload Plugin**.
4. Choose `plugin-reviewer.zip`, select **Install Now**, and then **Activate Plugin**.
5. Go to **Tools → Plugin Reviewer**. Opening this screen runs the read-only audit and displays the current results.
6. Review the evidence on screen. Select **Export report CSV** to download a copy for further review or safe sharing.

The plugin requires WordPress 6.0 or newer, PHP 7.4 or newer, and a user with the
`activate_plugins` capability. On multisite, use an account with the equivalent
network capability.

## What to expect

- The audit inventories standard, must-use, and drop-in plugins.
- Core files are compared with authoritative checksums for the installed WordPress
  version and package locale. Checksum manifests are cached for 12 hours; local
  files are scanned fresh when the report is generated.
- Modified and missing expected files are reported. Unexpected files are
  enumerated only under `wp-admin` and `wp-includes`; `wp-content` and unrelated
  site-root files are intentionally excluded to avoid false positives.
- Development/nightly builds are reported as unsupported. Missing checksums,
  unreadable files, symlinks, and scan limits produce an explicit incomplete
  status rather than a clean result. Custom distributions may therefore need
  manual interpretation.
- Public plugin slugs and the installed WordPress version/package locale are sent
  to `api.wordpress.org` to retrieve directory metadata and core checksums. These
  requests use WordPress HTTP handling and its normal user agent. No option
  values, file paths, file contents, usernames, telemetry, or report data are
  transmitted.
- WordPress.org responses are cached for 12 hours and local option-analysis results
  for one hour. Uninstalling removes Plugin Reviewer transients.
- The options report reads option names and serialized byte sizes. It never reads
  option values into the report and never modifies or deletes an option.
- “Candidate orphan” and abandonment scores are evidence for human review, not
  cleanup instructions. Confirm ownership and business impact before making any
  site change.

Large plugin stacks or a slow connection to WordPress.org can make the first page
load take longer. Each directory request has an eight-second timeout; unavailable
metadata is shown as **Unavailable** and can be retried by reloading later. If the
page hits a hosting timeout or memory limit, ask the host to temporarily raise the
wp-admin PHP execution limit, then reload the report. Cached responses make later
runs faster.

## Troubleshooting

- **WordPress says the package is invalid:** confirm the downloaded file is the
  release asset named `plugin-reviewer.zip`, not a GitHub source-code archive.
- **Tools → Plugin Reviewer is missing:** confirm the plugin is active and the
  signed-in account can activate plugins.
- **Directory details say “Unavailable”:** verify that the server can make outbound
  HTTPS requests to `api.wordpress.org`, then retry later.
- **Core scan says “Incomplete” or “Unsupported”:** review its coverage notes.
  Confirm the site can reach WordPress.org and that it uses an official stable
  package. Symlinked or host-customized core layouts are not asserted clean.
- **The page times out:** retry once to benefit from cached directory results. For
  unusually large stacks, check the host's PHP execution-time and memory limits.
- **Export does not start:** sign in again and retry. CSV export requires the same
  administrator capability and a valid WordPress security nonce.

Deactivating the plugin stops its admin screen but retains temporary caches until
they expire. Deleting it through WordPress runs `uninstall.php`, which removes only
Plugin Reviewer transients. It does not remove or alter data belonging to other
plugins.

## Contributor development

`main` contains the complete, releasable source. Work in short-lived feature
branches and merge through pull requests; there is no long-lived development or
generated distribution branch.

The Python scripts and anonymized field-audit dataset are contributor/reference
material only. They are never included in the installable plugin ZIP and are not
required on a WordPress site.

Rebuild the reference dataset and dashboard (Python 3.8+, standard library only):

```bash
cd tools
python3 build_data.py
python3 generate_dashboard.py
```

Build and validate the exact release artifact locally:

```bash
./scripts/build-release.sh
./scripts/validate-release.sh dist/plugin-reviewer.zip
```

The build uses standard shell tools plus `zip` and `unzip`. It copies an explicit
allowlist of runtime files into a clean staging directory, so repository data,
Python, tests, and development configuration cannot leak into the package.

Before tagging a release, update the version in `plugin-reviewer.php` and the
stable tag/changelog in `readme.txt`. Push a tag matching that version, such as
`v0.2.0`. The GitHub Actions workflow lints PHP, runs isolated integrity fixtures,
verifies the tag/version match,
builds and validates `plugin-reviewer.zip`, and attaches it to a GitHub Release.

## Repository reference material

| Path | Purpose |
| --- | --- |
| `plugin-reviewer.php`, `includes/`, `assets/`, `languages/` | Production plugin source |
| `scripts/` | Reproducible release build and validation |
| `dashboard/` | Anonymized field-audit dashboard and UI reference |
| `data/` | Anonymized field-audit CSV data |
| `tools/` | Python generators for the reference material |

## License

Copyright 2026 Matt Dorman. Licensed under the GNU General Public License,
version 2 or (at your option) any later version (`GPL-2.0-or-later`). See
[`LICENSE`](LICENSE).
