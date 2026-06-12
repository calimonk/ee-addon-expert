# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [1.4.3] - 2026-06-03

### Changed
- **Auto-finalize now also covers manual ZIP uploads**, not just the
  one-click GitHub install flow. After dragging a zip into Install ZIP,
  the same marker file + finalize-on-next-render pipeline runs, so
  EE's "Update Available" prompt no longer needs to wait minutes for
  EE's own addon scan to surface it.
- Auto-finalize is now triggered from the **Index** (Install ZIP),
  **Packages**, and **Releases** routes (Releases was the only one
  in 1.4.0). Whichever screen the admin lands on after an install,
  the finalizer runs.
- The auto-finalize banner moved into a shared
  `views/_finalize_banner.php` partial included from all three
  views — single source of truth for the success/failure rendering.

### Added
- ZIP-upload installs now write an `install_ok` audit-log entry with
  `source=upload_zip`, distinguishing them from GitHub installs
  (`source=release-asset:*` / `source=source-zipball`) for
  post-incident forensics.

### Internal
- `PackageInstaller::__construct` gains `AutoFinalizer` and
  `InstallAuditor` dependencies (both optional / lazy-loaded via
  `ee('addon_installer:...')` when not injected).
- `addon.setup.php` updated to pass them into the singleton.

## [1.4.2] - 2026-06-03

### Fixed
- **Auto-finalize banner showed "X.Y.Z → X.Y.Z" instead of the actual
  from→to versions.** `AutoFinalizer::finalizeOne()` was reading the
  installed version at the END of the method via
  `$addonInfo->getInstalledVersion()` — but by that point we'd
  already saved the new version, so the read returned the
  post-finalize value. Fixed by reading `from` out of the
  module/extension parts we captured earlier in the method (which
  hold the actual pre-save value).
- **Audit Log grep recipes rendered as raw PHP source** instead of
  shell commands. A `// <?= ... ?>` illustrative comment in the view
  closed the PHP block — PHP single-line comments terminate at
  newline OR at a closing tag, even when the tag is "inside" the
  comment. The rest of the PHP code became literal HTML output.
  Rewrote the comment to not contain a closing tag token.

## [1.4.1] - 2026-06-03

### Fixed
- **Audit Log column wrapping**. Cells were force-breaking long
  identifiers mid-word ("addon_install/er", "calimon/k", "PINNE/D"),
  making the table unreadable on narrow CP themes. Added
  `white-space: nowrap` to the structured-data columns (When, Event,
  Add-on, Repo, Version, Admin, Self?), let the Detail column wrap,
  and wrapped the table in an `overflow-x: auto` container so narrow
  viewports get a horizontal scrollbar instead of broken words.
- **Grep recipes at the bottom of Audit Log collapsed onto one line**
  because PHP's `<?= ... ?>` template tag eats a trailing newline.
  Replaced the inline interpolation with a single
  `implode("\n", [...])` echo, which renders correctly.
- Applied the same `white-space: nowrap` + `overflow-x: auto`
  treatment to the Releases-screen mapping table preemptively — same
  content shape, same risk on narrow themes.

### Notes
- The 1.3.x → 1.4.0 upgrade itself didn't trigger the new
  auto-finalize banner because the 1.3.x installer (which performed
  the swap) didn't write markers — that's expected. From 1.4.0
  onwards every install writes a marker and the next page load runs
  the EE-side finalize. The next install (1.4.0 → 1.4.1 or later) is
  the real dog-food test of the auto-finalize feature.

## [1.4.0] - 2026-06-03

### Added — Auto-finalize EE-side updates
- After the one-click "Install vX.Y.Z" button swaps files on disk, the
  installer writes a marker at
  `system/user/cache/addon_installer/pending_finalize/{short}.json`.
  On the very next CP request (the post-install redirect to our
  Releases screen), `Service/AutoFinalizer` scans the markers and
  runs EE's exact update flow per pending addon:

    1. `ee('Addon')->get($short)` → addon info
    2. `new {InstallerClass}()->update($currentVersion)` — runs the
       addon's own `upd.php` migration code
    3. Bump `exp_modules.module_version` via the EE Model API
    4. Same for extension class + `exp_extensions.version` (if any)

  Mirrors `Controller\Addons\Addons::update()` from EE 7.dev so
  there's no semantic drift — what EE does for the native Update
  button, we do via the marker. Self-updates of Addon Manager + work
  cleanly because the finalize runs in a fresh request with the new
  code loaded (not the in-memory pre-swap version).

  Failures are capped at 3 retries per marker, audit-logged, and
  surfaced as a red banner on the Releases screen.

  Setting: **Auto-finalize EE-side updates after one-click install**
  (default ON). Off reverts to the manual "click Update on
  Developer → Add-Ons" flow.

### Added — Lazy parallel release refresh
- Release-cache TTL dropped from **12h → 1h**. Combined with the new
  lazy refresh, an admin who visits the Releases screen once a day
  always sees current data.
- Loading the Releases screen now refreshes any stale cache entries
  in parallel via `curl_multi` — single round-trip total, bounded by
  the slowest single GitHub fetch (~4s ceiling), regardless of how
  many mappings you have.
- Setting: **Refresh stale release caches when loading the Releases
  screen** (default ON). Off keeps the cache static until the
  explicit "Check for updates" button is clicked.

### Internal
- New `Service/AutoFinalizer` with the marker/finalize logic.
- `GitHubReleaseChecker::refreshMultiple()` for `curl_multi`-backed
  parallel fetches; falls back to sequential when cURL multi isn't
  available.
- `ReleaseInstaller::__construct` gains an optional `AutoFinalizer`
  arg; `addon.setup.php` registers the new singleton.

## [1.3.5] - 2026-06-03

### Changed
- **Audit log promoted to a top-level sidebar entry** (Add-on Manager + →
  Audit Log) and removed from the bottom of the Releases screen. The
  dedicated page shows a wider window (last 200 events vs the previous
  inline 25), a per-event-type count summary, a new "Self?" column
  highlighting self-updates of Addon Manager +, and grep recipes for
  poking at the raw JSONL log.
- New `Service/InstallAuditor::logPath()` helper returns the resolved
  on-disk path for the view to surface.

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
