<?php
$h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$packages = $packages ?? [];
$csrfToken = $csrf_token ?? '';

// Segment into: available to install, available to update, installed (current).
$toInstall = [];
$toUpdate  = [];
$installed = [];
foreach ($packages as $p) {
    $isInstalled = ! empty($p['is_installed']);
    $hasUpdate   = ! empty($p['remote_update_available']) || ! empty($p['update_available']);
    if (! $isInstalled) {
        $toInstall[] = $p;
    } elseif ($hasUpdate) {
        $toUpdate[] = $p;
    } else {
        $installed[] = $p;
    }
}

// Hidden CSRF inputs for the inline POST forms.
$csrf = function () use ($csrfToken, $h) {
    if ($csrfToken === '') {
        return '';
    }
    return '<input type="hidden" name="csrf_token" value="' . $h($csrfToken) . '">'
         . '<input type="hidden" name="XID" value="' . $h($csrfToken) . '">';
};

// One package row.
$row = function (array $p) use ($h, $csrf) {
    $name        = (string) ($p['name'] ?? $p['short_name'] ?? '');
    $short       = (string) ($p['short_name'] ?? '');
    $desc        = (string) ($p['description'] ?? '');
    $author      = (string) ($p['author'] ?? '');
    $installed   = ! empty($p['is_installed']);
    $instVer     = (string) ($p['installed_version'] ?? '');
    $diskVer     = (string) ($p['version'] ?? '');
    $shownVer    = $installed ? ($instVer !== '' ? $instVer : $diskVer) : $diskVer;
    $remoteUpd   = ! empty($p['remote_update_available']);
    $remoteVer   = (string) ($p['remote_version'] ?? '');
    $eeUpd       = ! empty($p['update_available']);
    $overridden  = ! empty($p['is_overridden']);
    $compat      = (array) ($p['compat_issues'] ?? []);

    ob_start();
    ?>
    <tr<?= $remoteUpd ? ' class="is-update"' : '' ?>>
      <td class="addi-pkg-name">
        <div class="addi-pkg-title"><?= $h($name) ?></div>
        <code><?= $h($short) ?></code>
        <?php if ($desc !== ''): ?>
          <div class="addi-pkg-desc"><?= $h($desc) ?></div>
        <?php endif; ?>
        <?php if ($overridden):
          $ov = $p['override_info'] ?? [];
          $origStr = [];
          foreach ((array) ($ov['original_requires'] ?? []) as $k => $v) { $origStr[] = $k . ' ' . $v; }
        ?>
          <span class="addi-pkg-flag is-warn" title="Requirements overridden at install<?= ! empty($origStr) ? ' (declared ' . $h(implode(', ', $origStr)) . ')' : '' ?>">⚠ requirement override</span>
        <?php elseif (! empty($compat)): ?>
          <span class="addi-pkg-flag is-bad" title="<?= $h(implode(' ', $compat)) ?>">⚠ incompatible</span>
        <?php endif; ?>
        <?php if (! empty($p['ee7'])):
          $ee7 = $p['ee7'];
          $ee7cls = $ee7['verdict'] === 'good' ? 'is-ok' : ($ee7['verdict'] === 'legacy' ? 'is-bad' : 'is-warn');
          $ee7tip = implode(' · ', array_map(fn($s) => (string) ($s[1] ?? ''), (array) ($ee7['signals'] ?? [])));
        ?>
          <span class="addi-pkg-flag <?= $ee7cls ?>" title="EE7 fit (best-effort) — <?= $h($ee7tip) ?>"><?= $h($ee7['label'] ?? '') ?></span>
        <?php endif; ?>
      </td>
      <td class="addi-pkg-ver">
        <?= $h($shownVer !== '' ? $shownVer : '—') ?>
        <?php if ($remoteUpd && $remoteVer !== ''): ?>
          <span class="addi-pkg-newver" title="Newer release available">&rarr; v<?= $h($remoteVer) ?></span>
        <?php elseif ($eeUpd): ?>
          <span class="addi-pkg-newver" title="An EE update is pending for this add-on">update&nbsp;pending</span>
        <?php endif; ?>
      </td>
      <td class="addi-pkg-author"><?= $h($author !== '' ? $author : '—') ?></td>
      <td>
        <div class="addi-pkg-actions">
          <?php if (! $installed && ! empty($p['install_url'])): ?>
            <form class="addi-inline-form" method="post" action="<?= $h($p['install_url']) ?>">
              <?= $csrf() ?>
              <button class="button button--primary addi-icon-button" type="submit" title="Install <?= $h($name) ?>" aria-label="Install <?= $h($name) ?>">
                <i class="fal fa-plus-circle" aria-hidden="true"></i>
              </button>
            </form>
          <?php endif; ?>

          <?php if ($remoteUpd && ! empty($p['remote_install_url'])): ?>
            <form class="addi-inline-form" method="post" action="<?= $h($p['remote_install_url']) ?>"
                  onsubmit="return confirm('Download and replace <?= $h($name) ?> with the latest release (v<?= $h($remoteVer) ?>)? The previous version will be kept as a backup.');">
              <?= $csrf() ?>
              <input type="hidden" name="install_release" value="1">
              <input type="hidden" name="short_name" value="<?= $h($short) ?>">
              <button class="button button--primary addi-icon-button" type="submit" style="background:#f59e0b;border-color:#f59e0b"
                      title="Update <?= $h($name) ?> to v<?= $h($remoteVer) ?>" aria-label="Update <?= $h($name) ?>">
                <i class="fal fa-cloud-download-alt" aria-hidden="true"></i>
              </button>
            </form>
          <?php endif; ?>

          <?php if ($eeUpd && ! empty($p['update_url'])): ?>
            <form class="addi-inline-form" method="post" action="<?= $h($p['update_url']) ?>">
              <?= $csrf() ?>
              <button class="button button--primary addi-icon-button" type="submit" title="Run EE update for <?= $h($name) ?>" aria-label="Update <?= $h($name) ?>">
                <i class="fal fa-sync-alt" aria-hidden="true"></i>
              </button>
            </form>
          <?php endif; ?>

          <a class="button button--default addi-icon-button" href="<?= $h($p['download_url'] ?? '') ?>" title="Download <?= $h($name) ?> as ZIP" aria-label="Download <?= $h($name) ?>">
            <i class="fal fa-download" aria-hidden="true"></i>
          </a>

          <?php if (! empty($p['settings_available']) && ! empty($p['settings_url'])): ?>
            <a class="button button--default addi-icon-button" href="<?= $h($p['settings_url']) ?>" title="Settings for <?= $h($name) ?>" aria-label="Settings for <?= $h($name) ?>">
              <i class="fal fa-cog" aria-hidden="true"></i>
            </a>
          <?php else: ?>
            <span class="button button--default addi-icon-button addi-button-disabled" title="No settings" aria-disabled="true"><i class="fal fa-cog" aria-hidden="true"></i></span>
          <?php endif; ?>

          <?php if ($installed && ! empty($p['remove_check_url'])): ?>
            <a class="button addi-icon-button addi-icon-danger" href="<?= $h($p['remove_check_url']) ?>" title="Remove <?= $h($name) ?> (checks for usage first)" aria-label="Remove <?= $h($name) ?>">
              <i class="fal fa-trash-alt" aria-hidden="true"></i>
            </a>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php
    return ob_get_clean();
};

$section = function (string $title, array $rows) use ($h, $row) {
    if (empty($rows)) {
        return;
    }
    ?>
    <div class="addi-pkg-section">
      <h3><?= $h($title) ?> <span class="addi-pkg-count"><?= count($rows) ?></span></h3>
      <div class="addi-pkg-tablewrap">
        <table class="addi-pkg-table">
          <thead>
            <tr>
              <th>Add-on</th>
              <th>Version</th>
              <th>Author</th>
              <th class="addi-pkg-actions-head">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $p) { echo $row($p); } ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
};
?>
<div class="addi-wrap">
  <?php include __DIR__ . '/_finalize_banner.php'; ?>

  <p class="addi-toolbar">
    <a class="button button--primary" href="<?= $h($upload_url) ?>">Install ZIP</a>
    <a class="button button--default" href="<?= $h($docs_url) ?>">Documentation</a>
    <a class="button button--default" href="<?= $h($manager_url) ?>">Add-on Manager</a>
  </p>

  <section class="addi-card">
    <h2>Add-on Packages</h2>
    <?php if (empty($packages)): ?>
      <p class="addi-muted">No add-on packages with an <code>addon.setup.php</code> file were found.</p>
    <?php else: ?>
      <?php
        $section('Available to install', $toInstall);
        $section('Available to update', $toUpdate);
        $section('Installed', $installed);
      ?>
    <?php endif; ?>
  </section>
</div>
