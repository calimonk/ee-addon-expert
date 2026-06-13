<?php
/**
 * Shared finalize-results banner. Include from any route view that
 * passes a `finalize_results` key into setBody().
 *
 *   <?php include __DIR__ . '/_finalize_banner.php'; ?>
 *
 * Expects the caller to have already defined `$h` (htmlspecialchars
 * shortcut) and `$finalize_results` (the result array from
 * AutoFinalizer::finalizeAllPending()). Silent no-op when there's
 * nothing to show.
 */
$results = $finalize_results ?? null;
if ($results === null) {
    return;
}
if (
    empty($results['finalized'])
    && empty($results['failed'])
    && empty($results['skipped'])
) {
    return;
}
$h = $h ?? function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

// Border color reflects the worst outcome. Failed wins; otherwise
// finalized; otherwise skipped (which is informational, not a problem).
$borderColor = '#16a34a';      // green default
if (! empty($results['failed'])) {
    $borderColor = '#dc2626';  // red
} elseif (empty($results['finalized']) && ! empty($results['skipped'])) {
    $borderColor = '#94a3b8';  // grey — purely informational
}

$skipReasonLabel = function (string $reason): string {
    switch ($reason) {
        case 'no_update_needed':
            return 'on-disk version already matches the installed version — nothing to do';
        case 'addon_not_registered':
            return 'addon not registered in EE yet — click Install on the success notice above';
        case 'nothing_to_update':
            return 'no module or extension version row was eligible for bump';
        default:
            return $reason;
    }
};
?>
<section class="addi-card" style="margin-bottom:16px;border-left:4px solid <?= $borderColor ?>">
  <h3 style="margin:0 0 8px;font-size:14px">Auto-finalize results</h3>
  <?php if (! empty($results['finalized'])): ?>
    <p style="margin:4px 0;font-size:13px">
      <strong style="color:#166534">Finalized:</strong>
      <?php foreach ($results['finalized'] as $short => $info): ?>
        <code style="background:#dcfce7;color:#166534;padding:2px 6px;border-radius:3px;margin-right:4px">
          <?= $h($short) ?>
          <?php if (! empty($info['from']) && ! empty($info['to'])): ?>
            <?= $h($info['from']) ?>&nbsp;→&nbsp;<?= $h($info['to']) ?>
          <?php endif; ?>
        </code>
      <?php endforeach; ?>
      <span class="addi-muted" style="font-size:12px;margin-left:4px">— DB versions bumped, migrations run.</span>
    </p>
  <?php endif; ?>
  <?php if (! empty($results['failed'])): ?>
    <p style="margin:4px 0;font-size:13px">
      <strong style="color:#991b1b">Failed:</strong>
      <?php foreach ($results['failed'] as $short => $info): ?>
        <code style="background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:3px;margin-right:4px;font-size:12px"><?= $h($short) ?></code>
        <span style="font-size:12px;color:#991b1b">attempt <?= (int) $info['attempts'] ?>/3 — <?= $h(substr((string) $info['error'], 0, 140)) ?></span><br>
      <?php endforeach; ?>
      <span class="addi-muted" style="font-size:12px">Will retry on next page load. Or finalize manually via Developer → Add-Ons.</span>
    </p>
  <?php endif; ?>
  <?php if (! empty($results['skipped'])): ?>
    <p style="margin:4px 0;font-size:12.5px;color:#475569">
      <strong style="color:#475569">No-op:</strong>
      <?php foreach ($results['skipped'] as $short => $reason): ?>
        <code style="background:#f1f5f9;color:#475569;padding:2px 6px;border-radius:3px;margin-right:4px;font-size:12px"><?= $h($short) ?></code>
        <span style="font-size:12px;color:#64748b">— <?= $h($skipReasonLabel((string) $reason)) ?>.</span><br>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
</section>
