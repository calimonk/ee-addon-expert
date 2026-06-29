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

$registryHostsV        = $registry_hosts ?? [];
$registryDefault       = (string) ($registry_default ?? '');
$registryDefaultLocked = ! empty($registry_default_locked);
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

  <section class="addi-card">
    <h2>Registry license keys</h2>
    <p class="addi-muted" style="font-size:13px">
      Private, license-gated add-ons fetch updates from a vendor's release
      registry instead of GitHub. You hold a <strong>license key per
      vendor</strong>; the vendor's endpoint validates it and returns a
      signed, checksum-verified download. Enter the key once per vendor host
      below. Keys set in <code>config.php</code>
      (<code>$config['addon_expert_registry_key']</code> or the per-host
      <code>addon_expert_registry_keys</code> array) or the
      <code>ADDON_EXPERT_REGISTRY_KEY</code> environment variable take
      precedence over anything saved here.
    </p>

    <form method="post" action="<?= $h($save_url) ?>">
      <?php if ($csrfToken !== ''): ?>
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="XID" value="<?= $h($csrfToken) ?>">
      <?php endif; ?>
      <input type="hidden" name="save_registry_keys" value="1">

      <fieldset style="border:1px solid #e2e8f0;border-radius:6px;padding:14px 18px;margin:0 0 18px">
        <legend style="padding:0 8px;font-weight:600;color:#1e293b">Default key</legend>
        <div style="margin:8px 0 4px">
          <input
            type="text"
            name="registry_key_default"
            value="<?= $h($registryDefault) ?>"
            <?= $registryDefaultLocked ? 'disabled' : '' ?>
            placeholder="Applies to any registry host without a specific key"
            autocomplete="off"
            spellcheck="false"
            style="width:100%;max-width:520px;padding:5px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;font-family:monospace"
          >
          <div class="addi-muted" style="font-size:12px;margin-top:4px">
            <?php if ($registryDefaultLocked): ?>
              Currently provided by config or environment — that value
              overrides this field.
            <?php else: ?>
              Optional. Most useful when all your registry add-ons come from
              one vendor (e.g. your own Codebit master key).
            <?php endif; ?>
          </div>
        </div>
      </fieldset>

      <?php if (! empty($registryHostsV)): ?>
        <fieldset style="border:1px solid #e2e8f0;border-radius:6px;padding:14px 18px;margin:0 0 18px">
          <legend style="padding:0 8px;font-weight:600;color:#1e293b">Per-vendor keys</legend>
          <?php foreach ($registryHostsV as $rh): ?>
            <?php
              $rhHost   = (string) ($rh['host'] ?? '');
              $rhKey    = (string) ($rh['stored_key'] ?? '');
              $rhLocked = ! empty($rh['locked']);
              $rhHasKey = ! empty($rh['has_key']);
              $rhAddons = array_values(array_unique((array) ($rh['addons'] ?? [])));
            ?>
            <div style="margin:8px 0 16px">
              <strong style="display:block;color:#1e293b"><?= $h($rhHost) ?></strong>
              <?php if (! empty($rhAddons)): ?>
                <div class="addi-muted" style="font-size:12px;margin-bottom:4px">
                  Used by: <?= $h(implode(', ', $rhAddons)) ?>
                </div>
              <?php endif; ?>
              <input
                type="text"
                name="registry_key[<?= $h($rhHost) ?>]"
                value="<?= $h($rhKey) ?>"
                <?= $rhLocked ? 'disabled' : '' ?>
                placeholder="License key for <?= $h($rhHost) ?>"
                autocomplete="off"
                spellcheck="false"
                style="width:100%;max-width:520px;padding:5px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;font-family:monospace"
              >
              <div class="addi-muted" style="font-size:12px;margin-top:4px">
                <?php if ($rhLocked): ?>
                  Currently provided by config or environment — that value
                  overrides this field.
                <?php elseif (! $rhHasKey): ?>
                  No key set — updates from this vendor are paused until you
                  add one.
                <?php else: ?>
                  Key saved. Entitlement is checked by the vendor at update
                  time.
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </fieldset>
      <?php else: ?>
        <p class="addi-muted" style="font-size:12px">
          No installed add-on currently declares a registry source. When one
          does, its vendor host appears here for a key.
        </p>
      <?php endif; ?>

      <button class="button button--primary" type="submit">Save license keys</button>
    </form>
  </section>
</div>
