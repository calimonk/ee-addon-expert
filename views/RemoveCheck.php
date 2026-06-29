<?php
$h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$usage     = $usage ?? [];
$csrfToken = $csrf_token ?? '';
$hasUsage  = ! empty($usage['has_usage']);

$kinds = [
    ['key' => 'templates',  'label' => 'Templates',       'risk' => true],
    ['key' => 'snippets',   'label' => 'Snippets',        'risk' => true],
    ['key' => 'fields',     'label' => 'Channel fields',  'risk' => true],
    ['key' => 'extensions', 'label' => 'Extension hooks', 'risk' => false],
];
?>
<div class="addi-wrap">
  <p class="addi-toolbar">
    <a class="button button--default" href="<?= $h($cancel_url) ?>">Packages</a>
  </p>

  <section class="addi-card">
    <h2>Remove <?= $h($name) ?>?</h2>
    <p class="addi-muted" style="font-size:13px">
      Short name <code><?= $h($short) ?></code>. Before uninstalling, Addon Expert checked where this add-on
      is used (its tags <code><?= $h($tag) ?>…}</code>, channel fields, and extension hooks). This is
      <strong>best-effort</strong> — it can't see tags built dynamically or PHP that calls the add-on.
    </p>

    <?php if ($hasUsage): ?>
      <div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:6px;padding:10px 14px;margin:0 0 16px;font-size:13px">
        <strong>⚠ This add-on appears to be in use.</strong>
        Removing it will leave the items below referencing tags/fields that no longer exist —
        templates may render blank or error, and channel field data will be orphaned.
      </div>
    <?php else: ?>
      <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:6px;padding:10px 14px;margin:0 0 16px;font-size:13px">
        <strong>✓ No usage detected.</strong> No templates, snippets, or channel fields reference this add-on — it appears safe to remove.
      </div>
    <?php endif; ?>

    <div style="border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;margin:0 0 18px">
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <tbody>
          <?php foreach ($kinds as $k):
            $u = $usage[$k['key']] ?? ['count' => 0, 'names' => []];
            $count = (int) ($u['count'] ?? 0);
            $names = (array) ($u['names'] ?? []);
            $shown = array_slice($names, 0, 8);
            $more  = $count - count($shown);
            $hit   = $count > 0;
            $tone  = ($hit && $k['risk']) ? '#92400e' : ($hit ? '#475569' : '#94a3b8');
          ?>
          <tr style="border-top:1px solid #f1f5f9">
            <td style="padding:9px 14px;white-space:nowrap;font-weight:600;width:150px;color:#1e293b"><?= $h($k['label']) ?></td>
            <td style="padding:9px 14px;white-space:nowrap;font-variant-numeric:tabular-nums;width:60px;color:<?= $tone ?>;font-weight:600"><?= $count ?></td>
            <td style="padding:9px 14px;color:#475569">
              <?php if (! $hit): ?>
                <span style="color:#94a3b8">none<?= $k['key'] === 'extensions' ? '' : ' found' ?></span>
              <?php else: ?>
                <?= $h(implode(', ', $shown)) ?><?php if ($more > 0): ?> <span class="addi-muted">+<?= $more ?> more</span><?php endif; ?>
                <?php if (! $k['risk']): ?><span class="addi-muted" style="font-size:11.5px"> · removed with the add-on</span><?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <form method="post" action="<?= $h($remove_url) ?>" style="display:inline-flex;gap:10px;align-items:center"
          onsubmit="return confirm('Permanently uninstall <?= $h($name) ?>? This runs EE's native uninstall.');">
      <?php if ($csrfToken !== ''): ?>
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="XID" value="<?= $h($csrfToken) ?>">
      <?php endif; ?>
      <button type="submit" class="button addi-button-danger">
        <i class="fal fa-trash-alt" aria-hidden="true"></i>
        Remove <?= $h($name) ?><?= $hasUsage ? ' anyway' : '' ?>
      </button>
      <a class="button button--default" href="<?= $h($cancel_url) ?>">Cancel</a>
    </form>
  </section>
</div>
