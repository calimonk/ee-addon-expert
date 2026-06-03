# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

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
