<?php
$h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$packages       = $packages ?? [];
$csrfToken      = $csrf_token ?? '';
$updatesCount   = (int) ($updates_count ?? 0);
$finalizeResults = $finalize_results ?? null;

$fmtAge = function (int $ts): string {
    if ($ts <= 0) return 'never';
    $delta = time() - $ts;
    if ($delta < 60)    return $delta . 's ago';
    if ($delta < 3600)  return floor($delta / 60) . 'm ago';
    if ($delta < 86400) return floor($delta / 3600) . 'h ago';
    return floor($delta / 86400) . 'd ago';
};

// Small, safe markdown -> HTML for release notes in the changelog modal:
// escape first, then a few block/inline rules.
$mdToHtml = function (string $md) use ($h) {
    $inline = function ($s) use ($h) {
        $s = $h($s);
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
        $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
            return '<a href="' . str_replace('"', '%22', $m[2]) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
        }, $s);
        return $s;
    };
    $html = '';
    $inList = false;
    foreach (preg_split('/\r?\n/', $md) as $raw) {
        $line = rtrim((string) $raw);
        if (preg_match('/^#{1,}\s+(.+)/', $line, $m)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<h4 style="margin:12px 0 4px;font-size:13px">' . $inline($m[1]) . '</h4>';
        } elseif (preg_match('/^[-*]\s+(.+)/', $line, $m)) {
            if (! $inList) { $html .= '<ul style="margin:4px 0 8px;padding-left:18px">'; $inList = true; }
            $html .= '<li>' . $inline($m[1]) . '</li>';
        } elseif (trim($line) === '') {
            if ($inList) { $html .= '</ul>'; $inList = false; }
        } else {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<p style="margin:4px 0">' . $inline($line) . '</p>';
        }
    }
    if ($inList) { $html .= '</ul>'; }
    return $html;
};
?>
<div class="addi-wrap">
  <p class="addi-toolbar">
    <a class="button button--default" href="<?= $h($packages_url) ?>">Packages</a>
    <a class="button button--default" href="<?= $h($docs_url) ?>">Documentation</a>
    <a class="button button--default" href="<?= $h($manager_url) ?>">Add-on Manager</a>
  </p>

  <?php include __DIR__ . '/_finalize_banner.php'; ?>

  <section class="addi-card">
    <h2>Release Tracking</h2>
    <p class="addi-muted">
      For each installed add-on, point Addon Expert at a GitHub repo. The
      latest release is polled and compared against the on-disk version. Add-ons
      whose <code>addon.setup.php</code> declares <code>'github_repo' =&gt; 'owner/repo'</code>
      are mapped automatically — you only need to fill in the rest. Private,
      paid add-ons that declare a <code>registry</code> source are tracked the
      same way through a license-gated vendor endpoint — set the license key
      under <a href="<?= $h($settings_url ?? '') ?>">Settings</a>.
    </p>

    <p>
      <?php if ($updatesCount > 0): ?>
        <strong style="color:#b45309"><?= $updatesCount ?> update<?= $updatesCount === 1 ? '' : 's' ?> available</strong>
        across tracked add-ons.
      <?php else: ?>
        <span class="addi-muted">No updates available right now.</span>
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
        Polls every mapped repo once. Cached for 1h; stale entries also refresh when this screen loads.
      </span>
    </form>

    <?php if (empty($packages)): ?>
      <p class="addi-muted">No add-on packages detected.</p>
    <?php else: ?>
        <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:6px">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead>
            <tr style="background:#f8fafc;text-align:left;color:#1e293b;font-weight:600">
              <th style="padding:9px 12px;white-space:nowrap">Add-on</th>
              <th style="padding:9px 12px;white-space:nowrap">Installed</th>
              <th style="padding:9px 12px;min-width:220px">Source</th>
              <th style="padding:9px 12px;white-space:nowrap">Latest release</th>
              <th style="padding:9px 12px;white-space:nowrap">Checked</th>
              <th style="padding:9px 12px;white-space:nowrap" title="Trust on first use — does the GitHub repo identity (owner ID, repo ID, created_at) still match what was pinned on first install?">Trust</th>
              <th style="padding:9px 12px;white-space:nowrap">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php
            // Surface the updatable add-ons in their own group at the top.
            $updates = [];
            $rest = [];
            foreach ($packages as $p2) {
              if (! empty($p2['remote_update_available']) || ! empty($p2['update_available'])) {
                $updates[] = $p2;
              } else {
                $rest[] = $p2;
              }
            }
            $ordered = array_merge($updates, $rest);
            $nUpd = count($updates);
            $relIndex = 0;
            foreach ($ordered as $pkg):
              if ($relIndex === 0 && $nUpd > 0) {
                echo '<tr><td colspan="7" style="padding:8px 14px;background:#fffbeb;border-top:1px solid #f1f5f9;font-weight:700;color:#92400e;font-size:12.5px">Updates available (' . (int) $nUpd . ')</td></tr>';
              } elseif ($relIndex === $nUpd && ! empty($rest)) {
                echo '<tr><td colspan="7" style="padding:8px 14px;background:#f8fafc;border-top:1px solid #f1f5f9;font-weight:700;color:#475569;font-size:12.5px">All tracked add-ons (' . count($rest) . ')</td></tr>';
              }
              $relIndex++;
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

              $trustState = (string) ($pkg['remote_trust_state'] ?? 'none');
              $trustDiff  = (array) ($pkg['remote_trust_diff'] ?? []);
              $trustPinned = $pkg['remote_trust_pinned'] ?? null;

              $kind        = (string) ($pkg['remote_kind'] ?? '');
              $isRegistry  = $kind === 'registry';
              $regUrl      = (string) ($pkg['remote_registry_url'] ?? '');
              $regProduct  = (string) ($pkg['remote_registry_product'] ?? '');
              $regHost     = $regUrl !== '' ? (string) parse_url($regUrl, PHP_URL_HOST) : '';
              $keyPresent  = ! empty($pkg['remote_key_present']);

              $statusLabel = [
                'unconfigured'  => $isRegistry ? 'License key required' : 'Not tracked',
                'never_checked' => $isRegistry ? 'Configured (never checked)' : 'Mapped (never checked)',
                'stale'         => 'Stale',
                'fresh'         => 'Fresh',
                'error'         => $isRegistry ? 'Registry refused / unreachable' : 'Last fetch failed',
              ][$status] ?? $status;

              // Trust mismatch is the loudest signal — paints the row red
              // and replaces the install action with a Reconfirm prompt.
              $isTrustChanged = $trustState === 'changed';
              $rowBg = $isTrustChanged
                ? '#fee2e2'
                : (! empty($pkg['remote_update_available']) ? '#fef3c7' : 'transparent');
            ?>
            <tr style="border-top:1px solid #f1f5f9;background:<?= $rowBg ?>">
              <td style="padding:8px 12px;white-space:nowrap">
                <div style="font-weight:600"><?= $h($name) ?></div>
                <code style="color:#64748b;font-size:12px"><?= $h($short) ?></code>
              </td>
              <td style="padding:8px 12px;font-family:ui-monospace,Menlo,monospace;font-size:12.5px;white-space:nowrap">
                <?= $h($installed !== '' ? $installed : '—') ?>
              </td>
              <td style="padding:8px 12px">
                <?php if ($isRegistry): ?>
                  <code style="background:#ede9fe;color:#5b21b6;padding:2px 6px;border-radius:3px">registry: <?= $h($regHost) ?></code>
                  <div style="font-size:11px;color:#64748b;margin-top:2px">
                    product <code><?= $h($regProduct) ?></code> · <?= $isManifest ? 'declared in <code>addon.setup.php</code>' : 'set on Update Sources' ?>
                  </div>
                <?php elseif ($remoteRepo !== ''): ?>
                  <code style="background:#dcfce7;color:#166534;padding:2px 6px;border-radius:3px"><?= $h($remoteRepo) ?></code>
                  <div style="font-size:11px;color:#64748b;margin-top:2px"><?= $isManifest ? 'declared in <code>addon.setup.php</code>' : 'set on Update Sources' ?></div>
                <?php else: ?>
                  <span style="color:#94a3b8">Not tracked</span>
                  <a href="<?= $h($sources_url ?? '') ?>" style="font-size:11px;margin-left:6px">Set a source</a>
                <?php endif; ?>
              </td>
              <td style="padding:8px 12px;font-family:ui-monospace,Menlo,monospace;font-size:12.5px;white-space:nowrap">
                <?php if ($remoteVer !== ''): ?>
                  <?php if ($remoteUrl !== ''): ?>
                    <a href="<?= $h($remoteUrl) ?>" target="_blank" rel="noopener"><?= $h($remoteVer) ?> ↗</a>
                  <?php else: ?>
                    <?= $h($remoteVer) ?>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="color:#94a3b8">—</span>
                <?php endif; ?>
                <?php
                  // Full-changelog link: registry products → the live worker
                  // changelog; GitHub sources → the repo's releases page.
                  $clUrl = '';
                  if ($isRegistry && $regProduct !== '' && $regUrl !== '') {
                    $clUrl = parse_url($regUrl, PHP_URL_SCHEME) . '://' . parse_url($regUrl, PHP_URL_HOST) . '/changelog/' . $regProduct;
                  } elseif ($remoteRepo !== '') {
                    $clUrl = 'https://github.com/' . $remoteRepo . '/releases';
                  }
                  $notes = (string) ($pkg['remote_notes'] ?? '');
                ?>
                <?php if ($notes !== ''): ?>
                  <div style="margin-top:3px">
                    <a href="<?= $h($clUrl !== '' ? $clUrl : '#') ?>" target="_blank" rel="noopener"
                       onclick="aeCl('<?= $h($short) ?>');return false;"
                       style="font-size:11px;color:#5b21b6;text-decoration:none" title="What changed in the latest release">
                      <i class="fal fa-file-alt" aria-hidden="true"></i> changelog
                    </a>
                  </div>
                  <div class="ae-cl-data" id="aecl-<?= $h($short) ?>" data-name="<?= $h($name) ?>" data-full="<?= $h($clUrl) ?>" style="display:none"><?= $mdToHtml($notes) ?></div>
                <?php elseif ($clUrl !== ''): ?>
                  <div style="margin-top:3px">
                    <a href="<?= $h($clUrl) ?>" target="_blank" rel="noopener" style="font-size:11px;color:#5b21b6;text-decoration:none" title="View changelog">
                      <i class="fal fa-file-alt" aria-hidden="true"></i> changelog ↗
                    </a>
                  </div>
                <?php endif; ?>
              </td>
              <td style="padding:8px 12px;color:#64748b;font-size:12px;white-space:nowrap">
                <?= $h($fmtAge($checkedAt)) ?>
              </td>
              <td style="padding:8px 12px;font-size:12px">
                <?php if ($isRegistry): ?>
                  <span style="background:#ede9fe;color:#5b21b6;padding:3px 8px;border-radius:3px;font-weight:600"
                        title="License-gated: the vendor verifies entitlement, the download URL is signed and short-lived, and the package sha256 is verified before install.">
                    license-gated
                  </span>
                <?php elseif ($trustState === 'trusted'): ?>
                  <span style="background:#dcfce7;color:#166534;padding:3px 8px;border-radius:3px;font-weight:600"
                        title="Pinned <?= $h(date('Y-m-d', (int) ($trustPinned['first_seen_at'] ?? 0))) ?> by <?= $h((string) ($trustPinned['pinned_by'] ?? 'unknown')) ?>. owner_id=<?= $h((string) ($trustPinned['owner_id'] ?? '?')) ?>, repo_id=<?= $h((string) ($trustPinned['repo_id'] ?? '?')) ?>">
                    ✓ trusted
                  </span>
                <?php elseif ($trustState === 'changed'): ?>
                  <span style="background:#dc2626;color:#fff;padding:3px 8px;border-radius:3px;font-weight:700"
                        title="<?php
                          $tips = [];
                          foreach ($trustDiff as $field => $pair) {
                            $tips[] = $field . ': ' . var_export($pair['pinned'] ?? '?', true) . ' → ' . var_export($pair['observed'] ?? '?', true);
                          }
                          echo $h(implode("\n", $tips));
                        ?>">
                    ⚠ CHANGED
                  </span>
                <?php elseif ($trustState === 'unverified'): ?>
                  <span style="background:#fef3c7;color:#78350f;padding:3px 8px;border-radius:3px;font-weight:600"
                        title="No identity anchor pinned yet. The next install will pin one.">
                    unverified
                  </span>
                <?php else: ?>
                  <span style="color:#94a3b8">—</span>
                <?php endif; ?>
              </td>
              <td style="padding:8px 12px;font-size:12px">
                <?php if ($isTrustChanged): ?>
                  <form method="post"
                        action="<?= $h($refresh_url) ?>"
                        style="margin:0"
                        onsubmit="return confirm('This will pin the NEW repo identity for <?= $h($short) ?>. Only proceed if you have verified on GitHub that the change is legitimate (e.g., the maintainer transferred ownership, or you have explicitly migrated to a new fork). Continue?');">
                    <?php if ($csrfToken !== ''): ?>
                      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                      <input type="hidden" name="XID" value="<?= $h($csrfToken) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="reconfirm_trust" value="1">
                    <input type="hidden" name="short_name" value="<?= $h($short) ?>">
                    <button type="submit"
                            style="background:#dc2626;color:#fff;border:0;padding:4px 10px;border-radius:3px;font-weight:700;font-size:12px;cursor:pointer">
                      Reconfirm trust
                    </button>
                  </form>
                <?php elseif (! empty($pkg['remote_update_available']) && ! empty($pkg['remote_install_url']) && (! $isRegistry || $keyPresent)):
                  $confirmSrc = $isRegistry
                    ? ('from ' . $regHost . ' (license-gated; the package checksum is verified before install)')
                    : 'from GitHub';
                ?>
                  <form method="post"
                        action="<?= $h($pkg['remote_install_url']) ?>"
                        style="margin:0"
                        onsubmit="return confirm('Download and replace <?= $h($name) ?> with v<?= $h($remoteVer) ?> <?= $h($confirmSrc) ?>? The previous version will be kept as a backup.');">
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
                <?php elseif ($isRegistry && ! empty($pkg['remote_update_available']) && ! $keyPresent): ?>
                  <a href="<?= $h($settings_url ?? '') ?>"
                     style="background:#5b21b6;color:#fff;padding:4px 10px;border-radius:3px;font-weight:600;font-size:12px;text-decoration:none"
                     title="An update is available but no license key is configured for <?= $h($regHost) ?>.">
                    Add license key
                  </a>
                <?php elseif (! empty($pkg['remote_update_available'])): ?>
                  <span style="background:#f59e0b;color:#fff;padding:3px 8px;border-radius:3px;font-weight:600">Update available</span>
                <?php elseif ($isRegistry && ! $keyPresent): ?>
                  <a href="<?= $h($settings_url ?? '') ?>"
                     style="color:#5b21b6;font-weight:600;text-decoration:none"
                     title="Add a license key for <?= $h($regHost) ?> to check for updates.">
                    License key required
                  </a>
                <?php else: ?>
                  <?= $h($statusLabel) ?>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>

        <p style="margin-top:14px" class="addi-muted">
          <span style="font-size:12.5px">
            Configure where each add-on updates from on
            <a href="<?= $h($sources_url ?? '') ?>">Update Sources</a>.
          </span>
        </p>
    <?php endif; ?>
  </section>

  <div id="ae-cl-ov" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9998" onclick="aeClClose()"></div>
  <div id="ae-cl-box" role="dialog" aria-modal="true" style="display:none;position:fixed;z-index:9999;left:50%;top:50%;transform:translate(-50%,-50%);width:min(640px,92vw);max-height:80vh;overflow:auto;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 12px 40px rgba(15,23,42,.25);padding:20px 24px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:6px">
      <h3 id="ae-cl-title" style="margin:0;font-size:16px;color:#1e293b"></h3>
      <button type="button" onclick="aeClClose()" aria-label="Close" style="border:0;background:none;font-size:22px;line-height:1;cursor:pointer;color:#64748b">&times;</button>
    </div>
    <div id="ae-cl-body" style="font-size:13.5px;line-height:1.55;color:#334155"></div>
    <p id="ae-cl-fullwrap" style="margin:14px 0 0;border-top:1px solid #f1f5f9;padding-top:12px">
      <a id="ae-cl-full" href="#" target="_blank" rel="noopener" style="font-size:12.5px;color:#5b21b6">Full changelog ↗</a>
    </p>
  </div>
  <script>
  function aeCl(s){var d=document.getElementById('aecl-'+s);if(!d){return;}
    document.getElementById('ae-cl-title').textContent=(d.getAttribute('data-name')||'')+' — latest release';
    document.getElementById('ae-cl-body').innerHTML=d.innerHTML;
    var full=d.getAttribute('data-full')||'';var fw=document.getElementById('ae-cl-fullwrap');
    if(full){document.getElementById('ae-cl-full').href=full;fw.style.display='';}else{fw.style.display='none';}
    document.getElementById('ae-cl-ov').style.display='block';document.getElementById('ae-cl-box').style.display='block';}
  function aeClClose(){document.getElementById('ae-cl-ov').style.display='none';document.getElementById('ae-cl-box').style.display='none';}
  document.addEventListener('keydown',function(e){if(e.key==='Escape'){aeClClose();}});
  </script>
</div>
