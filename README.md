# Plugin Stack Audit

An anonymized field audit of 100 WordPress plugins across two production client
sites, plus the feature map it produced for a WordPress audit plugin.

All client data is **anonymized** ("Client A" / "Client B"). No client names, URLs,
hostnames, or IPs appear anywhere in this repository. Niche or client-specific
plugin names and business functions have been generalized, and unusually precise
counts rounded, to reduce fingerprinting risk — the remaining inventory is real but
deliberately less unique. A sufficiently determined reader with inside knowledge of
a specific site could still recognize it; review before making this repository
public.

## Contents

| Path | What it is |
|------|-----------|
| `dashboard/plugin-audit-dashboard.html` | Full audit results styled as the wp-admin page the audit plugin would render. Doubles as the plugin UI mockup. |
| `data/plugin-inventory.csv` | One row per plugin (100 rows): version, status, type, wordpress.org metadata, 1–5 risk scores. |
| `data/risk-matrix.csv` | Same population sorted by composite risk score, with scoring rationale. |
| `tools/` | Generators. `build_data.py` holds the dataset and emits the CSVs; `generate_dashboard.py` builds the dashboard. |

## Rebuilding

```bash
cd tools
python3 build_data.py            # regenerates ../data/*.csv
python3 generate_dashboard.py    # regenerates ../dashboard/
```

Python 3.8+ standard library only — no dependencies.

## Data provenance

- **Client A** — live WP-CLI against production (plugin list, autoloaded options,
  cron, hook counts), plus codebase scan; collected 2026-08-05.
- **Client B** — repository scan of `wp-content/plugins/` plus a parse of the
  options table and `active_plugins` from a database dump dated 2026-02-12.
- **wordpress.org** — plugin info API (last updated, tested-up-to, active installs,
  closed status), retrieved 2026-08-05.
- **Vulnerability flags** — public advisories (WPScan, Patchstack, Wordfence pages),
  retrieved 2026-08-05. Version-specific findings were reported to both clients on
  audit day, ahead of any public presentation.

Risk scores (abandonment / security / performance / replaceability, 1–5 each) are
judgment calls informed by that data; the scoring rationale for every plugin is in
`data/risk-matrix.csv`.

## Accessibility

The dashboard aims for WCAG 2.1 AA: semantic landmarks, the ARIA tabs pattern with
roving tabindex and arrow-key support, visible focus indicators, and text contrast
at or above AA. Reviewed internally against AA; not yet audited with assistive
technology by end users.

## License

Copyright 2026 Matt Dorman. Licensed under the GNU General Public License,
version 2 or (at your option) any later version (`GPL-2.0-or-later`). See
[`LICENSE`](LICENSE).
