<?php
$h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$status = $status ?? [];
?>
<div class="addi-wrap">
  <?php include __DIR__ . '/_finalize_banner.php'; ?>

  <?php
  $confirm = $confirm ?? null;
  if (is_array($confirm)):
    $cIssues = (array) ($confirm['issues'] ?? []);
    $cScan   = (string) ($confirm['scan'] ?? '');
    $cVerdict = (string) ($confirm['scan_verdict'] ?? '');
    $cName   = (string) ($confirm['name'] ?? ($confirm['short_name'] ?? 'this package'));
    $cVer    = (string) ($confirm['version'] ?? '');
    $cToken  = (string) ($confirm['token'] ?? '');
    $scanIsRisk = $cVerdict === 'risk';
  ?>
  <section class="addi-card" style="border-left:4px solid <?= $scanIsRisk ? '#dc2626' : '#f59e0b' ?>;margin-bottom:18px">
    <h2 style="margin-top:0">Confirm install — version requirement not met</h2>
    <p style="font-size:13px;margin:6px 0">
      <strong><?= $h($cName) ?><?= $cVer !== '' ? ' ' . $h($cVer) : '' ?></strong>
      declares requirements this server doesn't meet:
    </p>
    <ul style="margin:6px 0 10px;font-size:13px;color:#991b1b">
      <?php foreach ($cIssues as $iss): ?>
        <li><?= $h($iss) ?></li>
      <?php endforeach; ?>
    </ul>

    <?php if ($cScan !== ''): ?>
      <div style="background:<?= $scanIsRisk ? '#fef2f2' : '#f0fdf4' ?>;border:1px solid <?= $scanIsRisk ? '#fecaca' : '#bbf7d0' ?>;border-radius:6px;padding:10px 14px;margin:0 0 12px;font-size:12.5px;color:<?= $scanIsRisk ? '#991b1b' : '#166534' ?>">
        <strong><?= $scanIsRisk ? '⚠ Compatibility scan flagged risk' : '✓ Compatibility scan' ?>:</strong>
        <?= $h($cScan) ?>
      </div>
    <?php endif; ?>

    <p class="addi-muted" style="font-size:12px;margin:0 0 12px">
      Forcing patches the add-on's declared requirement so EE accepts it, records the
      original, and flags the add-on with a requirement-override badge. The upload is held
      for one hour; after that you'll need to re-upload.
    </p>

    <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap">
      <form method="post" action="<?= $h($confirm_url) ?>"
            onsubmit="return confirm('Force-install <?= $h($cName) ?> despite the version requirement?');">
        <?php if (! empty($csrf_token)): ?>
          <input type="hidden" name="csrf_token" value="<?= $h($csrf_token) ?>">
          <input type="hidden" name="XID" value="<?= $h($csrf_token) ?>">
        <?php endif; ?>
        <input type="hidden" name="force_quarantine" value="1">
        <input type="hidden" name="quarantine_token" value="<?= $h($cToken) ?>">
        <input type="text" name="override_reason" maxlength="200"
               placeholder="Optional reason (audit-logged)"
               style="padding:6px 9px;border:1px solid #cbd5e1;border-radius:4px;font-size:12.5px;width:280px;margin-right:6px;vertical-align:middle">
        <button class="button" type="submit" style="background:<?= $scanIsRisk ? '#dc2626' : '#f59e0b' ?>;color:#fff;border:0;vertical-align:middle">
          Force install anyway
        </button>
      </form>
      <form method="post" action="<?= $h($confirm_url) ?>">
        <?php if (! empty($csrf_token)): ?>
          <input type="hidden" name="csrf_token" value="<?= $h($csrf_token) ?>">
          <input type="hidden" name="XID" value="<?= $h($csrf_token) ?>">
        <?php endif; ?>
        <input type="hidden" name="cancel_quarantine" value="1">
        <input type="hidden" name="quarantine_token" value="<?= $h($cToken) ?>">
        <button class="button button--default" type="submit">Cancel</button>
      </form>
    </div>
  </section>
  <?php endif; ?>

  <?php if (! empty($installed_short_name)): ?>
    <section class="addi-notice is-success">
      <div>
        <strong><?= $h($installed_short_name) ?> is ready in ExpressionEngine.</strong>
        <p>
          <?php if (! empty($update_available)): ?>
            A newer package is ready. Run the ExpressionEngine update step to finish.
          <?php elseif (! empty($installed_is_installed)): ?>
            This add-on is already installed.
          <?php else: ?>
            Install it now, or review all detected packages.
          <?php endif; ?>
        </p>
      </div>
      <div class="addi-actions">
        <?php if (! empty($install_url)): ?>
          <form class="addi-inline-form" method="post" action="<?= $h($install_url) ?>">
            <?php if (! empty($csrf_token)): ?>
              <input type="hidden" name="csrf_token" value="<?= $h($csrf_token) ?>">
              <input type="hidden" name="XID" value="<?= $h($csrf_token) ?>">
            <?php endif; ?>
            <button class="button button--primary" type="submit">Install Add-on</button>
          </form>
        <?php endif; ?>
        <?php if (! empty($update_url)): ?>
          <form class="addi-inline-form" method="post" action="<?= $h($update_url) ?>">
            <?php if (! empty($csrf_token)): ?>
              <input type="hidden" name="csrf_token" value="<?= $h($csrf_token) ?>">
              <input type="hidden" name="XID" value="<?= $h($csrf_token) ?>">
            <?php endif; ?>
            <button class="button button--primary" type="submit">Update Add-on</button>
          </form>
        <?php endif; ?>
        <?php if (! empty($settings_url)): ?>
          <a class="button button--default" href="<?= $h($settings_url) ?>">Settings</a>
        <?php endif; ?>
        <?php if (! empty($remove_url)): ?>
          <form class="addi-inline-form" method="post" action="<?= $h($remove_url) ?>" onsubmit="return confirm('Uninstall <?= $h($installed_short_name) ?>?');">
            <?php if (! empty($csrf_token)): ?>
              <input type="hidden" name="csrf_token" value="<?= $h($csrf_token) ?>">
              <input type="hidden" name="XID" value="<?= $h($csrf_token) ?>">
            <?php endif; ?>
            <button class="button addi-button-danger" type="submit">Uninstall</button>
          </form>
        <?php endif; ?>
        <a class="button button--default" href="<?= $h($packages_url) ?>">View Packages</a>
        <a class="button button--default" href="<?= $h($manager_url) ?>">Add-on Manager</a>
      </div>
    </section>
  <?php endif; ?>

  <section class="addi-grid addi-grid-three">
    <article class="addi-card">
      <h2>ZIP Support</h2>
      <p class="addi-status <?= ! empty($status['zip_available']) ? 'is-ok' : 'is-bad' ?>">
        <?= ! empty($status['zip_available']) ? 'Available' : 'Missing' ?>
      </p>
      <p class="addi-muted">Requires PHP ZipArchive.</p>
    </article>

    <article class="addi-card">
      <h2>Add-ons Folder</h2>
      <p class="addi-status <?= ! empty($status['addons_path_writable']) ? 'is-ok' : 'is-bad' ?>">
        <?= ! empty($status['addons_path_writable']) ? 'Writable' : 'Not writable' ?>
      </p>
      <p class="addi-muted"><code><?= $h($status['addons_path'] ?? '') ?></code></p>
    </article>

    <article class="addi-card">
      <h2>Maximum ZIP Size</h2>
      <p class="addi-status is-neutral"><?= $h($status['upload_limit'] ?? '') ?> per upload</p>
      <p class="addi-muted">Server request limit: <?= $h($status['post_limit'] ?? '') ?></p>
    </article>
  </section>

  <section class="addi-card">
    <div class="addi-card-head">
      <div>
        <h2>Install Add-on ZIP</h2>
        <p class="addi-muted">Upload a market-style ZIP. The installer detects the add-on folder that contains <code>addon.setup.php</code>.</p>
      </div>
      <p class="addi-actions addi-actions-inline">
        <a class="button button--default" href="<?= $h($packages_url) ?>">View Packages</a>
        <a class="button button--default" href="<?= $h($docs_url) ?>">Documentation</a>
      </p>
    </div>

    <form method="post" enctype="multipart/form-data" action="">
      <?php if (! empty($csrf_token)): ?>
        <input type="hidden" name="csrf_token" value="<?= $h($csrf_token) ?>">
        <input type="hidden" name="XID" value="<?= $h($csrf_token) ?>">
      <?php endif; ?>
      <input type="hidden" name="install_package" value="1">

      <div class="addi-upload">
        <label>
          <span>ZIP package</span>
          <input type="file" name="addon_package" accept=".zip,application/zip,application/x-zip-compressed" required>
        </label>

        <label class="addi-check">
          <input type="checkbox" name="overwrite_existing" value="1">
          <span>Overwrite an existing add-on folder with the same short name</span>
        </label>

        <label class="addi-check" style="margin-top:8px">
          <input type="checkbox" name="override_requirements" value="1"
                 onchange="var r=document.getElementById('addi-override-reason'); if(r){r.style.display=this.checked?'block':'none';}">
          <span>
            <strong style="color:#b45309">Override version requirements</strong>
            — force-install even if the add-on declares a newer PHP / EE
            than this server runs. Only do this if you've verified the
            add-on actually runs on this environment; we patch the
            declared requirement so EE accepts it and flag the add-on as
            overridden.
          </span>
        </label>

        <div id="addi-override-reason" style="display:none;margin-top:6px">
          <input type="text" name="override_reason" maxlength="200"
                 placeholder="Optional: why is this override safe? (recorded in the audit log)"
                 style="width:100%;padding:5px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:12.5px">
        </div>
      </div>

      <p class="addi-actions">
        <button class="button button--primary" type="submit">Upload ZIP</button>
        <a class="button button--default" href="<?= $h($manager_url) ?>">Open Add-on Manager</a>
      </p>
    </form>
  </section>

  <section class="addi-card">
    <h2>Package Requirements</h2>
    <ul class="addi-list">
      <li>The ZIP must contain one add-on folder with <code>addon.setup.php</code>.</li>
      <li>Wrapper folders are allowed; the folder containing <code>addon.setup.php</code> is detected automatically.</li>
      <li>Folder names must use lowercase letters, numbers, and underscores.</li>
      <li>After extraction, ExpressionEngine still controls the final add-on install/update step.</li>
    </ul>
  </section>
</div>
