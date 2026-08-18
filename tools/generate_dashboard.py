#!/usr/bin/env python3
"""Generate the wp-admin-styled audit dashboard (self-contained HTML)."""

import importlib.util, html, re

spec = importlib.util.spec_from_file_location("bd", "build_data.py")
bd = importlib.util.module_from_spec(spec)
import sys, io

_old = sys.stdout
sys.stdout = io.StringIO()
spec.loader.exec_module(bd)
sys.stdout = _old
A, B = bd.A, bd.B


def esc(s):
    return html.escape(str(s))


def badge(status):
    cls = {"active": "b-act", "inactive": "b-ina", "must-use": "b-mu"}.get(
        status, "b-ina"
    )
    return f'<span class="badge {cls}">{esc(status)}</span>'


def tbadge(t):
    cls = {
        "free": "t-free",
        "premium": "t-prem",
        "custom": "t-cust",
        "host": "t-cust",
    }.get(t, "t-free")
    return f'<span class="badge {cls}">{esc(t)}</span>'


def score_cell(v):
    return f'<td class="sc s{v}">{v}</td>'


def inv_table(data, site_id):
    rows = []
    for name, ver, status, typ, cat, upd, tested, inst, ab, sec, pf, rep, flags in data:
        comp = ab + sec + pf + rep
        risk_cls = "risk-hi" if comp >= 13 else ("risk-md" if comp >= 10 else "")
        inst_s = f"{inst:,}" if isinstance(inst, int) else "—"
        rows.append(f"""<tr class="{risk_cls}">
<td class="pname"><strong>{esc(name)}</strong><div class="cat">{esc(cat)}</div></td>
<td>{esc(ver)}</td><td>{badge(status)}</td><td>{tbadge(typ)}</td>
<td>{esc(upd) if upd else '<span class="na">not on wp.org</span>'}</td>
<td>{esc(tested) if tested else '—'}</td><td class="num">{inst_s}</td>
<td class="num"><strong>{comp}</strong></td>
<td class="notes">{esc(flags)}</td></tr>""")
    return f"""<table class="wp-list-table widefat fixed striped" id="tbl-{site_id}">
<thead><tr><th class="w-name">Plugin</th><th class="w-ver">Version</th><th class="w-st">Status</th><th class="w-ty">Type</th>
<th class="w-upd">wp.org last updated</th><th class="w-tst">Tested to</th><th class="w-in">Installs</th><th class="w-cmp">Risk</th><th>Notes</th></tr></thead>
<tbody>{''.join(rows)}</tbody></table>"""


def risk_table():
    allr = [("Client A",) + p for p in A] + [("Client B",) + p for p in B]
    allr.sort(key=lambda r: -(r[9] + r[10] + r[11] + r[12]))
    rows = []
    for r in allr:
        (
            site,
            name,
            ver,
            status,
            typ,
            cat,
            upd,
            tested,
            inst,
            ab,
            sec,
            pf,
            rep,
            flags,
        ) = r
        comp = ab + sec + pf + rep
        risk_cls = "risk-hi" if comp >= 13 else ("risk-md" if comp >= 10 else "")
        rows.append(
            f"""<tr class="{risk_cls}"><td>{esc(site)}</td>
<td class="pname"><strong>{esc(name)}</strong></td><td>{esc(ver)}</td><td>{badge(status)}</td>
{score_cell(ab)}{score_cell(sec)}{score_cell(pf)}{score_cell(rep)}
<td class="num comp"><strong>{comp}</strong></td><td class="notes">{esc(flags)}</td></tr>"""
        )
    return f"""<table class="wp-list-table widefat fixed striped">
<thead><tr><th class="w-site">Site</th><th class="w-name">Plugin</th><th class="w-ver">Version</th><th class="w-st">Status</th>
<th class="w-s" title="Abandonment risk">Aband.</th><th class="w-s" title="Security risk">Security</th><th class="w-s" title="Performance impact">Perf.</th><th class="w-s" title="How easily removed/replaced">Replace.</th><th class="w-s">Composite</th><th>Rationale</th></tr></thead>
<tbody>{''.join(rows)}</tbody></table>"""


HTML_HEAD = """<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Plugin Stack Audit — Client A &amp; Client B</title>
<style>
:root{--wpblue:#2271b1;--dark:#1d2327;--gray:#646970;--bg:#f0f0f1;--card:#fff;--bord:#c3c4c7;
--red:#d63638;--amber:#dba617;--green:#00a32a;}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--dark);font:13px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif}
#adminbar{background:#1d2327;color:#c3c4c7;height:32px;display:flex;align-items:center;padding:0 16px;font-size:13px;position:sticky;top:0;z-index:50}
#adminbar .wplogo{width:20px;height:20px;border-radius:50%;background:#c3c4c7;color:#1d2327;display:inline-flex;align-items:center;justify-content:center;font-weight:700;margin-right:10px;font-size:12px}
#adminbar .sep{margin:0 12px;opacity:.4}
#layout{display:flex;min-height:calc(100vh - 32px)}
#sidemenu{width:160px;background:#1d2327;color:#c3c4c7;flex-shrink:0;padding-top:8px}
#sidemenu div{padding:8px 12px;font-size:13px}
#sidemenu .on{background:var(--wpblue);color:#fff;font-weight:600}
#sidemenu .dim{opacity:.55}
#main{flex:1;padding:20px 24px;max-width:1280px}
h1{font-size:23px;font-weight:400;margin:0 0 4px}
h2{font-size:18px;font-weight:600;margin:28px 0 10px}
h3{font-size:14px;font-weight:600;margin:18px 0 8px}
.sub{color:var(--gray);margin-bottom:16px}
.notice{background:#fff;border:1px solid var(--bord);border-left:4px solid var(--wpblue);padding:10px 14px;margin:12px 0;box-shadow:0 1px 1px rgba(0,0,0,.04)}
.notice.err{border-left-color:var(--red)}
.notice.warn{border-left-color:var(--amber)}
.notice.ok{border-left-color:var(--green)}
.tag{display:inline-block;font-size:10px;font-weight:700;letter-spacing:.4px;padding:2px 7px;border-radius:3px;vertical-align:middle;margin-left:6px}
.tag.auto{background:#e6f4ea;color:#0a6b2d;border:1px solid #a8dab5}
.tag.part{background:#fef7e0;color:#8a6116;border:1px solid #f2d675}
.tag.human{background:#fce8e8;color:#8f2424;border:1px solid #f0b8b8}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0}
.tile{background:var(--card);border:1px solid var(--bord);padding:14px 16px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
.tile .n{font-size:26px;font-weight:600;line-height:1.1}
.tile .l{color:var(--gray);font-size:12px;margin-top:4px}
.tile.bad .n{color:var(--red)} .tile.warn .n{color:#996800} .tile.good .n{color:var(--green)}
.nav-tab-wrapper{border-bottom:1px solid var(--bord);margin:18px 0 0;padding:0}
.nav-tab{display:inline-block;padding:8px 14px;margin:0 4px -1px 0;border:1px solid var(--bord);border-bottom:none;background:#dcdcde;color:#50575e;cursor:pointer;font-size:13px;border-radius:3px 3px 0 0;font-family:inherit}
.nav-tab:focus-visible{outline:2px solid var(--wpblue);outline-offset:-2px}
.nav-tab-active{background:var(--bg);color:#000;border-bottom:1px solid var(--bg);font-weight:600}
.tabpane{display:none;padding-top:16px}
.tabpane.on{display:block}
table.wp-list-table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--bord);box-shadow:0 1px 1px rgba(0,0,0,.04);margin:10px 0 22px}
.wp-list-table th{background:#fff;text-align:left;padding:8px 10px;border-bottom:1px solid var(--bord);font-weight:600;font-size:12px}
.wp-list-table td{padding:7px 10px;border-bottom:1px solid #f0f0f1;vertical-align:top}
.wp-list-table tbody tr:nth-child(odd){background:#f6f7f7}
tr.risk-hi td{background:#fcf0f1 !important}
tr.risk-md td{background:#fcf9e8 !important}
.badge{display:inline-block;font-size:11px;padding:1px 8px;border-radius:9px;font-weight:600}
.b-act{background:#e6f4ea;color:#0a6b2d}.b-ina{background:#f0f0f1;color:#646970}.b-mu{background:#e5effa;color:#135e96}
.t-free{background:#e5effa;color:#135e96}.t-prem{background:#f3e8fd;color:#6b2fa0}.t-cust{background:#fef1e1;color:#96520c}
.cat{font-size:11px;color:var(--gray)}
.notes{font-size:12px;color:#3c434a}
.num{text-align:right;font-variant-numeric:tabular-nums}
.na{color:#996800;font-size:12px;font-style:italic}
td.sc{text-align:center;font-weight:700;width:52px}
td.s1{color:#00701c}td.s2{color:#3f6321}td.s3{color:#8a5d00}td.s4{color:#9c3f00}td.s5{color:#a02222}
.w-name{width:220px}.w-ver{width:70px}.w-st{width:78px}.w-ty{width:78px}.w-upd{width:120px}.w-tst{width:70px}.w-in{width:85px}.w-cmp{width:48px}.w-s{width:66px}.w-site{width:70px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media(max-width:900px){.grid2{grid-template-columns:1fr}#sidemenu{display:none}}
.bar{background:#e2e4e7;border-radius:3px;height:18px;position:relative;margin:3px 0 10px;max-width:520px}
.bar>.fill{display:block;height:100%;border-radius:3px;background:var(--wpblue)}
.bar.red>.fill{background:var(--red)} .bar.amber>.fill{background:var(--amber)}
.bar .lbl{position:absolute;left:8px;top:0;font-size:11px;line-height:18px;color:#fff;font-weight:600;white-space:nowrap;text-shadow:0 0 3px rgba(0,0,0,.4)}
ul.tight{margin:6px 0 14px 20px;padding:0} ul.tight li{margin:5px 0}
.small{font-size:12px;color:var(--gray)}
code{background:#f0f0f1;border:1px solid #dcdcde;padding:0 4px;border-radius:3px;font-size:12px}
.talk li{margin:10px 0;font-size:14px}
.footer{margin:30px 0 10px;color:var(--gray);font-size:11px;border-top:1px solid var(--bord);padding-top:10px}
</style></head><body>
<div id="adminbar"><span class="wplogo">W</span> Site Admin <span class="sep">|</span> Plugin Stack Audit <span style="margin-left:auto">audit run: 2026-08-05</span></div>
<div id="layout">
<div id="sidemenu"><div class="dim">Dashboard</div><div class="dim">Posts</div><div class="dim">Media</div><div class="dim">Pages</div><div class="on">Plugin Audit</div><div class="dim">Plugins</div><div class="dim">Users</div><div class="dim">Tools</div><div class="dim">Settings</div></div>
<div id="main">
<h1>Plugin Stack Audit</h1>
<div class="sub">Two production WordPress sites, anonymized as <strong>Client A</strong> (full live access: WP-CLI against production) and <strong>Client B</strong> (codebase + database dump). Every check is tagged
<span class="tag auto">AUTOMATABLE</span> = a WordPress plugin inside wp-admin can do it programmatically,
<span class="tag part">PARTIAL</span> = plugin gathers evidence but needs human review,
<span class="tag human">HUMAN-JUDGMENT</span> = requires site/team/business context.</div>

<div class="notice err"><strong>Flagged during audit (not for the talk):</strong> Client A runs an active white-label plugin at a version affected by two CVSS 9.8 unauthenticated account-takeover CVEs, one exploited in the wild — update or remove before anything else. Client B's form plugin is 7 releases behind known fixes, and its brute-force-protection plugin is installed but deactivated.</div>

<div class="nav-tab-wrapper" id="tabs" role="tablist" aria-label="Audit sections">
<button class="nav-tab nav-tab-active" id="tab-overview" role="tab" aria-selected="true" aria-controls="p-overview" tabindex="0" data-t="overview">Overview</button>
<button class="nav-tab" id="tab-inventory" role="tab" aria-selected="false" aria-controls="p-inventory" tabindex="-1" data-t="inventory">Inventory</button>
<button class="nav-tab" id="tab-risk" role="tab" aria-selected="false" aria-controls="p-risk" tabindex="-1" data-t="risk">Risk Matrix</button>
<button class="nav-tab" id="tab-overlap" role="tab" aria-selected="false" aria-controls="p-overlap" tabindex="-1" data-t="overlap">Overlap &amp; Consolidation</button>
<button class="nav-tab" id="tab-perf" role="tab" aria-selected="false" aria-controls="p-perf" tabindex="-1" data-t="perf">Performance</button>
<button class="nav-tab" id="tab-feature" role="tab" aria-selected="false" aria-controls="p-feature" tabindex="-1" data-t="feature">Plugin Feature Map</button>
</div>
"""

overview = """
<div class="tabpane on" id="p-overview">
<h2>Summary dashboard</h2>
<div class="grid2">
<div>
<h3>Client A <span class="small">(live production, WP 7.0.2, managed host)</span></h3>
<div class="tiles">
<div class="tile"><div class="n">53</div><div class="l">installed plugins (+1 must-use, 1 drop-in)</div></div>
<div class="tile"><div class="n">43 / 10</div><div class="l">active / inactive</div></div>
<div class="tile bad"><div class="n">13</div><div class="l">high-risk (composite ≥ 13)</div></div>
<div class="tile warn"><div class="n">15</div><div class="l">premium (licensed)</div></div>
<div class="tile warn"><div class="n">11</div><div class="l">custom / not on wp.org</div></div>
<div class="tile"><div class="n">8</div><div class="l">overlap groups</div></div>
<div class="tile good"><div class="n">53 → 30</div><div class="l">consolidation target (−43%)</div></div>
</div>
</div>
<div>
<h3>Client B <span class="small">(repo + DB dump of 2026-02, WP 6.9)</span></h3>
<div class="tiles">
<div class="tile"><div class="n">47</div><div class="l">installed plugins (+2 must-use, 3 drop-ins)</div></div>
<div class="tile"><div class="n">37 / 10</div><div class="l">active / inactive</div></div>
<div class="tile bad"><div class="n">7</div><div class="l">high-risk (composite ≥ 13)</div></div>
<div class="tile warn"><div class="n">24</div><div class="l">custom / not on wp.org</div></div>
<div class="tile"><div class="n">10</div><div class="l">overlap groups</div></div>
<div class="tile bad"><div class="n">62%</div><div class="l">of autoloaded bytes from UNINSTALLED plugins</div></div>
<div class="tile good"><div class="n">47 → 22</div><div class="l">consolidation target (−53%)</div></div>
</div>
</div>
</div>

<h2>Presentation-ready findings <span class="small">(the 7 slides)</span></h2>
<ol class="talk">
<li><strong>100 plugins across two sites; roughly 3 in 10 do nothing useful.</strong> 20 are deactivated but still deployed, and at least 9 <em>active</em> plugins are dead weight: a product discontinued in 2019, two closed on wordpress.org, a "temporary" fix from 2019, and a helper whose target plugin was uninstalled long ago. <span class="tag auto">AUTOMATABLE</span></li>
<li><strong>62% of Client B's autoloaded option bytes (~231 of 379 KB) belong to plugins that are no longer installed.</strong> Eleven uninstalled plugins left data behind — the single largest autoloaded option on the site (145 KB, loaded on every request) is from a plugin that's gone. <span class="tag auto">AUTOMATABLE</span></li>
<li><strong>Uninstalling doesn't clean up: Client A's production cron still fires hooks for two security plugins removed long ago</strong> — and three <em>deactivated</em> plugins still have scheduled events and autoloaded settings. <span class="tag auto">AUTOMATABLE</span></li>
<li><strong>Client B runs 24 custom plugins — 21 are single-file, single-author utilities.</strong> Fifteen could be one version-controlled site plugin; one of them executes arbitrary PHP from menu items. <span class="tag part">PARTIAL</span></li>
<li><strong>Client A's legacy agency plugins ship 800–1,900 PHP files each</strong> — composer dev-dependencies, PHPUnit included, deployed to production inside one-function plugins (19 MB for a directory plugin). <span class="tag auto">AUTOMATABLE</span></li>
<li><strong>Caching archaeology: Client B carries the footprints of five caching systems</strong> (two active, plus W3TC, LiteSpeed and Docket Cache remnants) on top of CDN edge caching. Nobody decided who owns caching. <span class="tag part">PARTIAL</span></li>
<li><strong>On audit day, 5 active plugin installs across the two sites had known CVEs at the installed version</strong> — including one CVSS 9.8 account-takeover exploited in the wild. Version cross-referencing found every one of them in minutes. The verdicts, though — keep, replace, migrate, rewrite — needed human context every single time. <span class="tag part">PARTIAL</span></li>
</ol>

<div class="notice"><strong>The thesis for the talk:</strong> everything that made this audit <em>fast</em> was automatable; everything that made it <em>right</em> was human. The plugin gathers evidence — inventory, staleness, residue, overlap candidates, CVE matches. A person who knows the site turns evidence into decisions.</div>
</div>
"""

inventory = f"""
<div class="tabpane" id="p-inventory">
<h2>Client A — plugin inventory <span class="tag auto">AUTOMATABLE</span></h2>
<div class="small">Source: <code>wp plugin list</code> on production (2026-08-05) + wordpress.org API cross-reference. Custom/agency plugin names anonymized. Risk = composite of the four 1–5 scores (see Risk Matrix).</div>
{inv_table(A,'a')}
<div class="notice"><strong>Must-use &amp; drop-ins:</strong> 1 mu-plugin (managed-host platform), 1 drop-in (<code>advanced-cache.php</code>, WP Rocket). <strong>Update posture:</strong> auto-updates off for all 53; one plugin 7 releases behind with an update pending.</div>

<h2>Client B — plugin inventory <span class="tag auto">AUTOMATABLE</span></h2>
<div class="small">Source: repo scan of <code>wp-content/plugins/</code> + <code>active_plugins</code> parsed from the database dump (2026-02) + wordpress.org API. Active-theme data in the dump predates the in-progress redesign.</div>
{inv_table(B,'b')}
<div class="notice"><strong>Must-use &amp; drop-ins:</strong> 2 mu-plugins (backup glue, builder safe-mode), 3 drop-ins (<code>advanced-cache.php</code>, <code>object-cache.php</code>, <code>maintenance.php</code>). A deployment archive-extractor script was also found in the webroot — delete it.</div>
</div>
"""

risk_pane = f"""
<div class="tabpane" id="p-risk">
<h2>Risk matrix <span class="tag auto">AUTOMATABLE</span> <span class="small">scoring</span> <span class="tag human">HUMAN-JUDGMENT</span> <span class="small">weighting &amp; verdicts</span></h2>
<div class="small">Each plugin scored 1–5 (5 = worst) on <strong>abandonment risk</strong> (last update, tested-to, install base, vendor status), <strong>security risk</strong> (CVEs at installed version, attack surface: auth/users/code-execution), <strong>performance impact</strong> (front-end assets, per-request work, autoload/cron footprint), and <strong>replaceability</strong> (5 = trivially removable). Composite = sum, sorted descending. Rows ≥13 highlighted red, 10–12 amber.</div>
{risk_table()}
</div>
"""

overlap_pane = """
<div class="tabpane" id="p-overlap">
<h2>Overlap analysis <span class="tag part">PARTIAL</span></h2>
<div class="small">Category grouping and hook/asset evidence are automatable; deciding what is truly redundant is not.</div>

<h3>Client A — 8 overlap groups</h3>
<table class="wp-list-table widefat striped">
<thead><tr><th style="width:190px">Category</th><th>Current state</th><th style="width:320px">Recommended end state</th></tr></thead><tbody>
<tr><td><strong>Page builder (SiteOrigin)</strong></td><td>6 plugins: panels, widgets bundle, premium, an addon pack abandoned 7 years, a widget pack <em>closed on wp.org pending security review</em>, one custom glue plugin</td><td>3 now (drop abandoned + closed packs after usage audit); long-term migrate layouts to core blocks</td></tr>
<tr><td><strong>Admin branding / white-label</strong></td><td>3 plugins: admin theme (2 majors behind), white-label suite (critical CVE), admin-menu editor</td><td><strong>0 plugins</strong> — ~50 lines of login/admin branding code in the site plugin</td></tr>
<tr><td><strong>Custom code injection</strong></td><td>3: code-snippets, a retired CSS/JS-pro (inactive, already migrated to child theme), legacy per-page header/footer injector</td><td>1 (code snippets) → eventually version-controlled site plugin only</td></tr>
<tr><td><strong>Shortcode libraries</strong></td><td>3: Shortcodes Ultimate + paid maker addon + legacy agency shortcodes (145+ content references lock these in)</td><td>1 after a content-migration project; this is the hardest removal on the site</td></tr>
<tr><td><strong>Popups / lead-gen</strong></td><td>2: current popup plugin (2 embeds used) + its discontinued-in-2019 predecessor still installed</td><td>0–1: delete the 2019 relic now; migrate 2 embeds, then retire vendor stack</td></tr>
<tr><td><strong>Users / auth</strong></td><td>5: SSO, 2FA (policy unenforced), an 11-year-old multiple-accounts hack, an inactive multisite activation tool, a role editor</td><td>2 (SSO + 2FA with a real policy); roles as code; delete the rest</td></tr>
<tr><td><strong>Backup / migration</strong></td><td>2 inactive (migration + search-replace pro) — host already provides backups; WP-CLI provides search-replace</td><td>0 plugins</td></tr>
<tr><td><strong>Lightbox / carousel</strong></td><td>3: lightbox plugin sitewide, inactive carousel-pro (cron + options still resident), carousels inside SU and widget bundles</td><td>Core lightbox (WP 6.4+) + one carousel source</td></tr>
</tbody></table>

<h3>Client B — 10 overlap groups</h3>
<table class="wp-list-table widefat striped">
<thead><tr><th style="width:190px">Category</th><th>Current state</th><th style="width:320px">Recommended end state</th></tr></thead><tbody>
<tr><td><strong>Caching / CDN</strong></td><td>2 active (page cache + Redis object cache) + CDN edge control plugin + remnants of W3TC, LiteSpeed and Docket Cache + 3 drop-ins</td><td>Redis + <strong>one</strong> declared page-cache owner (edge or local, not both); purge remnants</td></tr>
<tr><td><strong>Backups</strong></td><td>2: vendor-distributed backup suite (active, own 6 DB tables) + inactive second backup plugin; extractor script in webroot</td><td>1; delete webroot extractor</td></tr>
<tr><td><strong>Redirects</strong></td><td>2: Redirection plugin + custom single-file category-redirect parsing category descriptions</td><td>1 — move rules into Redirection</td></tr>
<tr><td><strong>Accordions</strong></td><td>3 ways: lightweight-accordion, Shortcodes Ultimate accordions, core Details block</td><td>Core Details block (WP 6.3+)</td></tr>
<tr><td><strong>SEO / schema / breadcrumbs</strong></td><td>Yoast + custom schema plugin that disables Yoast schema + custom breadcrumbs + uninstalled-SEO-plugin options in DB</td><td>Yoast only, one schema owner</td></tr>
<tr><td><strong>Forms</strong></td><td>2 systems: Forminator (7 releases behind) + Elementor Pro forms</td><td>1 system</td></tr>
<tr><td><strong>Maps</strong></td><td>3 generations: Gmap block + uninstalled maps plugin's options + design-system map components (redesign)</td><td>1 (design-system component)</td></tr>
<tr><td><strong>Search UX</strong></td><td>3 custom single-file plugins: content-hub search + results + mobile search popup, plus a third-party search SaaS embed</td><td>Fold all into the site core plugin</td></tr>
<tr><td><strong>Custom plugin sprawl</strong></td><td>21 single-file, single-author plugins doing theme-glue jobs</td><td>15 of them → one version-controlled site core plugin; retire the rest</td></tr>
<tr><td><strong>Dev tools in production</strong></td><td>5: hook profiler, template display, WP-CLI login server (<em>active</em>), empty plugin folder, staging tool</td><td>0 in production</td></tr>
</tbody></table>

<h2>Consolidation roadmap <span class="tag human">HUMAN-JUDGMENT</span></h2>
<div class="grid2">
<div>
<h3>Client A: 53 → 30 <span class="small">(−23, −43%)</span></h3>
<ul class="tight">
<li><strong>Delete now (10):</strong> the 10 inactive plugins — incl. the discontinued 2019 popup product, retired CSS/JS tool (migration already done), both backup/migration tools, spam filter for disabled comments, importer, novelty plugin. <em>Prerequisite: none.</em></li>
<li><strong>Replace with ~50–100 lines of code (7):</strong> admin theme + white-label suite + admin-menu editor (one branding module), multiple-accounts hack, title toggle (closed on wp.org), robots.txt editor, "temporary" hide-title fix from 2019.</li>
<li><strong>Audit usage, then drop (3):</strong> abandoned SiteOrigin addon pack, closed Livemesh widgets, premium license used for one addon.</li>
<li><strong>Migration projects (3):</strong> accordion content (40 records), 53 custom shortcodes / 145+ content refs, popup embeds — then their plugins + vendor dashboard retire.</li>
<li><strong>Functionality now in WP core:</strong> image lightbox (6.4+), accordions via Details block (6.3+), duplicate via block editor patterns.</li>
</ul>
</div>
<div>
<h3>Client B: 47 → 22 <span class="small">(−25, −53%)</span></h3>
<ul class="tight">
<li><strong>Delete now (12):</strong> 8 inactive (second backup, staging tool, profiler, template display, importer, novelty, empty folder, deactivated brute-force plugin — decision needed) + 4 active-but-dead: events-helper for an uninstalled plugin, one-time https rewriter (run search-replace once), category-redirect (→ Redirection), CLI login server (deactivate).</li>
<li><strong>Fold 15 single-file customs → 1 site core plugin:</strong> search ×3, CTA, breadcrumbs, schema, TOC, posts-in-cat, tel-click, theme-selector, block-api, custom-query, frameworkdeps, phpengine (rewrite safely), notification bar.</li>
<li><strong>Consolidate systems:</strong> one form system, one accordion (core block), one schema owner, one caching owner.</li>
<li><strong>DB hygiene:</strong> purge ~231 KB of autoloaded options from 11 uninstalled plugins; drop orphaned tables/remnant configs.</li>
</ul>
</div>
</div>
<div class="notice warn"><strong>Sequencing is human work:</strong> every "delete now" above was validated against content dependencies (shortcode/meta usage counts), business function, and team ownership. The audit plugin's job is to surface the dependency counts <em>before</em> someone hits Deactivate.</div>
</div>
"""

perf_pane = """
<div class="tabpane" id="p-perf">
<h2>Performance impact</h2>

<h3>Client A — measured live via WP-CLI <span class="tag auto">AUTOMATABLE</span></h3>
<div class="tiles">
<div class="tile good"><div class="n">224 KB</div><div class="l">autoloaded options (536 options total — healthy)</div></div>
<div class="tile"><div class="n">2,423 / 4,790</div><div class="l">hook tags / registered callbacks</div></div>
<div class="tile good"><div class="n">71</div><div class="l">transient rows</div></div>
<div class="tile warn"><div class="n">40+</div><div class="l">scheduled cron events</div></div>
</div>
<h3 style="margin-top:6px">Top autoloaded options <span class="small">(bytes, every request)</span></h3>
<div class="bar"><span style="width:100%"></span><span class="lbl">widget_siteorigin-panels-builder — 49,094</span></div>
<div class="bar amber"><span style="width:87%"></span><span class="lbl">fs_accounts (Freemius SDK, 3 plugins bundle it) — 42,881</span></div>
<div class="bar"><span style="width:45%"></span><span class="lbl">_transient_wp_core_block_css_files — 22,266</span></div>
<div class="bar"><span style="width:43%"></span><span class="lbl">wp_user_roles — 21,170</span></div>
<div class="bar"><span style="width:42%"></span><span class="lbl">rewrite_rules — 20,515</span></div>
<ul class="tight">
<li><strong>Residue from inactive/uninstalled plugins</strong> <span class="tag auto">AUTOMATABLE</span>: cron events for two long-uninstalled security plugins (7 recurring hooks); weekly crons from two <em>deactivated</em> plugins (accordion, carousel); settings of the inactive carousel still autoloading; a daily update-check cron from a paid addon.</li>
<li><strong>Front-end asset pressure</strong> <span class="tag auto">AUTOMATABLE</span>: page-builder stack (premium addon pack alone registers 74 script enqueues, widget bundle 54), lightbox JS sitewide, translation widget. Mitigated today by aggressive page caching — the stack cost is paid on every cache miss.</li>
<li><strong>External HTTP callers in code</strong> <span class="tag part">PARTIAL</span>: caching plugin (49 wp_remote_* call sites — SaaS features), mail SMTP (27), popup marketing (28), vendor dashboard (15). Static counts locate the risk; runtime tracing confirms actual page-load calls.</li>
<li><strong>Shipped weight</strong> <span class="tag auto">AUTOMATABLE</span>: legacy agency plugins bundle composer dev-dependencies (PHPUnit et al.) — 800–1,900 PHP files per plugin, 19 MB for one, for single-function fixes.</li>
</ul>

<h3>Client B — static analysis + DB dump <span class="tag auto">AUTOMATABLE</span> <span class="small">(runtime numbers require live access)</span></h3>
<div class="tiles">
<div class="tile bad"><div class="n">379 KB</div><div class="l">autoloaded bytes (661 options)</div></div>
<div class="tile bad"><div class="n">~62%</div><div class="l">of autoloaded bytes from 11 UNINSTALLED plugins</div></div>
<div class="tile warn"><div class="n">3</div><div class="l">cache drop-ins + 3 dead cache configs</div></div>
<div class="tile"><div class="n">119 KB</div><div class="l">transient bytes (7 rows)</div></div>
</div>
<h3 style="margin-top:6px">Largest autoloaded options <span class="small">(red = source plugin no longer installed)</span></h3>
<div class="bar red"><span style="width:100%"></span><span class="lbl">addon_library_catalog — 144,935 (uninstalled)</span></div>
<div class="bar"><span style="width:23%"></span><span class="lbl">widget_text — 33,334</span></div>
<div class="bar red"><span style="width:20%"></span><span class="lbl">option_optimizer — 28,897 (uninstalled)</span></div>
<div class="bar"><span style="width:14%"></span><span class="lbl">user_roles — 20,217</span></div>
<div class="bar red"><span style="width:8%"></span><span class="lbl">megamenu_themes — 11,245 (uninstalled)</span></div>
<div class="bar red"><span style="width:6%"></span><span class="lbl">quadmenu / maps / events / SEO remnants — ~40 KB (uninstalled)</span></div>
<ul class="tight">
<li><strong>Every-page-load work in custom plugins</strong> <span class="tag auto">AUTOMATABLE</span>: a "framework dependencies" plugin enqueues 12 scripts + 6 styles sitewide; one plugin regex-rewrites phone numbers in content on every render; another rewrites http→https per request (a one-time DB fix).</li>
<li><strong>Custom DB tables</strong> <span class="tag auto">AUTOMATABLE</span>: backup suite owns 6 <code>ak_*</code> tables + secret-key file; second (inactive) backup plugin adds its own table.</li>
<li><strong>Builder platform weight</strong> <span class="tag part">PARTIAL</span>: Elementor + Pro + Forminator together ship ~4,000 PHP files and 200+ enqueue call sites; actual page cost depends on which widgets each page uses — needs runtime profiling.</li>
</ul>
</div>
"""

feature_pane = """
<div class="tabpane" id="p-feature">
<h2>Plugin feature map — what the audit plugin should do</h2>
<div class="small">Every check exercised in this audit, tagged for the attendee-takeaway plugin. P0 = MVP, P1 = V2, P2 = future, Human = out of scope for software.</div>
<table class="wp-list-table widefat striped">
<thead><tr><th style="width:250px">Check</th><th style="width:110px">Automatable?</th><th>Method</th><th style="width:90px">Priority</th><th style="width:260px">Notes / evidence from this audit</th></tr></thead>
<tbody>
<tr><td>Plugin inventory (name, version, status)</td><td><span class="tag auto">YES</span></td><td><code>get_plugins()</code>, <code>get_option('active_plugins')</code>, <code>get_mu_plugins()</code>, <code>get_dropins()</code></td><td><strong>P0</strong></td><td>Foundation. Don't forget mu-plugins and drop-ins — Client B had 3 drop-ins and cache remnants invisible to the Plugins screen.</td></tr>
<tr><td>wordpress.org cross-reference</td><td><span class="tag auto">YES</span></td><td><code>plugins_api()</code>: last_updated, tested, active_installs, <strong>closed status</strong></td><td><strong>P0</strong></td><td>Caught 2 closed plugins (one closed <em>pending security review</em>) and 3 plugins 7–11 years stale.</td></tr>
<tr><td>Abandonment risk scoring</td><td><span class="tag auto">YES</span></td><td>Last-updated age, tested-to gap vs current WP, install-count thresholds, not-on-wp.org flag</td><td><strong>P0</strong></td><td>Pure arithmetic once the API data is cached.</td></tr>
<tr><td>Autoloaded-options audit</td><td><span class="tag auto">YES</span></td><td>Direct <code>$wpdb</code> query; size totals + top offenders</td><td><strong>P0</strong></td><td>High impact, ~20 lines of SQL. Found the 145 KB orphan on Client B.</td></tr>
<tr><td><strong>Orphaned data detection (options)</strong></td><td><span class="tag auto">YES*</span></td><td>Prefix map: option prefixes ↔ installed plugin slugs; flag autoloaded options with no living owner</td><td><strong>P0–P1</strong></td><td>*Heuristic — needs a curated prefix dictionary. The single best stat this audit produced (62%).</td></tr>
<tr><td><strong>Orphaned cron detection</strong></td><td><span class="tag auto">YES*</span></td><td><code>_get_cron_array()</code> hook prefixes vs installed/active plugins</td><td><strong>P1</strong></td><td>Client A: 7 recurring hooks from uninstalled security plugins; crons from deactivated plugins still firing.</td></tr>
<tr><td>Inactive-plugin residue report</td><td><span class="tag auto">YES</span></td><td>Join inactive list × options × cron × custom tables</td><td><strong>P1</strong></td><td>"Deactivated ≠ gone" is a headline finding on both sites.</td></tr>
<tr><td>Update-lag measurement</td><td><span class="tag auto">YES</span></td><td>Installed version vs wp.org version; releases-behind count via changelog/SVN tags</td><td><strong>P1</strong></td><td>Client A: admin plugin 7 releases behind; B: forms plugin 7 releases behind known security fixes.</td></tr>
<tr><td>Functional category tagging</td><td><span class="tag part">PARTIAL</span></td><td>Keyword heuristics on headers/readme + manual override UI</td><td><strong>P1</strong></td><td>Heuristics mislabel custom plugins (24 of B's 47 have no readme). Ship with override.</td></tr>
<tr><td>Overlap detection</td><td><span class="tag part">PARTIAL</span></td><td>Category grouping + shared-hook analysis (<code>wp_head</code>, <code>the_content</code>, REST routes)</td><td><strong>P1</strong></td><td>Flags candidate groups (18 across both sites); humans decided only ~70% were truly redundant.</td></tr>
<tr><td>Security risk flags</td><td><span class="tag part">PARTIAL</span></td><td>tested-to gap + code scan for auth/user-creation/payment surface (<code>wp_insert_user</code>, <code>add_role</code>, payment SDKs)</td><td><strong>P1</strong></td><td>Static scan located every auth-touching plugin (7 on A, 4 on B) incl. an arbitrary-PHP-execution custom plugin.</td></tr>
<tr><td>CVE / vulnerability lookup</td><td><span class="tag part">PARTIAL</span></td><td>External API (WPScan/Patchstack) — optional, keyed, or manual cross-reference</td><td><strong>P2</strong></td><td>Free tier is rate-limited; in this audit web research confirmed 5 live CVE matches. Ship as optional integration.</td></tr>
<tr><td>Front-end asset audit</td><td><span class="tag auto">YES</span></td><td>Hook <code>wp_enqueue_scripts</code> on a sampled front-end request; attribute handles → plugin dirs</td><td><strong>P1</strong></td><td>Static enqueue counts (this audit) locate suspects; runtime sampling confirms.</td></tr>
<tr><td>External HTTP call monitoring</td><td><span class="tag part">PARTIAL</span></td><td>Hook <code>pre_http_request</code> to log runtime calls; static grep for <code>wp_remote_*</code>/<code>curl_init</code></td><td><strong>P2</strong></td><td>Static counts overcount (admin-only calls); runtime undercounts (conditional paths). Present both.</td></tr>
<tr><td>Bundled dev-dependency / library scan</td><td><span class="tag auto">YES</span></td><td>Scan plugin dirs for <code>vendor/</code>, <code>node_modules/</code>, phpunit, duplicated jquery/select2/moment/chart.js files</td><td><strong>P1</strong></td><td>Found PHPUnit in production ×6 plugins (A) and 5 plugins bundling their own select2/moment/chart.js.</td></tr>
<tr><td>Dev-tools-in-production flag</td><td><span class="tag auto">YES</span></td><td>Known-slug list (profilers, CLI helpers, template debuggers) + "empty plugin dir" check</td><td><strong>P1</strong></td><td>Client B: 5 hits incl. an <em>active</em> magic-login-link server.</td></tr>
<tr><td><strong>Content lock-in scan (pre-removal)</strong></td><td><span class="tag auto">YES</span></td><td>Search post_content/postmeta for each plugin's shortcodes/blocks/meta keys; count references</td><td><strong>P1</strong></td><td>The check that prevents disasters: 145+ shortcode meta rows (A) meant "cannot remove yet." Surface counts <em>before</em> the Deactivate button.</td></tr>
<tr><td>Single-vendor / single-author concentration</td><td><span class="tag auto">YES</span></td><td>Group by Author header; report concentration</td><td><strong>P2</strong></td><td>Metric is automatic (21 plugins, 1 author on B); what to do about it is not.</td></tr>
<tr><td>Vendor acquisition / sunset tracking</td><td><span class="tag human">NO</span></td><td>News, changelogs, community knowledge</td><td>Human</td><td>PopUp product retired 2019; agency vendor acquired twice; only humans (or curated feeds) know.</td></tr>
<tr><td>Repo ↔ production drift</td><td><span class="tag human">NO†</span></td><td>Needs VCS access — companion CI script, not an in-admin plugin</td><td>Human/CI</td><td>†Automatable in CI, not in wp-admin. Client A's repo lagged prod by 1–2 versions on 20+ plugins — incl. holding a known-vulnerable SSO version while prod was patched.</td></tr>
<tr><td>Consolidation recommendations</td><td><span class="tag human">NO</span></td><td>Requires knowledge of alternatives, business context, license costs, team skills</td><td>Human</td><td>Every 53→30 / 47→22 decision needed context (e.g. "SSO is business-critical", "redesign replaces this").</td></tr>
<tr><td>Removal sequencing &amp; rollout</td><td><span class="tag human">NO</span></td><td>Content dependencies, stakeholder buy-in, staging validation</td><td>Human</td><td>The plugin surfaces the data; the human makes the call.</td></tr>
</tbody></table>

<h3>Priority tiers</h3>
<ul class="tight">
<li><strong>P0 — Core (MVP):</strong> inventory (incl. mu/drop-ins), wp.org cross-reference + closed detection, abandonment scoring, autoloaded-options audit, orphaned-options heuristic. This alone would have produced the two best slides of this talk.</li>
<li><strong>P1 — V2:</strong> orphaned cron + inactive-residue report, update-lag, category tagging with overrides, overlap candidates, security-surface scan, front-end asset sampling, dev-dependency scan, dev-tools flag, <strong>content lock-in counts</strong>.</li>
<li><strong>P2 — Future:</strong> runtime HTTP monitoring, CVE API integration, vendor-concentration analytics.</li>
<li><strong>Human-only:</strong> consolidation verdicts, sequencing, vendor-news awareness, business context. The plugin is a flashlight, not a judge.</li>
</ul>
</div>
"""

FOOT = """
<div class="footer">Generated 2026-08-05 · Client A data: live WP-CLI against production + codebase + wordpress.org API · Client B data: repository + database dump (2026-02) + wordpress.org API · CVE data: public advisories (WPScan/Patchstack/Wordfence pages), retrieved 2026-08-05 · All client-identifying names anonymized.</div>
</div></div>
<script>
(function(){
 var tabs=[].slice.call(document.querySelectorAll('#tabs .nav-tab'));
 function activate(t,focus){
  tabs.forEach(function(x){
   x.classList.remove('nav-tab-active');
   x.setAttribute('aria-selected','false');
   x.setAttribute('tabindex','-1');
  });
  document.querySelectorAll('.tabpane').forEach(function(x){x.classList.remove('on')});
  t.classList.add('nav-tab-active');
  t.setAttribute('aria-selected','true');
  t.setAttribute('tabindex','0');
  document.getElementById('p-'+t.dataset.t).classList.add('on');
  if(focus)t.focus();
  window.scrollTo(0,0);
 }
 tabs.forEach(function(t,i){
  t.addEventListener('click',function(){activate(t,false)});
  t.addEventListener('keydown',function(e){
   var n=null;
   if(e.key==='ArrowRight'||e.key==='ArrowDown')n=(i+1)%tabs.length;
   else if(e.key==='ArrowLeft'||e.key==='ArrowUp')n=(i-1+tabs.length)%tabs.length;
   else if(e.key==='Home')n=0;
   else if(e.key==='End')n=tabs.length-1;
   if(n!==null){e.preventDefault();activate(tabs[n],true);}
  });
 });
})();
</script></body></html>
"""

out = (
    HTML_HEAD
    + overview
    + inventory
    + risk_pane
    + overlap_pane
    + perf_pane
    + feature_pane
    + FOOT
)
out = out.replace('class="tabpane', 'role="tabpanel" class="tabpane')
out = re.sub(r'id="p-([a-z]+)"', r'id="p-\1" aria-labelledby="tab-\1"', out)
# decorative bar fills: real elements (not empty spans) so validators keep them
out = out.replace(
    '<span style="width:', '<div class="fill" aria-hidden="true" style="width:'
)
out = out.replace('"></span><span class="lbl">', '"></div><span class="lbl">')
with open("../dashboard/plugin-audit-dashboard.html", "w") as f:
    f.write(out)
print("bytes:", len(out))
