# Addon Manager +

**Install and manage ExpressionEngine add-on ZIP packages directly from the EE 7 control panel.**

Author: Javid Fazaeli  
License: MIT  
Version: 1.1.0

---

## Why This Exists

The standard ExpressionEngine add-on installation workflow involves:

1. Download a ZIP from a third-party source.
2. Unzip it locally.
3. Locate the real add-on folder (sometimes nested inside a wrapper folder).
4. Upload that folder to `system/user/addons/` via FTP or SSH.
5. Return to the ExpressionEngine control panel to complete the install.

Addon Manager + keeps more of this workflow inside the control panel: upload the ZIP, and the add-on detects the real folder, extracts it into the correct location, and presents the install button — all without touching FTP.

## Features

- Upload third-party add-on ZIP packages directly from the control panel.
- Detect the real add-on folder automatically by locating `addon.setup.php`, including inside a wrapper folder.
- Extract packages into the active add-ons directory.
- Display installed / not installed / update available status for each package.
- Link back into ExpressionEngine's native install, update, settings, and uninstall flow.
- Reject unsafe ZIP paths, including absolute paths and `..` path traversal.
- Generate package downloads on demand without permanently storing ZIP files.
- Sort not-installed add-ons before installed ones for quick access.
- Show the Settings action only for add-ons that declare a settings page.

## Requirements

- ExpressionEngine 7
- PHP `ZipArchive` extension
- A writable add-ons directory (`system/user/addons/`)
- Control panel access with permission to manage add-ons

## Installation

1. Copy the `addon_installer/` folder into `system/user/addons/`.
2. In ExpressionEngine, open **Developer > Add-Ons**.
3. Find **Addon Manager +** and click **Install**.
4. Click **Settings** next to Addon Manager + to open it.

## Usage

### Uploading a package

1. Go to **Addon Manager + > Install ZIP**.
2. Select a ZIP file and upload it.
3. On success, click **Install** in the notice, or find the add-on on the Packages screen.

### Packages screen

The Packages screen shows all detected packages as responsive cards with status badges:

| Badge | Meaning |
|-------|---------|
| Installed | The add-on is extracted and installed in ExpressionEngine. |
| Not Installed | The add-on is extracted but not yet installed. |
| Update Available | A newer version was uploaded over an existing installed add-on. |

Available actions per card:

- **Install** — available for not-installed add-ons.
- **Update** — available when a newer version has been uploaded.
- **Settings** — available only when the add-on declares a settings page.
- **Download** — generates a ZIP for any detected add-on on demand.
- **Uninstall** — (red) available for installed add-ons.

## Package Format

The ZIP should contain one add-on folder named with the add-on short name:

```text
my_addon/
  addon.setup.php
  upd.my_addon.php
  mcp.my_addon.php
  ...
```

Wrapper folders are allowed:

```text
downloaded-release/
  my_addon/
    addon.setup.php
    upd.my_addon.php
```

Loose add-on files at the ZIP root are rejected because the installer cannot infer the destination folder name. Valid add-on folder names use lowercase letters, numbers, and underscores.

## Security Notes

- ZIP entries with absolute paths or `..` segments are rejected before extraction.
- Only files inside the detected add-on folder are extracted.
- The add-on does not execute or evaluate uploaded PHP files during extraction.
- ExpressionEngine's own permission system controls who can access the control panel module.
- Do not grant control panel access to untrusted users — extracted add-on code runs with the same privileges as any other installed add-on.

## Known Limitations

- Only ZIP archives are supported; `.tar.gz` and other formats are not.
- Addon Manager + does not publish or fetch packages from a remote registry; all packages must be uploaded manually.
- The download feature regenerates ZIPs from the current on-disk files, not from the original uploaded archive.

## Screenshots

### Install ZIP

![Install ZIP screen](docs/screenshots/upload-zip.png)

*Upload screen showing ZIP Support, Add-ons Folder, and Maximum ZIP Size status cards, the file picker, and the overwrite option.*

### Packages

> Coming soon: package cards screen.

### Actions

> Coming soon: update / install / settings actions.

## Tracking GitHub Releases

Addon Manager + can poll GitHub Releases for every installed add-on and surface
a single "updates available" count in the EE CP sidebar — covering EE-store
add-ons (the existing upload flow) AND add-ons distributed on GitHub.

Two resolution layers, in priority order:

1. **Author-declared.** Add `'github_repo' => 'owner/repo'` to the add-on's
   `addon.setup.php`. Opt-in, decentralized, survives admin reinstall.
2. **Admin-mapped.** Open **Addon Manager + → Releases** and fill in
   `owner/repo` for any installed add-on whose author hasn't declared one.
   Persisted to `system/user/config/addon_installer_mappings.json`.

The Releases screen lists every installed add-on with its installed version,
mapped repo, latest release, and last-check timestamp. Click **Check for
updates** to refresh all mapped repos (12h cache, sentinel-on-failure so a
flaky network doesn't hammer GitHub). The Packages screen swaps in a
"GitHub: vX.Y.Z ↗" badge whenever a newer release exists.

### One-click update from GitHub

When a newer release is detected, an orange cloud-download button appears
on the Packages card (and an "Install vX.Y.Z" button on the Releases row).
Clicking it:

1. Re-fetches the latest release from the GitHub API.
2. Picks a download URL — preferring release assets named after the
   add-on (`{short_name}-{version}.zip`), then any `.zip` asset, then
   the auto-generated `zipball_url` (source archive).
3. Streams the archive to a temp file, capped at 100 MB.
4. Extracts into a staging directory next to the add-on, applying the
   same `..` / absolute-path safety filter the upload flow uses.
5. Locates the add-on subtree inside the zip by finding the
   `addon.setup.php` whose parent folder matches `short_name`. Falls
   back to the wrapper root for single-add-on source zipballs.
6. Renames the existing add-on to `.{short_name}.backup.{ts}` (only one
   backup is kept — older backups are removed first).
7. Renames the staging dir into place.
8. Forgets the release cache so the new on-disk version is read fresh.
9. Redirects to EE's native **Update Add-on** screen so any migration
   step is consciously approved by the admin.

If anything fails, the previous version stays in place (or is restored
from backup), and the failure surfaces as a CP banner.

GitHub API calls are unauthenticated (public repos only). The 60-requests/hour
unauthenticated quota per IP is far above any realistic site's add-on count.

### Supply-chain protection

GitHub-distributed add-ons sit in a known attack class: an upstream maintainer
can lose control of their account, sell the repo, or have it deleted and
re-claimed by a malicious actor under the same `owner/repo` name (RepoJacking).
Addon Manager + treats every install path as a supply-chain decision and
pins a **trust anchor** on first use.

- On the first install of a GitHub-mapped add-on, the GitHub-controlled stable
  identifiers — owner numeric ID, repo numeric ID, repo `created_at` — are
  pinned to `system/user/config/addon_installer_trust.json` alongside which
  EE admin pinned them and when.
- Every install attempt re-fetches identity from GitHub **bypassing the cache**
  and compares. Any mismatch hard-blocks the install and surfaces a banner
  listing exactly which fields changed.
- Username renames are *not* an alarm — owner numeric ID is stable across
  renames. Only real ownership transfers and delete+recreate scenarios trip
  the check.
- The Releases screen shows the trust state per add-on (`✓ trusted`,
  `⚠ CHANGED`, `unverified`) and offers a **Reconfirm trust** action for
  legitimate identity changes. Reconfirming is itself audit-logged.
- Every install and trust event writes to
  `system/user/cache/addon_installer/install.log` (JSONL, last 25 events
  shown on the Releases screen). Useful for forensics after the fact.

The same rules apply to Addon Manager +'s own self-update — a hostile
takeover of our own repo would otherwise become a vector via the one-click
flow.

## Roadmap

- Remote package registry / URL install
- Bulk install from a ZIP containing multiple add-ons
- Improved version conflict UI
- Optional authenticated GitHub calls (PAT) for sites that pin private
  forks of add-ons

## Changelog / Releases

See [CHANGELOG.md](CHANGELOG.md) for a full version history.

## Development

Additional project documentation lives in the [wiki/](wiki/) directory:

- [Home](wiki/Home.md)
- [Installation](wiki/Installation.md)
- [Package Format](wiki/Package-Format.md)
- [Package Workflow](wiki/Package-Workflow.md)
- [Security Model](wiki/Security-Model.md)
- [Development](wiki/Development.md)

Run PHP lint after editing PHP files:

```bash
for f in *.php ControlPanel/*.php ControlPanel/Routes/*.php Service/*.php views/*.php; do php -l "$f" || exit 1; done
```

`AGENTS.md` is local development guidance and is intentionally not part of this repository's public documentation.

## GitHub Repository Setup

> Remove this section after completing repo setup.

Set the GitHub **description** to:

> Install and manage ExpressionEngine add-on ZIP packages directly from the EE 7 control panel.

Add these **topics:** `expressionengine` `expressionengine-addon` `ee7` `cms` `php` `addon-manager` `zip-installer` `control-panel` `developer-tools`

## License

MIT. See [LICENSE](LICENSE).
