# Development

## Important Files

- `Service/PackageInstaller.php`: package detection, ZIP validation, extraction, package listing, and download generation.
- `ControlPanel/Routes/`: control panel routes.
- `views/`: control panel UI.
- `views/css/style.css`: embedded control panel styling.
- `addon.setup.php`: ExpressionEngine add-on metadata and services.

## Compatibility Rule

Keep the folder and short name as `addon_expert` unless a migration plan is
created. The public product name is Addon Expert, but the short name keeps
existing installs and routes stable.

## Lint

Run PHP lint after edits:

```bash
for f in *.php ControlPanel/*.php ControlPanel/Routes/*.php Service/*.php views/*.php; do php -l "$f" || exit 1; done
```

## Git

`AGENTS.md` is ignored intentionally. It is local agent guidance and should not
be committed to the add-on repository.
