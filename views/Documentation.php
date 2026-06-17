<?php
$h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<div class="addi-wrap">
  <p class="addi-toolbar">
    <a class="button button--primary" href="<?= $h($upload_url) ?>">Install ZIP</a>
    <a class="button button--default" href="<?= $h($packages_url) ?>">View Packages</a>
    <a class="button button--default" href="<?= $h($manager_url) ?>">Add-on Manager</a>
  </p>

  <section class="addi-card">
    <h2>Documentation</h2>
    <p class="addi-muted">Addon Expert uploads add-on ZIP files, detects the actual add-on folder from the package's <code>addon.setup.php</code>, and extracts it into the active ExpressionEngine add-ons directory.</p>
  </section>

  <section class="addi-card">
    <h2>Package Detection</h2>
    <ul class="addi-list">
      <li>The ZIP can contain a wrapper folder, for example <code>vendor-package/my_addon/addon.setup.php</code>.</li>
      <li>The installer uses the folder containing <code>addon.setup.php</code> as the add-on folder.</li>
      <li>Loose add-on files at the ZIP root are rejected because there is no folder name to install.</li>
      <li>The detected folder name must use lowercase letters, numbers, and underscores.</li>
    </ul>
  </section>

  <section class="addi-card">
    <h2>Downloads</h2>
    <p class="addi-muted">The Packages screen can export any detected add-on folder as a ZIP. Downloads are generated on demand from the active add-on directory and are not stored permanently.</p>
  </section>

  <section class="addi-card">
    <h2>Package Actions</h2>
    <ul class="addi-list">
      <li>Packages are sorted with not-installed add-ons first.</li>
      <li>The status tag shows whether ExpressionEngine currently has the add-on installed.</li>
      <li>The install icon uses ExpressionEngine's normal add-on installation flow and returns to the Packages screen.</li>
      <li>The install icon is shown only for add-ons that are not installed.</li>
      <li>When an installed add-on's files are overwritten with a newer version, the update icon runs ExpressionEngine's normal update flow.</li>
      <li>The settings icon is enabled only after the add-on is installed and exposes a settings page.</li>
      <li>The red uninstall icon is shown only for installed add-ons and uses ExpressionEngine's normal uninstall flow.</li>
    </ul>
  </section>

  <section class="addi-card">
    <h2>Safety Checks</h2>
    <ul class="addi-list">
      <li>ZIP support requires PHP <code>ZipArchive</code>.</li>
      <li>Packages must include <code>addon.setup.php</code>.</li>
      <li>Absolute paths, drive-letter paths, and <code>..</code> path traversal are rejected before extraction.</li>
      <li>ExpressionEngine still controls the final install or update step after extraction.</li>
    </ul>
  </section>
</div>
