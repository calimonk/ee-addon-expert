<?php
$h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$entries = $entries ?? [];
$counts  = $counts ?? [];

$eventBadge = function (string $event) {
    switch ($event) {
        case 'install_ok':                return ['background:#16a34a;color:#fff', 'OK'];
        case 'install_failed':            return ['background:#dc2626;color:#fff', 'FAIL'];
        case 'install_blocked':           return ['background:#7c2d12;color:#fff', 'BLOCKED'];
        case 'uninstall':                 return ['background:#b91c1c;color:#fff', 'REMOVED'];
        case 'trust_pinned':              return ['background:#0ea5e9;color:#fff', 'PINNED'];
        case 'trust_reconfirmed_manual':  return ['background:#0284c7;color:#fff', 'RECONFIRM'];
        default:                          return ['background:#64748b;color:#fff', strtoupper($event)];
    }
};
?>
<div class="addi-wrap">
  <p class="addi-toolbar">
    <a class="button button--default" href="<?= $h($releases_url) ?>">Releases</a>
    <a class="button button--default" href="<?= $h($packages_url) ?>">Packages</a>
    <a class="button button--default" href="<?= $h($docs_url) ?>">Documentation</a>
    <a class="button button--default" href="<?= $h($manager_url) ?>">Add-on Manager</a>
  </p>

  <section class="addi-card">
    <h2>Audit Log</h2>
    <p class="addi-muted" style="font-size:13px">
      Append-only log of every install / trust / refresh action. Stored
      as JSONL at <code><?= $h($log_file) ?></code>. Rotates at ~1&nbsp;MB
      to keep on-disk size bounded.
    </p>

    <?php if (! empty($counts)): ?>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 18px">
        <?php foreach ($counts as $event => $n):
          [$badgeStyle, $badgeText] = $eventBadge((string) $event);
        ?>
          <span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:4px;font-size:12px;background:#f1f5f9;color:#1e293b;font-weight:600">
            <span style="padding:2px 6px;border-radius:2px;font-size:10.5px;<?= $h($badgeStyle) ?>"><?= $h($badgeText) ?></span>
            <?= (int) $n ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (empty($entries)): ?>
      <p class="addi-muted">No events recorded yet.</p>
    <?php else: ?>
      <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:6px">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px">
          <thead>
            <tr style="background:#f8fafc;text-align:left;color:#1e293b;font-weight:600">
              <th style="padding:8px 10px;white-space:nowrap">When</th>
              <th style="padding:8px 10px;white-space:nowrap">Event</th>
              <th style="padding:8px 10px;white-space:nowrap">Add-on</th>
              <th style="padding:8px 10px;white-space:nowrap">Repo</th>
              <th style="padding:8px 10px;white-space:nowrap">Version</th>
              <th style="padding:8px 10px;white-space:nowrap">Admin</th>
              <th style="padding:8px 10px;white-space:nowrap">Self?</th>
              <th style="padding:8px 10px">Detail</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($entries as $event):
              $ts = (int) ($event['ts'] ?? 0);
              $when = $ts > 0 ? date('Y-m-d H:i:s', $ts) : '?';
              [$badgeStyle, $badgeText] = $eventBadge((string) ($event['event'] ?? '?'));
              $isSelf = ! empty($event['is_self']);
              $detail = '';
              if (! empty($event['reason']))  $detail .= 'reason=' . $event['reason'] . ' ';
              if (! empty($event['error']))   $detail .= 'error=' . substr($event['error'], 0, 120) . ' ';
              if (! empty($event['source']))  $detail .= 'src=' . $event['source'] . ' ';
              if (! empty($event['trust_state'])) $detail .= 'trust=' . $event['trust_state'] . ' ';
              if (! empty($event['owner_id']))    $detail .= 'owner_id=' . $event['owner_id'] . ' ';
              if (! empty($event['repo_id']))     $detail .= 'repo_id=' . $event['repo_id'] . ' ';
            ?>
            <tr style="border-top:1px solid #f1f5f9">
              <td style="padding:6px 10px;font-family:ui-monospace,Menlo,monospace;color:#475569;white-space:nowrap"><?= $h($when) ?></td>
              <td style="padding:6px 10px;white-space:nowrap">
                <span style="padding:2px 7px;border-radius:3px;font-weight:700;font-size:11px;white-space:nowrap;<?= $h($badgeStyle) ?>"><?= $h($badgeText) ?></span>
              </td>
              <td style="padding:6px 10px;font-family:ui-monospace,Menlo,monospace;white-space:nowrap"><?= $h((string) ($event['short_name'] ?? '—')) ?></td>
              <td style="padding:6px 10px;font-family:ui-monospace,Menlo,monospace;color:#475569;white-space:nowrap"><?= $h((string) ($event['repo'] ?? '—')) ?></td>
              <td style="padding:6px 10px;font-family:ui-monospace,Menlo,monospace;white-space:nowrap"><?= $h((string) ($event['version'] ?? '')) ?></td>
              <td style="padding:6px 10px;white-space:nowrap"><?= $h((string) ($event['admin'] ?? '—')) ?></td>
              <td style="padding:6px 10px;text-align:center;white-space:nowrap">
                <?php if ($isSelf): ?>
                  <span title="Self-update of Addon Expert itself" style="color:#0ea5e9;font-weight:700">●</span>
                <?php else: ?>
                  <span style="color:#cbd5e1">—</span>
                <?php endif; ?>
              </td>
              <td style="padding:6px 10px;color:#64748b;font-size:11.5px;min-width:240px"><?= $h(trim($detail)) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <p class="addi-muted" style="font-size:12px;margin-top:12px">
        Showing the most recent <?= count($entries) ?> events. For longer
        history, grep the JSONL file at
        <code><?= $h($log_file) ?></code>:
      </p>
      <?php
      // Build the grep recipes as a single string then echo once.
      // Inline interpolation got bitten by PHP eating newlines after
      // a closing PHP tag; this avoids the issue entirely.
      // (And no, you can't write literal closing-tag tokens in `//`
      // comments — PHP treats them as the end of the PHP block, even
      // inside what looks like a comment.)
      $grepBlock = implode("\n", [
          'grep \'"event":"install_blocked"\' ' . $log_file,
          'grep \'"is_self":true\' ' . $log_file,
          'grep \'"short_name":"edge_cache_tags"\' ' . $log_file,
      ]);
      ?>
      <pre style="background:#0f172a;color:#e2e8f0;padding:10px 14px;border-radius:6px;font-size:12px;font-family:ui-monospace,Menlo,monospace;line-height:1.6;overflow-x:auto;white-space:pre"><?= $h($grepBlock) ?></pre>
    <?php endif; ?>
  </section>
</div>
