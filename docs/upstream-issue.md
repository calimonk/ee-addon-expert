# Upstream issue draft

For `jfaza/addon-manager-plus`. File via `gh issue create --repo jfaza/addon-manager-plus`
or via the web UI. Replace with the actual PR body
([docs/upstream-pr.md](upstream-pr.md)) only if/when Javid signals he
wants the full diff.

---

## Title

> Proposing: GitHub Releases tracking + one-click install

## Body

Hi Javid! I'd like to propose adding native GitHub Releases support to
Addon Manager +. The Roadmap already lists *"Remote package registry /
URL install"* — this is a concrete take on that direction. Wanted to
align with you before opening the actual PR per CONTRIBUTING.

### Motivation

Addon Manager + already covers EE-store distribution (upload zip →
install). For GitHub-distributed add-ons, every author typically bakes
their own update-polling into their own add-on (mine ship ~200 lines of
cURL + cron each). Centralizing that into Addon Manager + would:

- Give admins **one CP surface** for update tracking regardless of
  distribution channel.
- Avoid duplication across GitHub-distributed add-ons.
- Provide one place to add **supply-chain hardening** (RepoJacking
  defenses) that benefits every tracked add-on by default.

### What I'm proposing (high level)

1. **Mapping layer** — add-on opts in via
   `'github_repo' => 'owner/repo'` in its `addon.setup.php`, or admin
   maps it manually on a new Releases screen. Manifest wins.
2. **Polling** — 1h cache of `/releases/latest`; lazy parallel refresh
   via `curl_multi` on Releases-screen load.
3. **Pending-update badges** — `GitHub: vX.Y.Z ↗` on Packages cards,
   `(N)` count in CP sidebar.
4. **One-click install** — downloads release zip (asset or
   source-zipball), safe-extracts (existing `..` / absolute-path
   filter reused), atomic-swaps, backs up the old version, invalidates
   caches.
5. **TOFU supply-chain protection** — pins `owner_id` + `repo_id` +
   `created_at` on first install; blocks installs when those change
   (RepoJacking / ownership transfer). Username renames don't
   false-alarm.
6. **JSONL audit log** — every install / trust / refresh event
   recorded, with rotation at 1 MB.
7. **Auto-finalize** — mirrors EE's own
   `Controller\Addons\Addons::update()` via a marker file pattern so
   the admin doesn't need to navigate to Developer → Add-Ons after
   each install.
8. **CP Custom-menu integration** — `cp_custom_menu` extension hook
   with admin-configurable label + pending-count badge.

### Design constraints

- **Opt-in everywhere.** Existing 1.1.0 behavior is unchanged for
  users who don't configure anything new.
- **No DB migrations.** Everything persists to JSON files under
  `system/user/config/` and `system/user/cache/`.
- **No external services.** Unauthenticated GitHub API only
  (60/hr/IP is comfortably over budget for any realistic site).
- **No required CLI / cron.** Lazy refresh + 1h TTL handles
  daily-active sites.

### Status

Done as a fork at
[`calimonk/ee-addon-manager`](https://github.com/calimonk/ee-addon-manager)
(1.2.0 → 1.4.2). Validated by installing every release through the
prior release's UI on a production EE 7 site over the past couple of
weeks — including self-updates of Addon Manager + itself, which
exercises the trickiest code paths.

22 files changed, +4100 / -8 LOC. 16 new files, 6 existing extended.

Full PR description (the long version with file layout, design
decisions, testing notes, what's NOT included, etc.) is at
https://github.com/calimonk/ee-addon-manager/blob/main/docs/upstream-pr.md.

### Questions for you

1. **Does the scope sound right?** Or would you want a narrower first
   cut?
2. **One PR, or split?** If split, my suggested decomposition would be:
   (a) mapping + polling (no install), (b) one-click install,
   (c) supply-chain layer, (d) auto-finalize, (e) custom-menu — in
   that order of dependency.
3. **Persistence**: JSON files under `system/user/` (current approach)
   — or would you prefer addon-settings DB tables for some of it?
4. Any specific design choices you'd want me to revisit before I open
   the actual diff?

Happy to discuss any of this. If you'd rather just look at the code
first, the fork's [`main`](https://github.com/calimonk/ee-addon-manager)
is the proposed diff.
