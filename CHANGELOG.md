# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [2.8.0] - 2026-06-29

### Added
- **Changelog modal on the Releases screen.** The per-row "changelog" link now
  opens a modal with the latest release's notes inline — rendered from the
  release notes already cached for *any* tracked add-on (GitHub release body or
  registry notes), so it's not limited to first-party products — plus a
  "Full changelog ↗" link to the complete history. With JS off, the link still
  navigates straight to the full changelog.

### Fixed
- **Uninstalls are now recorded in the Audit Log.** Removing an add-on through
  Addon Expert logs an `uninstall` (REMOVED) event before handing off to EE's
  native uninstall. (Removals done via EE's own Add-Ons screen still aren't
  captured — EE provides no hook for it.)

## [2.7.1] - 2026-06-29

### Changed
- **Update Sources screen grouped** into two sections — **Configured**
  (manifest-declared read-only sources + sources set here) and **Available to
  configure** (everything without a source yet) — for clearer separation.

## [2.7.0] - 2026-06-29

### Added
- **Safe removal — pre-uninstall usage check.** The Packages "remove" action
  now routes through a check that scans (best-effort) for where the add-on is
  used and shows what removing it might break, before handing off to EE's
  native uninstall:
  - its template tags `{exp:<short>:…}` in templates (layout templates
    included) and snippets,
  - channel fields whose fieldtype is the add-on,
  - extension hooks it registers (informational — removed with the add-on).
  If nothing is found it reports "safe to remove"; otherwise it lists the
  references with a "Remove anyway" confirmation. Heuristic — it can't see
  tags built dynamically or PHP that calls the add-on.

## [2.6.0] - 2026-06-29

### Added
- **Changelog link on the Releases screen.** Each tracked add-on links to its
  release notes — registry products to the live `updates.nivoli.com` changelog,
  GitHub sources to their releases page — so you can see what changed before
  updating.

### Changed
- **Releases screen groups updatable add-ons first** — an "Updates available
  (N)" group at the top, then "All tracked add-ons", so pending updates are
  surfaced rather than buried in the list.
- **EE7-fit badge wording** now reads as a status rather than an action:
  "EE7 ready" / "EE7 unverified" / "Pre-EE7" (was "Review for EE7" etc.), and
  the badges are styled as non-interactive labels.

## [2.5.0] - 2026-06-29

### Added
- **EE7 compatibility hint on the Packages screen.** Each detected add-on
  shows a best-effort "EE7 fit" badge — **EE7 ready** / **Review for EE7** /
  **Legacy (pre-EE7)** — derived fact-first from the declared `requires.ee`
  and whether the package is namespaced (the EE6/7-era structure), plus a
  small high-signal legacy scan (accessory files, removed in EE3; and the
  EE2 `$plugin_info` array). Hover the badge for the underlying signals.
  Heuristic — same "verify before relying on it" caveat as the force-install
  feature scan.

### Changed
- **Packages screen segmented into three groups** — *Available to install*,
  *Available to update*, and *Installed* — each shown only when non-empty,
  for a clearer at-a-glance overview.

### Changed
- **Packages screen redesigned for scannability.** Replaced the bulky
  3-column card grid (mixing installed and not-installed) with two compact,
  grouped tables — **Installed** and **Available** — each with a count. Each
  row shows name + short name + a one-line description, the version (with a
  `→ vX` badge when a newer release is tracked, or "update pending"), the
  author, and the same inline actions (install / update-from-release / EE
  update / settings / remove / download ZIP). Requirement-override and
  incompatibility flags are shown inline.

## [2.4.1] - 2026-06-29

### Fixed
- **Auto-finalize no longer fails on module-only add-ons.** The extension
  step called `class_exists()` on a conventional extension class name even
  for add-ons that ship no `ext.<short>.php`; that triggers EE's autoloader
  into a bare `require` of the missing file and fatals (e.g. `content_toc`).
  The extension block is now guarded by an `is_file()` check on the actual
  ext file before the class is touched. The add-on's files and module
  version still finalize correctly; only the spurious extension step is
  skipped.

### Added
- **Update Sources screen.** A dedicated screen to choose, per installed
  add-on, where its updates come from — a **GitHub repo** or a **license-gated
  registry** (`url` + `product`) — for add-ons that don't declare a source in
  their own `addon.setup.php`. The admin map now stores registry sources
  (alongside GitHub repos, which keep their plain-string format).

### Changed
- Source mapping moved off the Releases screen onto the new Update Sources
  screen; the Releases screen now shows each add-on's source read-only and
  links to it. A manifest-declared source still always wins and shows
  read-only.

### Added
- **License-gated registry update source.** Private/paid add-ons can declare
  a `registry` source — `'registry' => ['url' => '…', 'product' => '…']` — and
  Addon Expert tracks and installs them like GitHub releases, through a vendor
  endpoint that validates a license key and returns a signed, sha256-verified
  download. The site holds only the key; the vendor holds the GitHub token.
- **Registry license keys** (Settings): one key per vendor host,
  auto-discovered from installed manifests. `config.php` / env values override
  the UI-saved ones (`$config['addon_expert_registry_key']`, the per-host
  `addon_expert_registry_keys` array, or the `ADDON_EXPERT_REGISTRY_KEY`
  environment variable). Stored in
  `system/user/config/addon_expert_registry_keys.json`.
- **Integrity gate:** a registry download's sha256 is verified against the
  manifest before the atomic swap — the registry equivalent of the GitHub
  trust-on-first-use check. A mismatch hard-blocks the install and is
  audit-logged.

### Changed
- Releases screen now lists registry-sourced add-ons alongside GitHub ones,
  with a `license-gated` badge and the same one-click install; a missing key
  links to Settings. The "Source" column and copy were generalised from
  GitHub-only.

## [2.2.0] - 2026-06-26

### Changed
- Internal PHP namespace moved from `Codebit\AddonExpert` to `Nivoli\AddonExpert`,
  unifying the first-party add-ons under the Nivoli vendor namespace (alongside
  CF Image, Game Import, etc.). The legacy module/extension class names are
  unchanged, so existing installs, settings and stored data are unaffected.

## [2.1.2] - 2026-06-17

### Changed
- New add-on icon: a near-black squircle with three teal stacked
  "add-on" bars and an update badge, in the Codebit family style
  (replaces the earlier clipboard glyph). Shipped in the package so the
  EE control panel shows the new mark.

## [2.1.1] - 2026-06-17

### Changed (docs / copy only — no logic change)
- Rewrote the in-CP **Documentation** screen to cover the full 2.x
  feature set (was still describing only the ZIP uploader): Install ZIP +
  inspect/confirm, Packages badges, GitHub tracking, one-click update +
  auto-finalize, supply-chain/TOFU + audit log, compatibility + force
  override + feature scan, Settings + custom menu, a "where data lives"
  map, and refreshed safety checks. Its toolbar now links to every
  screen.
- Fixed the **Releases** "Check for updates" helper text — it still said
  "Cached for 12h afterwards"; the TTL has been 1h (with lazy refresh)
  since 1.4.0.
- README: refreshed to the 2.x feature set and embedded current
  screenshots.

## [2.1.0] - 2026-06-17

### Changed — Cleaner upload flow (inspect-before-commit + one-click force)
- Uploading a ZIP now **inspects and scans before committing**. A
  compatible package installs straight through as before. An
  **incompatible** one is no longer rejected with an error that loses
  your file — instead the upload is **quarantined** and you land on a
  confirm screen showing the unmet requirement, the compatibility-scan
  verdict inline, and a one-click **Force install anyway** button (with
  an optional audit-logged reason). No re-selecting and re-uploading
  the file.
- The pre-existing "Override version requirements" checkbox still works
  as a one-shot power path: tick it on the initial upload and an
  incompatible package force-installs immediately, skipping the confirm
  step.
- Quarantined uploads live in `system/user/cache/addon_expert/quarantine/`,
  are swept after 1 hour, and tokens are random 16-hex (path-safe).

### Internal
- `PackageInstaller`: `inspectUpload()` / `inspectForInstall()`
  (validate + inspect + scan, no side effects), `installFromZip()`
  (path-based installer shared by the upload and confirm paths), and
  `quarantineStore()` / `quarantineGet()` / `quarantineClear()` /
  `sweepQuarantine()`. `installUploaded()` now delegates to
  `installFromZip()`.
- GitHub repo renamed `calimonk/ee-addon-manager` →
  `calimonk/ee-addon-expert`; `github_repo` self-reference updated to
  match (GitHub redirects the old URL).

## [2.0.0] - 2026-06-17 — Renamed to Addon Expert

This is the same codebase as 1.7.0, **spun off into an independently
maintained product**. The plugin began as a fork of
[Addon Manager +](https://github.com/jfaza/addon-manager-plus) by Javid
Fazaeli (MIT) and has since added GitHub release tracking, one-click
updates, auto-finalize, supply-chain (TOFU) protection, requirement
override + compatibility scanner, and CP custom-menu integration —
diverging far enough that it's now its own thing. Credit to Javid for
the original ZIP-upload installer.

### Changed (breaking)
- **Short name `addon_installer` → `addon_expert`.** EE identifies
  add-ons by short name, so EE treats this as a new add-on. See the
  migration note below — existing config is carried over automatically.
- **Product name** "Addon Manager +" → **"Addon Expert"**.
- **Namespace** `JavidFazaeli\AddonInstaller` → `Nivoli\AddonExpert`.
- **Author** → Codebit (Javid credited as original author in LICENSE +
  README).
- All CP routes move from `addons/settings/addon_installer/*` to
  `addons/settings/addon_expert/*`.
- Config/cache files move from `addon_installer_*.json` /
  `user/cache/addon_installer/` to the `addon_expert` equivalents.

### Migration (automatic)
- On install/update, `upd.addon_expert.php` **copies** any existing
  `addon_installer_*.json` config (GitHub mappings, TOFU trust anchors,
  settings, requirement overrides) and the `user/cache/addon_installer/`
  tree (release cache, audit log, backups) to the new `addon_expert`
  names — only when the new file/dir doesn't already exist, never
  clobbering. Originals are left in place, so the old add-on keeps
  working until you uninstall it and a rollback loses nothing.
- **To upgrade an existing `addon_installer` install:** install Addon
  Expert (config migrates automatically), confirm the Releases /
  trust / overrides screens look right, then uninstall the old
  "Addon Manager +". Re-add the CP custom-menu entry (Settings → Menu
  Manager) since it points at the new short name.

## [1.7.0] - 2026-06-03

### Added — Compatibility feature scan (informed force)
- When an add-on is incompatible (declares a newer PHP than the server
  runs), Addon Manager + now **statically scans the add-on's code** for
  version-specific PHP syntax and functions and reports whether the
  code actually uses anything newer than the target — so a force
  override is an informed decision, not a blind one:
  - *"Best-effort scan of 31 files: no PHP features newer than 8.2
    found — appears safe to force."*
  - *"Best-effort scan found features NEWER than PHP 8.2:
    json_validate() (PHP 8.3) in Tags/Foo.php. Forcing onto 8.2 will
    likely fatal when that code runs."*
- The verdict is shown in the incompatibility message on the Install
  ZIP screen (right where you decide whether to tick "Override version
  requirements"), recorded on the override registry entry, surfaced in
  the Packages override badge ("Scan at force time: …"), and written to
  the audit log (`scan_verdict` / `scan`).
- `Service/CompatibilityScanner` — curated, low-false-positive marker
  set covering PHP 8.0–8.4 (syntax: match, nullsafe, enums, readonly,
  readonly classes, typed constants, dynamic class-const fetch,
  `#[Override]`, asymmetric visibility; plus version-introduced
  functions like `json_validate`, `mb_str_pad`, `array_find`, …).
  Regex-on-source rather than tokenized — the running tokenizer is the
  target version and can't recognise syntax newer than itself.
  Comment-stripped, method/identifier-guarded, bounded to 400 files.
  Smoke-tested across 11 scenarios incl. false-positive bait
  (`->match()`, `preg_match()`, `'readonly' => true`) and the real
  Lasting Impressions Pro 5.0.4 (correctly clear on 8.2).

### Internal
- `PackageInstaller` gains a `CompatibilityScanner` dependency + a
  `collectZipPhp()` helper that reads .php entries from the upload zip
  in-memory (no extraction) for the scan.
- `OverrideStore::record()` stores the scan verdict alongside the
  override.

## [1.6.0] - 2026-06-03

### Added — Requirement override (force install)
- An **"Override version requirements"** checkbox on the Install ZIP
  form. When an add-on declares a newer PHP/EE than the server runs and
  you tick it, the installer extracts the package, **patches the
  extracted `addon.setup.php`'s `requires` down to the running
  environment** (only the failing keys — a satisfied `ee` requirement is
  left alone), and proceeds. This is the only way past EE's native
  install gate, which reads the add-on's own declared `requires`.
- Overrides are recorded in `system/user/config/addon_installer_overrides.json`
  with the **original** declared requirement, who forced it, when, and an
  optional reason — and audit-logged (`requirement_override`).
- A persistent **"⚠ requirement override"** badge on the Packages screen
  shows which add-ons have been forced and what they originally declared,
  so it's never silently forgotten.
- **Re-apply on update**: if an overridden add-on later updates via the
  GitHub one-click flow, the patch is re-applied to the new release
  automatically (`requirement_override_reapplied`) so the override
  survives updates instead of re-breaking. If the new release adds a
  *different* unmet requirement the override didn't cover, the install
  is still refused.
- The patch is pure string surgery on the `requires` block (scoped by
  offset so it never touches a stray `'php' => ...` elsewhere); the
  add-on's PHP is never `include()`d. Smoke-tested across 12 scenarios
  (modern + legacy array syntax, partial patch, no-op, valid-PHP output,
  passes-the-gate-after).

### Added — Packages-screen compatibility awareness
- The Packages screen now flags **not-yet-installed packages that EE
  would refuse** (incompatible PHP/EE) with a red "⚠ incompatible"
  badge and a pointer to the override flow — instead of showing an
  Install button that silently bounces off EE's native gate. (Answers
  "does the pre-flight cover the Packages page?" — it does now.)

### Internal
- New `Service/OverrideStore` (JSON registry, preserves first-seen
  original across re-applies).
- `PackageInstaller::patchRequiresInFile()` (static, shared with
  ReleaseInstaller); `installUploaded()` gains `$overrideRequirements`
  + `$overrideReason` params and an `override_applied` return flag.
- `installedPackages()` surfaces `compat_issues`, `is_overridden`,
  `override_info` per package.
- `ReleaseInstaller` honors the override registry at the staging gate.

## [1.5.0] - 2026-06-03

### Added — Compatibility pre-flight check
- Both install paths now read the `requires` block from the add-on's
  `addon.setup.php` and refuse incompatible installs **before** touching
  the filesystem, with a clear message. Mirrors EE's own enforcement
  (`requires['php']` vs `PHP_VERSION`, `requires['ee']` vs `APP_VER`,
  `version_compare(..., '<')`) — but surfaces the verdict at upload /
  download time instead of only at EE's later install step.
  - **Upload ZIP**: checked right after inspecting the zip, before
    extraction. Previously the upload reported "Package uploaded"
    success and the admin only discovered the incompatibility when EE's
    native installer refused it.
  - **GitHub one-click**: checked at the staging step, before the
    atomic swap, so the existing install stays intact. This is the more
    important of the two — swapping in code that declares (and uses)
    a newer PHP syntax than the host runs could fatal the CP on the
    next request that loads the add-on.
- `requires` is parsed by regex, never `include()` — the upload flow
  must not execute untrusted PHP (an existing security property), and
  the GitHub flow must not load too-new syntax just to read a version
  (a parse fatal would crash the request). Parser handles modern
  `[...]` and legacy `array(...)` syntax, multiline, and `php` / `ee` /
  `mysql` / `mariadb` keys.
- Smoke-tested across 5 parse scenarios + 5 requirement-check scenarios.

### Internal
- `PackageInstaller::parseRequires()` + public static
  `PackageInstaller::checkRequirements()` (shared verdict logic).
- `ReleaseInstaller::parseStagedRequires()` reads from the staged file
  path (can't `include` downloaded PHP).

## [1.4.4] - 2026-06-03

### Fixed
- **Auto-finalize after a ZIP upload was inconsistent.** Sometimes the
  banner showed; sometimes it didn't. Root cause: PHP opcache could
  still serve the OLD bytecode for `addon.setup.php` for up to
  `opcache.revalidate_freq` seconds after the swap, so EE read the
  pre-swap version, `hasUpdate()` returned false, and AutoFinalizer
  correctly decided there was nothing to do — but the user perceived
  it as "the feature didn't fire". `ReleaseInstaller` already
  invalidated opcache after its atomic swap; `PackageInstaller::installUploaded`
  did not. Adding `invalidateOpcache()` to the upload path closes
  the race.

### Changed
- **Auto-finalize banner now shows skipped events too.** Previously
  the banner only rendered when something was finalized or failed —
  so when a user re-uploaded the same version (or uploaded to a
  fresh-not-yet-installed addon), the banner stayed silent and the
  whole thing looked broken. Now skipped events render as a quiet
  grey "No-op:" line with a human-readable reason (e.g. *"on-disk
  version already matches the installed version — nothing to do"*).
  The admin always gets a clear answer to "did the finalize step
  fire, and what did it find?"

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
