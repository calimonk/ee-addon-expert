<?php
$h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$rows      = $rows ?? [];
$csrfToken = $csrf_token ?? '';

$inputCss = 'padding:5px 8px;border:1px solid #cbd5e1;border-radius:4px;font-family:ui-monospace,Menlo,monospace;font-size:12.5px';
?>
<div class="addi-wrap">
  <p class="addi-toolbar">
    <a class="button button--default" href="<?= $h($releases_url) ?>">Releases</a>
    <a class="button button--default" href="<?= $h($settings_url) ?>">Settings</a>
    <a class="button button--default" href="<?= $h($docs_url) ?>">Documentation</a>
    <a class="button button--default" href="<?= $h($manager_url) ?>">Add-on Manager</a>
  </p>

  <section class="addi-card">
    <h2>Update Sources</h2>
    <p class="addi-muted" style="font-size:13px">
      Choose where each installed add-on's updates come from. An add-on whose
      <code>addon.setup.php</code> already declares a source shows it read-only
      (it always wins over anything set here). For everything else, pick a
      <strong>GitHub repo</strong> or a <strong>license-gated registry</strong>.
      Registry sources also need a license key per vendor host — set that under
      <a href="<?= $h($settings_url) ?>">Settings → Registry license keys</a>.
    </p>

    <?php if (empty($rows)): ?>
      <p class="addi-muted">No add-on packages detected.</p>
    <?php else: ?>
      <form method="post" action="<?= $h($save_url) ?>">
        <?php if ($csrfToken !== ''): ?>
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="XID" value="<?= $h($csrfToken) ?>">
        <?php endif; ?>
        <input type="hidden" name="save_sources" value="1">

        <?php
        // One row's markup (buffered so it can be grouped into sections).
        $renderRow = function (array $r, int $i) use ($h, $inputCss) {
          $short    = (string) $r['short_name'];
          $name     = (string) $r['name'];
          $inst     = (string) $r['installed'];
          $isMan    = ! empty($r['is_manifest']);
          $declared = $r['declared'] ?? null;
          $type     = (string) ($r['admin_type'] ?? 'none');
          $repo     = (string) ($r['admin_repo'] ?? '');
          $regUrl   = (string) ($r['admin_reg_url'] ?? '');
          $regProd  = (string) ($r['admin_reg_product'] ?? '');
          ob_start();
          ?>
          <div style="padding:12px 16px;border-top:<?= $i ? '1px solid #f1f5f9' : '0' ?>;background:<?= $i % 2 ? '#f8fafc' : '#fff' ?>">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px">
              <div>
                <span style="font-weight:600;color:#1e293b"><?= $h($name) ?></span>
                <code style="color:#64748b;font-size:12px;margin-left:6px"><?= $h($short) ?></code>
              </div>
              <span class="addi-muted" style="font-size:12px;white-space:nowrap">installed <?= $h($inst !== '' ? $inst : '—') ?></span>
            </div>

            <?php if ($isMan && $declared): ?>
              <div style="margin-top:6px;font-size:12.5px">
                <?php if ($declared['kind'] === 'registry'): ?>
                  <code style="background:#ede9fe;color:#5b21b6;padding:2px 6px;border-radius:3px">registry: <?= $h($declared['host']) ?></code>
                  <span class="addi-muted"> · product <code><?= $h($declared['product']) ?></code></span>
                <?php else: ?>
                  <code style="background:#dcfce7;color:#166534;padding:2px 6px;border-radius:3px"><?= $h($declared['repo']) ?></code>
                <?php endif; ?>
                <span class="addi-muted"> — declared in <code>addon.setup.php</code> (read-only)</span>
              </div>
            <?php else: ?>
              <div style="margin-top:8px;display:flex;flex-direction:column;gap:6px">
                <label style="font-size:12.5px">
                  <input type="radio" name="source_type[<?= $h($short) ?>]" value="none" <?= $type === 'none' ? 'checked' : '' ?>>
                  <span class="addi-muted">Not tracked</span>
                </label>

                <label style="font-size:12.5px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <span><input type="radio" name="source_type[<?= $h($short) ?>]" value="github" <?= $type === 'github' ? 'checked' : '' ?>> <strong>GitHub</strong></span>
                  <input type="text" name="repo[<?= $h($short) ?>]" value="<?= $h($repo) ?>"
                         placeholder="owner/repo" autocomplete="off" spellcheck="false"
                         style="<?= $inputCss ?>;width:240px">
                </label>

                <label style="font-size:12.5px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <span><input type="radio" name="source_type[<?= $h($short) ?>]" value="registry" <?= $type === 'registry' ? 'checked' : '' ?>> <strong>Registry</strong></span>
                  <input type="text" name="registry_url[<?= $h($short) ?>]" value="<?= $h($regUrl) ?>"
                         placeholder="https://vendor/releases/latest" autocomplete="off" spellcheck="false"
                         style="<?= $inputCss ?>;width:300px">
                  <input type="text" name="registry_product[<?= $h($short) ?>]" value="<?= $h($regProd) ?>"
                         placeholder="product slug" autocomplete="off" spellcheck="false"
                         style="<?= $inputCss ?>;width:140px">
                </label>
              </div>
            <?php endif; ?>
          </div>
          <?php
          return ob_get_clean();
        };

        // Configured = a source is declared (manifest, read-only) or set
        // here (admin). Everything else is open to configure.
        $configured = [];
        $open = [];
        foreach ($rows as $r) {
          if (! empty($r['is_manifest']) || (($r['admin_type'] ?? 'none') !== 'none')) {
            $configured[] = $r;
          } else {
            $open[] = $r;
          }
        }
        ?>

        <?php if (! empty($configured)): ?>
          <div class="addi-pkg-section">
            <h3>Configured <span class="addi-pkg-count"><?= count($configured) ?></span></h3>
            <div style="border:1px solid #e2e8f0;border-radius:6px;overflow:hidden">
              <?php foreach ($configured as $i => $r) { echo $renderRow($r, $i); } ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if (! empty($open)): ?>
          <div class="addi-pkg-section">
            <h3>Available to configure <span class="addi-pkg-count"><?= count($open) ?></span></h3>
            <div style="border:1px solid #e2e8f0;border-radius:6px;overflow:hidden">
              <?php foreach ($open as $i => $r) { echo $renderRow($r, $i); } ?>
            </div>
          </div>
        <?php endif; ?>

        <p style="margin-top:14px">
          <button class="button button--primary" type="submit">Save sources</button>
          <span class="addi-muted" style="margin-left:12px;font-size:12.5px">
            Select a type and fill its field; leave on "Not tracked" to clear. Sources declared in <code>addon.setup.php</code> can't be overridden here.
          </span>
        </p>
      </form>
    <?php endif; ?>
  </section>
</div>
