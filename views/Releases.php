<?php
$h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$packages      = $packages ?? [];
$adminMap      = $admin_map ?? [];
$csrfToken     = $csrf_token ?? '';
$updatesCount  = (int) ($updates_count ?? 0);

$fmtAge = function (int $ts): string {
    if ($ts <= 0) return 'never';
    $delta = time() - $ts;
    if ($delta < 60)    return $delta . 's ago';
    if ($delta < 3600)  return floor($delta / 60) . 'm ago';
    if ($delta < 86400) return floor($delta / 3600) . 'h ago';
    return floor($delta / 86400) . 'd ago';
};
?>
<div class="addi-wrap">
  <p class="addi-toolbar">
    <a class="button button--default" href="<?= $h($packages_url) ?>">Packages</a>
    <a class="button button--default" href="<?= $h($docs_url) ?>">Documentation</a>
    <a class="button button--default" href="<?= $h($manager_url) ?>">Add-on Manager</a>
  </p>

  <section class="addi-card">
    <h2>GitHub Release Tracking</h2>
    <p class="addi-muted">
      For each installed add-on, point Addon Manager + at a GitHub repo. The
      latest release is polled and compared against the on-disk version. Add-ons
      whose <code>addon.setup.php</code> declares <code>'github_repo' =&gt; 'owner/repo'</code>
      are mapped automatically — you only need to fill in the rest.
    </p>

    <p>
      <?php if ($updatesCount > 0): ?>
        <strong style="color:#b45309"><?= $updatesCount ?> update<?= $updatesCount === 1 ? '' : 's' ?> available</strong>
        from GitHub across tracked add-ons.
      <?php else: ?>
        <span class="addi-muted">No GitHub updates available right now.</span>
      <?php endif; ?>
    </p>

    <form method="post" action="<?= $h($refresh_url) ?>" style="margin:8px 0 16px">
      <?php if ($csrfToken !== ''): ?>
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="XID" value="<?= $h($csrfToken) ?>">
      <?php endif; ?>
      <input type="hidden" name="refresh_releases" value="1">
      <button class="button button--primary" type="submit">
        <i class="fal fa-sync-alt" aria-hidden="true"></i>
        Check for updates
      </button>
      <span class="addi-muted" style="margin-left:12px;font-size:12.5px">
        Polls every mapped repo once. Cached for 12h afterwards.
      </span>
    </form>

    <?php if (empty($packages)): ?>
      <p class="addi-muted">No add-on packages detected.</p>
    <?php else: ?>
      <form method="post" action="<?= $h($save_url) ?>">
        <?php if ($csrfToken !== ''): ?>
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="XID" value="<?= $h($csrfToken) ?>">
        <?php endif; ?>
        <input type="hidden" name="save_mappings" value="1">

        <table style="width:100%;border-collapse:collapse;font-size:13px;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden">
          <thead>
            <tr style="background:#f8fafc;text-align:left;color:#1e293b;font-weight:600">
              <th style="padding:9px 12px">Add-on</th>
              <th style="padding:9px 12px">Installed</th>
              <th style="padding:9px 12px">GitHub repo</th>
              <th style="padding:9px 12px">Latest release</th>
              <th style="padding:9px 12px">Checked</th>
              <th style="padding:9px 12px">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($packages as $pkg):
              $short      = (string) ($pkg['short_name'] ?? '');
              $name       = (string) ($pkg['name'] ?? $short);
              $installed  = (string) ($pkg['installed_version'] ?? ($pkg['version'] ?? ''));
              $remoteRepo = (string) ($pkg['remote_repo'] ?? '');
              $repoSource = (string) ($pkg['remote_repo_source'] ?? '');
              $remoteVer  = (string) ($pkg['remote_version'] ?? '');
              $remoteUrl  = (string) ($pkg['remote_release_url'] ?? '');
              $checkedAt  = (int) ($pkg['remote_checked_at'] ?? 0);
              $status     = (string) ($pkg['remote_status'] ?? 'unconfigured');
              $isManifest = $repoSource === 'manifest';
              $adminValue = $adminMap[$short] ?? '';

              $statusLabel = [
                'unconfigured'  => 'Not tracked',
                'never_checked' => 'Mapped (never checked)',
                'stale'         => 'Stale',
                'fresh'         => 'Fresh',
                'error'         => 'Last fetch failed',
              ][$status] ?? $status;

              $rowBg = ! empty($pkg['remote_update_available']) ? '#fef3c7' : 'transparent';
            ?>
            <tr style="border-top:1px solid #f1f5f9;background:<?= $rowBg ?>">
              <td style="padding:8px 12px">
                <div style="font-weight:600"><?= $h($name) ?></div>
                <code style="color:#64748b;font-size:12px"><?= $h($short) ?></code>
              </td>
              <td style="padding:8px 12px;font-family:ui-monospace,Menlo,monospace;font-size:12.5px">
                <?= $h($installed !== '' ? $installed : '—') ?>
              </td>
              <td style="padding:8px 12px">
                <?php if ($isManifest): ?>
                  <code style="background:#dcfce7;color:#166534;padding:2px 6px;border-radius:3px"><?= $h($remoteRepo) ?></code>
                  <div style="font-size:11px;color:#64748b;margin-top:2px">declared in <code>addon.setup.php</code></div>
                  <input type="hidden" name="repo[<?= $h($short) ?>]" value="<?= $h($adminValue) ?>">
                <?php else: ?>
                  <input
                    type="text"
                    name="repo[<?= $h($short) ?>]"
                    value="<?= $h($adminValue) ?>"
                    placeholder="owner/repo"
                    style="width:100%;padding:5px 8px;border:1px solid #cbd5e1;border-radius:4px;font-family:ui-monospace,Menlo,monospace;font-size:12.5px"
                  >
                <?php endif; ?>
              </td>
              <td style="padding:8px 12px;font-family:ui-monospace,Menlo,monospace;font-size:12.5px">
                <?php if ($remoteVer !== ''): ?>
                  <?php if ($remoteUrl !== ''): ?>
                    <a href="<?= $h($remoteUrl) ?>" target="_blank" rel="noopener"><?= $h($remoteVer) ?> ↗</a>
                  <?php else: ?>
                    <?= $h($remoteVer) ?>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="color:#94a3b8">—</span>
                <?php endif; ?>
              </td>
              <td style="padding:8px 12px;color:#64748b;font-size:12px">
                <?= $h($fmtAge($checkedAt)) ?>
              </td>
              <td style="padding:8px 12px;font-size:12px">
                <?php if (! empty($pkg['remote_update_available']) && ! empty($pkg['remote_install_url'])): ?>
                  <form method="post"
                        action="<?= $h($pkg['remote_install_url']) ?>"
                        style="margin:0"
                        onsubmit="return confirm('Download and replace <?= $h($name) ?> with v<?= $h($remoteVer) ?> from GitHub? The previous version will be kept as a backup.');">
                    <?php if ($csrfToken !== ''): ?>
                      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                      <input type="hidden" name="XID" value="<?= $h($csrfToken) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="install_release" value="1">
                    <input type="hidden" name="short_name" value="<?= $h($short) ?>">
                    <button type="submit"
                            style="background:#f59e0b;color:#fff;border:0;padding:4px 10px;border-radius:3px;font-weight:600;font-size:12px;cursor:pointer">
                      <i class="fal fa-cloud-download-alt" aria-hidden="true"></i>
                      Install v<?= $h($remoteVer) ?>
                    </button>
                  </form>
                <?php elseif (! empty($pkg['remote_update_available'])): ?>
                  <span style="background:#f59e0b;color:#fff;padding:3px 8px;border-radius:3px;font-weight:600">Update available</span>
                <?php else: ?>
                  <?= $h($statusLabel) ?>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <p style="margin-top:14px">
          <button class="button button--primary" type="submit">Save mappings</button>
          <span class="addi-muted" style="margin-left:12px;font-size:12.5px">
            Repos declared in <code>addon.setup.php</code> are read-only here.
          </span>
        </p>
      </form>
    <?php endif; ?>
  </section>
</div>
