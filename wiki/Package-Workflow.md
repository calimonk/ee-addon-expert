# Package Workflow

## Upload

Use Install ZIP to upload an ExpressionEngine add-on package. Addon Expert
extracts the detected add-on folder into the active add-ons directory.

Enable overwrite when uploading a newer version of an add-on whose folder
already exists.

## Install

Packages marked Not Installed show an install icon. The icon submits to
ExpressionEngine's normal install endpoint and returns to the Packages screen.

## Update

Installed packages with a newer file version show Update Available. The update
icon submits to ExpressionEngine's normal update endpoint.

This is the expected flow after uploading a newer ZIP with overwrite enabled.

## Settings

The settings icon is enabled only when the add-on is installed and declares
`settings_exist` in `addon.setup.php`.

## Download

Every detected package has a download icon. Downloads are generated on demand
from the current add-on folder and are not stored permanently.

## Uninstall

Installed packages show a red uninstall icon. The action asks for confirmation
and submits to ExpressionEngine's normal uninstall endpoint.
