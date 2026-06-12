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
if (empty($results['finalized']) && empty($results['failed'])) {
    return;
}
$h = $h ?? function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
?>
<section class="addi-card" style="margin-bottom:16px;border-left:4px solid <?= ! empty($results['failed']) ? '#dc2626' : '#16a34a' ?>">
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
</section>
