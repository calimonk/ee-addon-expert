<?php
$h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$values     = $values ?? [];
$csrfToken  = $csrf_token ?? '';
$target       = (string) ($values['custom_menu_target'] ?? 'releases');
$showCount    = ($values['custom_menu_show_count'] ?? 'y') === 'y';
$showInMenu   = ($values['show_in_custom_menu'] ?? 'y') === 'y';
$menuLabel    = (string) ($values['custom_menu_label'] ?? 'Addons');
$autoFinalize = ($values['auto_finalize'] ?? 'y') === 'y';
$lazyRefresh  = ($values['lazy_refresh'] ?? 'y') === 'y';
?>
<div class="addi-wrap">
  <p class="addi-toolbar">
    <a class="button button--default" href="<?= $h($manager_url) ?>">Add-on Manager</a>
    <a class="button button--default" href="<?= $h($docs_url) ?>">Documentation</a>
  </p>

  <section class="addi-card">
    <h2>Settings</h2>
    <p class="addi-muted" style="font-size:13px">
      These settings control behaviour of the Addon Expert Custom-menu
      integration. The admin still needs to add <strong>Addon Expert</strong>
      to the Custom menu via <code>Settings → Menu Manager</code>
      (<code>/cp/settings/menu-manager/</code>); this screen only configures
      what we render once they've done so.
    </p>

    <form method="post" action="<?= $h($save_url) ?>">
      <?php if ($csrfToken !== ''): ?>
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="XID" value="<?= $h($csrfToken) ?>">
      <?php endif; ?>
      <input type="hidden" name="save_settings" value="1">

      <fieldset style="border:1px solid #e2e8f0;border-radius:6px;padding:14px 18px;margin:0 0 18px">
        <legend style="padding:0 8px;font-weight:600;color:#1e293b">Custom-menu integration</legend>

        <label style="display:block;margin:8px 0 14px">
          <input type="checkbox" name="show_in_custom_menu" value="y" <?= $showInMenu ? 'checked' : '' ?>>
          <strong>Surface Addon Expert in the CP Custom menu</strong>
          <div class="addi-muted" style="font-size:12px;margin-left:22px">
            When unchecked, the <code>cp_custom_menu</code> hook stays
            registered but renders nothing. Useful for temporarily silencing
            the entry without uninstalling.
          </div>
        </label>

        <div style="margin:8px 0 14px">
          <strong style="display:block;margin-bottom:4px">Menu link target</strong>
          <label style="display:block;margin:4px 0">
            <input type="radio" name="custom_menu_target" value="releases" <?= $target === 'releases' ? 'checked' : '' ?>>
            <strong>Releases</strong>
            <span class="addi-muted" style="font-size:12px"> — pending GitHub updates + trust state. Recommended.</span>
          </label>
          <label style="display:block;margin:4px 0">
            <input type="radio" name="custom_menu_target" value="packages" <?= $target === 'packages' ? 'checked' : '' ?>>
            <strong>Packages</strong>
            <span class="addi-muted" style="font-size:12px"> — all installed packages with badges.</span>
          </label>
          <label style="display:block;margin:4px 0">
            <input type="radio" name="custom_menu_target" value="index" <?= $target === 'index' ? 'checked' : '' ?>>
            <strong>Install ZIP</strong>
            <span class="addi-muted" style="font-size:12px"> — the upload screen.</span>
          </label>
        </div>

        <div style="margin:8px 0 14px">
          <strong style="display:block;margin-bottom:4px">Custom-menu label</strong>
          <input
            type="text"
            name="custom_menu_label"
            value="<?= $h($menuLabel) ?>"
            maxlength="40"
            placeholder="Addons"
            style="width:280px;padding:5px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px"
          >
          <div class="addi-muted" style="font-size:12px;margin-top:4px">
            Text the Custom-menu entry shows. EE's Custom sidebar is
            narrow — keep it short. Default "Addons" matches the style
            of other addons in the sidebar (e.g. "Edge Cache").
          </div>
        </div>

        <label style="display:block;margin:8px 0 4px">
          <input type="checkbox" name="custom_menu_show_count" value="y" <?= $showCount ? 'checked' : '' ?>>
          <strong>Append pending-update count to the label</strong>
          <div class="addi-muted" style="font-size:12px;margin-left:22px">
            Label becomes "<?= $h($menuLabel) ?> (3)" when there are pending
            GitHub updates. EE's Custom menu doesn't support badge widgets,
            so the count is embedded in the label text.
          </div>
        </label>
      </fieldset>

      <fieldset style="border:1px solid #e2e8f0;border-radius:6px;padding:14px 18px;margin:0 0 18px">
        <legend style="padding:0 8px;font-weight:600;color:#1e293b">Automation</legend>

        <label style="display:block;margin:8px 0 14px">
          <input type="checkbox" name="auto_finalize" value="y" <?= $autoFinalize ? 'checked' : '' ?>>
          <strong>Auto-finalize EE-side updates after one-click install</strong>
          <div class="addi-muted" style="font-size:12px;margin-left:22px">
            After the orange "Install vX.Y.Z" button swaps files on disk,
            run EE's update flow (upd::update + DB version bump) on the
            next CP request. Without this, you'd have to click "Update"
            on the matching card under Developer → Add-Ons by hand for
            every install. Failures are audit-logged and retried up to
            3 times.
          </div>
        </label>

        <label style="display:block;margin:8px 0 4px">
          <input type="checkbox" name="lazy_refresh" value="y" <?= $lazyRefresh ? 'checked' : '' ?>>
          <strong>Refresh stale release caches when loading the Releases screen</strong>
          <div class="addi-muted" style="font-size:12px;margin-left:22px">
            Every time you load the Releases screen, any mapping whose
            release cache is older than 1h gets refreshed in parallel
            (single round-trip via curl_multi). The explicit
            "Check for updates" button still forces a full refresh.
            Disable to keep the cache static between manual clicks.
          </div>
        </label>
      </fieldset>

      <button class="button button--primary" type="submit">Save settings</button>
    </form>
  </section>
</div>
