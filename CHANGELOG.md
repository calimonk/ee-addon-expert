# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [1.3.4] - 2026-06-03

### Changed
- **Custom-menu label is now configurable** and defaults to **"Addons"**
  instead of "Add-on Manager +". EE's Custom sidebar is narrow and the
  previous default truncated to "Add-on Manag..." on most themes.
  Settings → new "Custom-menu label" text field (40 char cap, HTML
  stripped). When pending-update count is enabled and there are pending
  updates, the displayed label becomes e.g. "Addons (3)".

## [1.3.3] - 2026-06-03

### Fixed (docs)
- In-product copy on the Settings screen and the extension docblock
  pointed the admin at the wrong location for adding the Custom-menu
  entry. The actual path is **Settings → Menu Manager**
  (`/cp/settings/menu-manager/`), not "Members → Roles → CP & Tools".
  Corrected.

## [1.3.2] - 2026-06-03

### Fixed
- **Transient 403 after a successful one-click install**. The 1.3.1
  installer correctly downloaded, extracted, swapped, and audit-logged
  the new release, but the post-install `302 → cp/addons` redirect hit
  a 403 on the EE-native Add-Ons listing page in some EE 7 setups. The
  install itself was fine (the new version became visible after a few
  minutes once EE re-cached) but the 403 was a confusing dead-end for
  the admin.

  Now: after a successful install we redirect back to our own Releases
  screen (which we know is authorized — we just rendered the page that
  POSTed) and the success banner contains an explicit link to
  Developer → Add-Ons. The admin clicks it as a normal user-initiated
  GET, no programmatic redirect into a possibly-transient state.

## [1.3.1] - 2026-06-03

### Fixed
- **Self-update of Addon Manager + via the one-click flow left the site
  with a fatal autoloader collision** (`Class "TrustStore" not found` /
  similar). Root cause: the previous installer wrote post-install
  backups to `system/user/addons/.{short}.backup.{ts}/`, which EE's
  PSR-4 discovery walked and registered as a second source for our
  namespace. The 1.2.0 backup then shadowed the 1.3.0 live code in the
  class loader.
- **Post-install redirect to `addons/update/{short}` returned 403**.
  EE 7's update endpoint is POST-only; a GET redirect from inside our
  installer was always going to fail.

### Changed
- **Backups now live in `system/user/cache/addon_installer/backups/{short}/{ts}/`**,
  outside addon discovery. No collision risk regardless of how EE walks
  the addons directory.
- **Post-install redirect now targets `cp/addons`** (EE's native
  Add-Ons list). The admin clicks the **Update** prompt on the affected
  card to finalize — exactly the same flow as a manually-uploaded zip,
  with EE's own POST + CSRF form.
- **Cross-filesystem backup support**: backup dir can now live on a
  different mount than the addons dir. `rename()` is attempted first
  (atomic same-FS); falls back to recursive copy + source removal.
- **Opcache invalidation** runs on every PHP file in the new install
  immediately after the swap. Production setups with high
  `opcache.revalidate_freq` or `validate_timestamps=0` no longer have
  to wait for stale bytecode to expire.
- **Audit log entries gain an `is_self` boolean** so post-incident
  forensics can distinguish self-update failures (historically the
  higher-risk case) from updates of other add-ons.

### Self-healing
- On every install, any leftover `system/user/addons/.{short}.backup.*`
  directories from 1.3.0 are swept up automatically. Sites damaged by
  the 1.3.0 collision recover on the next successful install of any
  GitHub-mapped add-on.

### Manual recovery (one-time, only if currently broken)
If you upgraded to 1.3.0 via the one-click flow and now see
`Class "TrustStore" not found` on any Addon Manager + screen:

```bash
cd /path/to/ee/system/user/addons
rm -rf .addon_installer.backup.*
```

Then **Developer → Add-Ons** → click **Update** on the Add-on Manager +
card to finalize the 1.3.0 install. After that, this 1.3.1 release
installs through the (now-working) one-click flow with no further
intervention.

## [1.3.0] - 2026-06-03

### Added — Supply-chain protection
- **TOFU repo identity pinning.** On first install of a GitHub-mapped
  add-on, the GitHub-controlled stable identifiers (`owner_id`, `repo_id`,
  `created_at`) are pinned to `system/user/config/addon_installer_trust.json`.
  Every subsequent install attempt re-fetches identity from GitHub
  (cache-bypass, always fresh) and compares. A mismatch — consistent with
  ownership transfer or RepoJacking — **hard-blocks the install** with a
  diff banner. Username renames on the same owner do not trigger false
  alarms (owner_id is stable across renames).
- **"Reconfirm trust" action** on the Releases screen for legitimate
  identity changes (real ownership transfer, intentional fork migration).
  Pinning the new identity is itself audit-logged.
- **Install audit log** at `system/user/cache/addon_installer/install.log`
  (JSONL, ~1 MB rotating). Records `install_ok`, `install_failed`,
  `install_blocked`, `trust_pinned`, `trust_reconfirmed_manual` events
  with admin user, repo, version, URL, identity, and reason. Last 25
  entries surface at the bottom of the Releases screen.
- **Self-protection.** The same TOFU rules apply to Addon Manager +'s
  own mapping (`calimonk/ee-addon-manager`). A hostile takeover of our
  own repo would otherwise become a self-pwn vector via one-click
  update.

### Added — CP Custom-menu integration
- New extension class `Addon_installer_ext` registers the `cp_custom_menu`
  hook. When the admin adds Add-on Manager + to a role's Custom menu via
  `Members → Roles → CP & Tools → Menu Manager`, our extension renders
  the label as `Add-on Manager + (N)` when there are pending GitHub
  updates, with a click target the admin can choose.
- New **Settings** screen with toggles:
  - `show_in_custom_menu` (on/off — gates the hook output)
  - `custom_menu_target` (Releases / Packages / Install ZIP)
  - `custom_menu_show_count` (on/off — appends `(N)`)
- `upd.addon_installer.php` registers/removes the extension row on
  install/uninstall, and backfills the row on update so upgrades from
  1.2.x pick up the hook automatically.

### Internal
- New services: `TrustStore`, `InstallAuditor`, `SettingsStore`.
- `GitHubReleaseChecker` learned `repoIdentityCached/Refresh/Latest` for
  the `/repos/{owner}/{repo}` endpoint (7-day TTL on the routine path).
- Sidebar grows a Settings entry.

## [1.2.1] - 2026-06-03

### Fixed
- Release downloads from GitHub returned `HTTP 415 Unsupported Media Type`
  whenever the install path fell through to `zipball_url` (source archive
  — i.e. any release that doesn't ship a `.zip` asset, which is the common
  case for tagged-only releases). GitHub's zipball endpoint rejects a
  strict `Accept: application/octet-stream` header. Switched the
  download Accept to `*/*`, which both endpoint shapes accept — asset
  downloads via `browser_download_url` still serve the binary
  unchanged.

## [1.2.0] - 2026-06-03

- Add GitHub Releases tracking. Each installed add-on can be mapped to a
  GitHub repo, either via an opt-in `'github_repo' => 'owner/repo'` key in
  its `addon.setup.php` or via a new admin-mapped table on the new
  **Releases** screen. The Packages screen surfaces a "GitHub: vX.Y.Z ↗"
  badge when a newer release exists, and the sidebar shows a global count.
- Add one-click update from GitHub. When a newer release is detected, the
  Packages card and Releases row both render a button that downloads the
  release zip, safe-extracts into a staging directory, snapshots the
  existing add-on as `.{short_name}.backup.{ts}`, swaps the new version
  into place, and hands off to EE's native update screen so migration
  approval stays with the admin.
- Add `Service/GitHubReleaseChecker` (12h on-disk cache,
  sentinel-on-failure), `Service/UpdateSourceRegistry`, and
  `Service/ReleaseInstaller` (100 MB download cap, atomic swap, single
  rolling backup).
- Add `ControlPanel/Routes/Releases` (manage mappings, "Check for
  updates" button, and `install_release` POST handler).

## [1.1.0] - 2026-04-30
- Added uninstall action for installed add-ons.
- Styling improvements to the control panel UI.

## [1.0.0] - 2026-04-30
- Initial public release.
- Upload and manage ExpressionEngine add-on ZIP packages.
- Detect package folders using `addon.setup.php`.
- Integrate with ExpressionEngine's native add-on install/update/settings/uninstall flow.
