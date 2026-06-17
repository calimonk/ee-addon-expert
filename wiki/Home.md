# Addon Expert Wiki

Addon Expert is an ExpressionEngine 7 control panel add-on for managing
third-party add-on ZIP packages without leaving the control panel.

## Pages

- [Installation](Installation.md)
- [Package Workflow](Package-Workflow.md)
- [Package Format](Package-Format.md)
- [Security Model](Security-Model.md)
- [Development](Development.md)

## What It Does

- Uploads add-on ZIP files.
- Detects the real add-on folder from `addon.setup.php`.
- Extracts packages into the active ExpressionEngine add-ons directory.
- Shows detected add-ons as cards with install state.
- Links to ExpressionEngine install, update, uninstall, settings, and download actions.

ExpressionEngine remains responsible for the final add-on lifecycle actions.
