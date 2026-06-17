# ExpressionEngine add-on store — listing prep (Addon Expert)

Paste-ready copy for the EE vendor submission form, mirroring the field
layout (Essentials / Add-on Versions / Add-on Icon / Version
Compatibility / Software License / Supporting Assets).

> **Status:** copy is final. Listed as **Free / MIT**. URLs use branded
> `codebit.nl/addon-expert/*` pages — those pages must exist before the
> listing links resolve (see **Before submitting** at the bottom).

---

## Essentials

**Add-on Name**

```
Addon Expert
```

**Add-on Price**

```
0.00
```
(Free — Addon Expert is MIT and developed in the open.)

**Elevator Pitch**

```
Install, update, and track every ExpressionEngine add-on from one set of
control-panel screens — upload ZIPs, watch GitHub releases, update in one
click — with supply-chain checks and a compatibility scan before anything
touches your site.
```

**Full Description** (HTML — the store renders HTML, like the CF Image listing)

```html
<p><strong>Addon Expert</strong> is the add-on that manages your add-ons. Upload a ZIP, watch a GitHub repo for new releases, update in one click, and let it finalize ExpressionEngine's update step for you — all from one set of control-panel screens, without FTP.</p>

<h3>What it does</h3>
<ul>
  <li><strong>Install from ZIP</strong> — drop in a package and it finds the real add-on folder (even nested inside a wrapper folder), validates the paths, and extracts it into place.</li>
  <li><strong>Track GitHub releases</strong> — map any installed add-on to a GitHub repo (or let the author declare it in <code>addon.setup.php</code>) and get a "new version available" badge on the package, plus a count in the sidebar.</li>
  <li><strong>One-click update + auto-finalize</strong> — download the release, swap it in, keep a backup of the old version, and run EE's update step automatically. No trip to Developer &rarr; Add-Ons.</li>
  <li><strong>Supply-chain protection</strong> — pins each repo's GitHub identity on first install and <em>blocks</em> the install if it changes (ownership transfer / RepoJacking), with a full append-only audit log of every install and trust event.</li>
  <li><strong>Compatibility checks</strong> — refuses to install an add-on that needs a newer PHP or EE than your server runs, and when you choose to force it anyway, scans the code first and tells you whether it's actually safe ("no PHP 8.3 features detected" vs "uses <code>json_validate()</code> — will fatal").</li>
</ul>

<h3>Why</h3>
<p>EE add-ons come from everywhere — the store, GitHub, a client's zip in an email. Addon Expert gives you one place to install them, one place to see what's out of date, and one click to update, with guardrails so a compromised upstream repo or a wrong-PHP package can't quietly break your site.</p>

<h3>Open source</h3>
<p>Addon Expert is MIT-licensed and developed in the open. It is a maintained fork of <a href="https://github.com/jfaza/addon-manager-plus">Addon Manager +</a> by Javid Fazaeli, extended with the GitHub release tracking, one-click updates, supply-chain checks, and compatibility tooling above.</p>

<p><em>ExpressionEngine 7. Requires the PHP <code>ZipArchive</code> and <code>cURL</code> extensions.</em></p>
```

---

## Add-on Versions (one row)

| Field | Value |
|-------|-------|
| Version | `2.1.1` |
| Release Date | 2026-06-17 |
| Add-on Zip | `addon_expert-v2.1.1.zip` (see Build below) |
| Changelog | use the text block below |

**Changelog cell text**

```
2.1.1 — Documentation and in-CP help refreshed for the full feature set; fixed a stale cache-interval label on the Releases screen. (2.1.0 added the inspect-before-commit upload flow with one-click force; 2.0.0 was the rename from Addon Manager + with automatic config migration.) See the full history at the Changelog URL.
```

---

## Add-on Icon

The Addon Expert mark — near-black squircle, three teal stacked "add-on"
bars with an update badge, in the Codebit family style. Source is
`icon.svg` (repo root).

**Ready to upload:** `~/Downloads/addon-expert-icon-512.png` (512×512,
transparent corners). Re-render any time with Quick Look:

```bash
qlmanage -t -s 512 -o ~/Downloads icon.svg && mv ~/Downloads/icon.svg.png ~/Downloads/addon-expert-icon-512.png
```

## Version Compatibility

- ✅ **7**  (only — EE 7 is the target; leave 6/5/4/3/2/1 unchecked)

## Software License

- **License:** MIT / Open Source
- **Link:** https://github.com/calimonk/ee-addon-expert/blob/main/LICENSE

---

## Supporting Assets

Branded `codebit.nl/addon-expert/*` pages, matching the CF Image listing
style. **These pages must exist before submitting** (see Before submitting).

| Field | Value |
|-------|-------|
| Add-on Main URL | https://codebit.nl/addon-expert |
| Documentation URL | https://codebit.nl/addon-expert/docs |
| Support URL | https://codebit.nl/addon-expert/support |
| Changelog URL | https://codebit.nl/addon-expert/changelog |
| Featured Image | `docs/screenshots/packages.png` (or `releases.png`) |

---

## Build the submission zip

The GitHub release already hosts an identical package
(`addon_expert-2.1.1.zip`). To produce a `v`-named file matching the store
convention:

```bash
git archive --format=zip --prefix=addon_expert/ \
  -o addon_expert-v2.1.1.zip v2.1.1
```

`.gitattributes` keeps the package lean (~105 KB) — runtime code +
README/LICENSE/CHANGELOG only, no docs/screenshots.

---

## Before submitting

The only thing gating submission is the four `codebit.nl/addon-expert/*`
pages — the store links to them and reviewers will click through:

- `/addon-expert` — overview / landing (mirror the elevator pitch + Full
  Description above; the CF Image landing page is the template).
- `/addon-expert/docs` — can simply restate the in-CP Documentation
  screen, or link to the GitHub README/wiki.
- `/addon-expert/support` — how to get help (GitHub issues link is fine,
  or a contact form like CF Image's).
- `/addon-expert/changelog` — mirror `CHANGELOG.md`.

Quickest honest shortcut if you want to submit now: point Docs →
GitHub README, Support → GitHub issues, Changelog → GitHub CHANGELOG, and
only build the `/addon-expert` landing page. They're branded-ish via a
redirect and all resolve immediately.

Everything else (copy, icon, version, license, zip) is ready.
