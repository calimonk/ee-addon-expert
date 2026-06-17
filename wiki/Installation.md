# Installation

## Requirements

- ExpressionEngine 7.
- PHP `ZipArchive`.
- A writable `system/user/addons/` directory.
- Control panel permission to manage add-ons.

## Install The Add-on

1. Copy `addon_expert/` into `system/user/addons/`.
2. Open ExpressionEngine **Developer > Add-Ons**.
3. Find **Addon Expert** and click **Install**.
4. Click **Settings** next to Addon Expert to open it.

## Verify Setup

The Install ZIP page shows:

- ZIP Support: confirms `ZipArchive` is available.
- Add-ons Folder: confirms the active add-ons folder is writable.
- Maximum ZIP Size: shows server upload and POST limits.

If the add-ons folder is not writable, update filesystem permissions before
uploading packages.
