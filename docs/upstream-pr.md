# Upstream PR draft


## Title

> feat: GitHub Releases tracking, one-click install, supply-chain hardening

## Body

### TL;DR

Adds end-to-end GitHub Releases support to Addon Manager + so admins can:

1. **Map any installed add-on to a GitHub repo** — per-author via
   `'github_repo' => 'owner/repo'` in `addon.setup.php`, or per-site via
   a new Releases settings screen.
2. **See pending GitHub updates** at a glance — Packages cards show
   `GitHub: vX.Y.Z ↗` badges; CP sidebar grows an `Addons (N)` count.
3. **One-click install** of the latest release — downloads, safe-extracts,
   atomic swaps, backs up the old version, invalidates caches.
4. **Auto-finalize** the EE-side update after the swap so the admin
   doesn't have to navigate to **Developer → Add-Ons** separately.

A supply-chain protection layer (TOFU repo identity pinning + audit log)
gates every install so a hostile takeover of an upstream repo can't
auto-pwn the site.

Originated as a fork at
[`calimonk/ee-addon-manager`](https://github.com/calimonk/ee-addon-manager)
versions 1.2.0 → 1.4.2, dog-food-validated by installing every release
through the prior release's UI on a production EE 7 site.

### Motivation

Addon Manager + already covers EE-store-distributed add-ons (the existing
upload flow). For GitHub-distributed add-ons, every author currently has
to bake their own update-polling code into their own add-on. Centralizing
that into Addon Manager + means:

- **One CP surface** for all updates regardless of distribution channel.
- **No duplication** across add-ons that ship update polling individually.
- **Supply-chain hardening** benefits every tracked add-on by default
  rather than each author having to implement it themselves.

The README Roadmap already lists *"Remote package registry / URL install"*
— this PR delivers a concrete, opinionated version of that.

### What's new

**1. Mapping layer**

- `'github_repo' => 'owner/repo'` key in any `addon.setup.php` — opt-in,
  decentralized, survives admin reinstall.
- Admin-mapped table on a new **Releases** screen — works for any add-on,
  no author opt-in needed. Persists to
  `system/user/config/addon_installer_mappings.json`.
- Resolution priority: manifest key beats admin map (an opted-in add-on
  can't be silently misrouted by a stale admin entry).

**2. Polling**

- `Service/GitHubReleaseChecker` — unauthenticated
  `/repos/{owner}/{repo}/releases/latest`, 1h on-disk cache,
  sentinel-on-failure so a flaky network doesn't hammer the API.
- Lazy parallel refresh on Releases-screen load via `curl_multi` — single
  round-trip total, ~4s ceiling regardless of mapping count.
- Explicit "Check for updates" button forces full refresh of every
  mapping.

**3. Install flow**

- Orange "Install vX.Y.Z" button on Packages cards + Releases rows.
- `Service/ReleaseInstaller` — downloads (100 MB cap), safe-extracts
  (existing `..` / absolute-path filter reused), locates the addon
  subtree inside the zip, atomic-swaps via `rename()`, backs up the
  old version, invalidates caches.
- Backup lives at `system/user/cache/addon_installer/backups/{short}/{ts}/`
  — **explicitly outside** `system/user/addons/` so EE's PSR-4 discovery
  can't see it. (An earlier `.{short}.backup.{ts}` scheme inside
  `addons/` collided with the autoloader during self-updates.)
- `locateAddonRoot` smoke-tested across 6 real-world zip layouts:
  standard asset zip, wrapper folder, GitHub source zipball (single-addon
  repo wrapped as `{owner}-{repo}-{sha}/`), multi-addon repo with
  explicit folder match, ambiguous multi-addon (correctly refuses), and
  macOS noise files (`__MACOSX/`).

**4. Supply-chain protection**

- TOFU identity pinning per mapping. On first install, three
  GitHub-controlled stable identifiers are pinned to
  `system/user/config/addon_installer_trust.json`:
  - `owner_id` — numeric, stable across username renames.
  - `repo_id` — numeric, changes on delete+recreate (RepoJacking).
  - `created_at` — repo creation timestamp.
- Every install attempt re-fetches identity **fresh** (cache bypass)
  before swap, compares against pinned anchor. Mismatch → hard-block
  with a diff banner explaining what changed.
- Username renames don't false-alarm — `owner_id` is stable. Only real
  ownership transfers and delete+recreate trip the check.
- "Reconfirm trust" action for legitimate ownership transfers
  (e.g. maintainer handed the repo over) — itself audit-logged so a
  hostile auto-reconfirm via stolen admin cookies leaves a trace.
- `TrustStore::compare` smoke-tested across 8 identity-change
  scenarios — including the critical "owner_login renamed but owner_id
  stable" case which must NOT alarm, and the "owner_id changed" case
  which must block.

**5. Append-only install audit log**

- `Service/InstallAuditor` writes JSONL to
  `system/user/cache/addon_installer/install.log`.
- Events: `install_ok`, `install_failed`, `install_blocked`,
  `trust_pinned`, `trust_reconfirmed_manual`, `auto_finalized`,
  `auto_finalize_failed`, `auto_finalize_abandoned`.
- Records timestamp, admin login, repo, version, source URL, identity
  fingerprint, trust state, `is_self` flag.
- Rotates at ~1 MB to keep on-disk size bounded.
- New CP screen surfaces the last 200 events with per-event-type count
  chips and grep recipes for the raw file.

**6. Auto-finalize EE-side updates**

After our installer swaps files, EE detects the on-disk version is
newer than `exp_modules.module_version` and shows an "Update Available"
prompt on Developer → Add-Ons. Without auto-finalize, the admin has to
click that prompt manually.

`Service/AutoFinalizer` mirrors `Controller\Addons\Addons::update()`
from EE 7.dev exactly:

1. `ee('Addon')->get($short)` → addon info.
2. `new {InstallerClass}()->update($currentVersion)` — runs the addon's
   own `upd.php` migration code.
3. Bump `exp_modules.module_version` via the EE Model API.
4. Same for `getExtensionClass()` + `exp_extensions.version` (if any).

Pattern: install writes a marker file
(`system/user/cache/addon_installer/pending_finalize/{short}.json`)
post-swap. The next CP request (which is the post-install 302 redirect
to our Releases screen) scans the markers and finalizes each. This
works cleanly for self-updates of Addon Manager + itself because the
finalize runs in a fresh PHP process with the new code loaded — not
the in-memory pre-swap version.

Failures cap at 3 retries per marker, audit-log under
`auto_finalize_failed` / `auto_finalize_abandoned`, and surface as a
red banner on the Releases screen.

Setting: **Auto-finalize EE-side updates after one-click install**
(default ON, can disable).

**7. CP Custom-menu integration**

- New `ext.addon_installer.php` with `cp_custom_menu` hook.
- When the admin adds Addon Manager + to a role's Custom menu via
  Settings → Menu Manager, our hook renders the entry with the
  pending-update count baked into the label (e.g. `Addons (3)`).
  EE's Custom menu doesn't support badge widgets — embedding the
  count in the label text is what EE's own first-party addons (Spam,
  Structure) do too.
- Label is admin-configurable (40-char cap, HTML stripped) since
  the Custom sidebar is narrow.

**8. UI**

- New CP screens: **Releases**, **Audit Log**, **Settings**.
- Sidebar order: Install ZIP → Packages → Releases (N) → Audit Log →
  Settings → Documentation.
- Packages cards swap in a `GitHub: vX.Y.Z ↗` badge alongside the
  existing "Update Available" badge when a newer GitHub release exists.

### File layout

```
Service/
├── GitHubReleaseChecker.php      # polling + 1h cache + parallel refresh
├── UpdateSourceRegistry.php      # author manifest + admin map resolution
├── ReleaseInstaller.php          # download → extract → swap → invalidate
├── TrustStore.php                # TOFU identity pinning
├── InstallAuditor.php            # JSONL audit trail
├── AutoFinalizer.php             # EE-side update orchestrator
├── SettingsStore.php             # addon-level settings (JSON file)
└── PackageInstaller.php          # ← existing; extended with remote_* fields

ControlPanel/Routes/
├── Releases.php                  # ← new
├── AuditLog.php                  # ← new
├── Settings.php                  # ← new
└── (other routes unchanged)

ext.addon_installer.php           # ← new (cp_custom_menu hook)
upd.addon_installer.php           # ← extended (registers extension on install/update)
```

Persistence layout (no DB migrations):

```
system/user/config/addon_installer_mappings.json   # admin repo map
system/user/config/addon_installer_trust.json      # TOFU fingerprints
system/user/config/addon_installer_settings.json   # addon-level toggles
system/user/cache/addon_installer/
├── release_*.json                                 # GitHub API cache
├── repo_*.json                                    # repo-identity cache
├── install.log                                    # JSONL audit trail
├── backups/{short}/{ts}/                          # rolling backups
└── pending_finalize/{short}.json                  # auto-finalize markers
```

### Design decisions

**Opt-in everywhere.** Manifest key, admin mapping, auto-finalize,
custom-menu, lazy refresh — all default-friendly but disable-able.
Existing 1.1.0 behavior is unchanged for users who don't configure
anything.

**No DB migrations.** Everything persists to JSON files under
user-managed dirs. Avoids the upgrade-path mismatches that often plague
EE add-on schema changes.

**No external services.** Unauthenticated GitHub API only (60/hr/IP per
site — comfortably over budget for any realistic site's mapping count).
No registry, no message queue, no background worker required.

**No required CLI / cron.** Lazy refresh on view + 1h TTL keeps things
fresh for sites with at least daily admin activity. An optional CLI
command for guaranteed background timing is a sensible follow-up but
deliberately not in scope here.

**Trust on first use (TOFU).** Identity pinning catches the realistic
threats (RepoJacking, ownership transfer) without requiring out-of-band
attestation. The first install is implicitly trusted; subsequent installs
verify against the pinned anchor. A "Reconfirm trust" action handles
legitimate identity changes with an audit-logged paper trail.

**Self-update safety.** Updating Addon Manager + itself is the most
fraught case. Two design choices specifically defend it:

1. Backups live OUTSIDE `system/user/addons/`. An earlier scheme stored
   them as `.{short}.backup.{ts}` inside the addons dir; EE's PSR-4
   discovery walked into them and registered the old version as a
   second source for our namespace, fatally confusing the classloader.
2. Auto-finalize is deferred via a marker file rather than run in the
   install request, so the next request loads fresh class definitions
   for the new code, not stale in-memory ones from the pre-swap state.

### What's NOT included

Deliberately scoped out to keep this reviewable:

- **Publisher-account tracking** (warn when a new GitHub user publishes
  a release for a repo whose publisher history has been one specific
  account).
- **Release-age threshold** (refuse to install releases younger than N
  hours, to give the community time to spot a hostile release before
  sites pick it up).
- **Cron-driven background refresh** (admin-facing CLI command +
  optional setup docs).
- **Authenticated GitHub calls** (PAT support for sites needing >60/hr).
- **Bulk install** from a single multi-addon zip (already on the
  existing Roadmap).

Happy to follow up with separate PRs for any of these if you'd like
them in.

### Configuration

Two new admin-visible screens, both opt-in:

- **Addon Manager + → Releases** — map add-ons to GitHub repos; "Check
  for updates" refresh button; trust state per mapping; one-click
  install per pending update.
- **Addon Manager + → Settings** — toggles for custom-menu integration
  (show/hide, target, label, count), auto-finalize, lazy refresh.

Both backed by JSON files under `system/user/config/`.

### Testing

- `php -l` clean across all touched and new files (per the existing
  CONTRIBUTING command).
- Smoke tests for the riskiest paths:
  - `locateAddonRoot` against 6 real-world zip layouts.
  - `TrustStore::compare` across 8 identity-change scenarios.
- Live dog-food validation across the fork's full release history
  ([1.2.0 → 1.4.2](https://github.com/calimonk/ee-addon-manager/releases))
  — every release installed via the prior release's UI on a production
  EE 7 site.

### Migration story

- No DB migrations.
- Existing 1.1.0 installs upgrade in place — `addon_installer` short
  name preserved, all existing routes still work, behavior unchanged
  for admins who don't configure anything new.
- Several bugs found and fixed during fork validation are already in
  this diff — e.g. the autoloader-collision from in-addons-dir
  backups, and a transient 403 from redirecting to `addons/update/{short}`
  via GET. Both root-caused, fixed, with self-heal logic for damaged
  installs (sweeps leftover legacy-format backups on any subsequent
  install).

### Compatibility

- ExpressionEngine 7
- PHP 7.4+ with `ZipArchive` and `cURL`
- Same surface as existing 1.1.0; no new system dependencies.

### Breaking changes

None.

### Stats

- 22 files changed, +4100 / -8 LOC.
- 16 new files (services, routes, views, extension class, draft docs).
- 6 existing files extended.
- 13 commits across 1.2.0 → 1.4.2.

### Split-up?

The features are tightly coupled (install requires trust verification
requires identity caching requires polling, etc.), so I'd lean toward
keeping it as one cohesive change. Happy to decompose into smaller
PRs along these natural seams if you'd prefer:

1. Mapping layer + polling (`Releases` screen, no install).
2. One-click install (depends on #1).
3. Supply-chain layer (TOFU + audit; depends on #2 because it gates
   the install path).
4. Auto-finalize (depends on #2).
5. Custom-menu extension (independent; could be standalone).

Let me know your preference.
