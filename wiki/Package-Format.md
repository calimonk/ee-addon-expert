# Package Format

Addon Expert expects a ZIP file containing one ExpressionEngine add-on folder.

## Standard Layout

```text
my_addon/
  addon.setup.php
  upd.my_addon.php
  mcp.my_addon.php
  mod.my_addon.php
  views/
  language/
```

## Wrapper Folders

Wrapper folders are allowed:

```text
downloaded-release/
  my_addon/
    addon.setup.php
    upd.my_addon.php
```

The folder containing `addon.setup.php` is treated as the add-on folder.

## Folder Names

Add-on folder names must use lowercase letters, numbers, and underscores.

Valid:

```text
my_addon
seo_tools
backup2
```

Invalid:

```text
MyAddon
my-addon
my addon
```

Loose add-on files at the ZIP root are rejected because the destination folder
cannot be inferred safely.
