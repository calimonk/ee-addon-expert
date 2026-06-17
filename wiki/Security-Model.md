# Security Model

Addon Expert does not execute uploaded package PHP during inspection.

## ZIP Validation

Uploaded packages are rejected when they contain:

- No `addon.setup.php`.
- Loose add-on files at the ZIP root.
- Absolute paths.
- Drive-letter paths.
- `..` path traversal.
- Invalid add-on folder names.

## Extraction

Only files inside the detected add-on folder are extracted. Wrapper folder files
outside the add-on folder are ignored.

## Lifecycle Actions

Install, update, settings, and uninstall are delegated to ExpressionEngine.
Addon Expert builds convenience actions, but ExpressionEngine enforces
permissions, CSRF validation, and lifecycle behavior.

## Downloads

Downloads are generated into temporary files and removed after streaming. They
are not retained as permanent uploaded package archives.
