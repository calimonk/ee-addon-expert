# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

- Add GitHub Releases tracking. Each installed add-on can be mapped to a
  GitHub repo, either via an opt-in `'github_repo' => 'owner/repo'` key in
  its `addon.setup.php` or via a new admin-mapped table on the new
  **Releases** screen. The Packages screen surfaces a "GitHub: vX.Y.Z ↗"
  badge when a newer release exists, and the sidebar shows a global count.
- Add `Service/GitHubReleaseChecker` (12h on-disk cache,
  sentinel-on-failure) and `Service/UpdateSourceRegistry`.
- Add `ControlPanel/Routes/Releases` (manage mappings + "Check for updates"
  button).

## [1.1.0] - 2026-04-30
- Added uninstall action for installed add-ons.
- Styling improvements to the control panel UI.

## [1.0.0] - 2026-04-30
- Initial public release.
- Upload and manage ExpressionEngine add-on ZIP packages.
- Detect package folders using `addon.setup.php`.
- Integrate with ExpressionEngine's native add-on install/update/settings/uninstall flow.
