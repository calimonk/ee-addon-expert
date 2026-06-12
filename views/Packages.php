<?php
$h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$packages = $packages ?? [];
$csrfToken = $csrf_token ?? '';
?>
<div class="addi-wrap">
  <?php include __DIR__ . '/_finalize_banner.php'; ?>

  <p class="addi-toolbar">
    <a class="button button--primary" href="<?= $h($upload_url) ?>">Install ZIP</a>
    <a class="button button--default" href="<?= $h($docs_url) ?>">Documentation</a>
    <a class="button button--default" href="<?= $h($manager_url) ?>">Add-on Manager</a>
  </p>

  <section class="addi-card">
    <h2>Detected Add-on Packages</h2>

    <?php if (empty($packages)): ?>
      <p class="addi-muted">No add-on packages with an <code>addon.setup.php</code> file were found.</p>
    <?php else: ?>
      <div class="addi-package-grid">
        <?php foreach ($packages as $package): ?>
          <article class="addi-package-card">
            <header class="addi-package-head">
              <div>
                <h3><?= $h($package['name'] ?? '') ?></h3>
                <code><?= $h($package['short_name'] ?? '') ?></code>
              </div>
              <?php if (! empty($package['remote_update_available'])): ?>
                <a class="addi-tag is-update"
                   href="<?= $h($package['remote_release_url'] ?? '') ?>"
                   target="_blank" rel="noopener"
                   style="background:#f59e0b;color:#fff;text-decoration:none"
                   title="A newer release exists on GitHub. Click to view release notes.">
                  GitHub: v<?= $h($package['remote_version'] ?? '') ?> ↗
                </a>
              <?php elseif (! empty($package['update_available'])): ?>
                <span class="addi-tag is-update">Update Available</span>
              <?php elseif (! empty($package['is_installed'])): ?>
                <span class="addi-tag is-installed">Installed</span>
              <?php else: ?>
                <span class="addi-tag is-not-installed">Not Installed</span>
              <?php endif; ?>
            </header>

            <?php if (! empty($package['description'])): ?>
              <p class="addi-package-description"><?= $h($package['description']) ?></p>
            <?php endif; ?>

            <dl class="addi-package-meta">
              <div>
                <dt>Version</dt>
                <dd><?= $h($package['version'] !== '' ? $package['version'] : 'Unknown') ?></dd>
              </div>
              <div>
                <dt>Author</dt>
                <dd><?= $h($package['author'] !== '' ? $package['author'] : 'Unknown') ?></dd>
              </div>
            </dl>

            <footer class="addi-package-actions">
              <?php if (empty($package['is_installed']) && ! empty($package['install_url'])): ?>
                <form class="addi-inline-form" method="post" action="<?= $h($package['install_url']) ?>">
                  <?php if ($csrfToken !== ''): ?>
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                    <input type="hidden" name="XID" value="<?= $h($csrfToken) ?>">
                  <?php endif; ?>
                  <button class="button button--primary addi-icon-button" type="submit" title="Install <?= $h($package['name'] ?? 'add-on') ?>" aria-label="Install <?= $h($package['name'] ?? 'add-on') ?>">
                    <i class="fal fa-plus-circle" aria-hidden="true"></i>
                  </button>
                </form>
              <?php endif; ?>
              <?php if (! empty($package['remote_update_available']) && ! empty($package['remote_install_url'])): ?>
                <form class="addi-inline-form"
                      method="post"
                      action="<?= $h($package['remote_install_url']) ?>"
                      onsubmit="return confirm('Download and replace <?= $h($package['name'] ?? 'this add-on') ?> with the latest GitHub release (v<?= $h($package['remote_version'] ?? '') ?>)? The previous version will be kept as a backup.');">
                  <?php if ($csrfToken !== ''): ?>
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                    <input type="hidden" name="XID" value="<?= $h($csrfToken) ?>">
                  <?php endif; ?>
                  <input type="hidden" name="install_release" value="1">
                  <input type="hidden" name="short_name" value="<?= $h($package['short_name'] ?? '') ?>">
                  <button class="button button--primary addi-icon-button"
                          type="submit"
                          style="background:#f59e0b;border-color:#f59e0b"
                          title="Update <?= $h($package['name'] ?? 'add-on') ?> from GitHub (v<?= $h($package['remote_version'] ?? '') ?>)"
                          aria-label="Update <?= $h($package['name'] ?? 'add-on') ?> from GitHub">
                    <i class="fal fa-cloud-download-alt" aria-hidden="true"></i>
                  </button>
                </form>
              <?php endif; ?>
              <?php if (! empty($package['update_available']) && ! empty($package['update_url'])): ?>
                <form class="addi-inline-form" method="post" action="<?= $h($package['update_url']) ?>">
                  <?php if ($csrfToken !== ''): ?>
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                    <input type="hidden" name="XID" value="<?= $h($csrfToken) ?>">
                  <?php endif; ?>
                  <button class="button button--primary addi-icon-button" type="submit" title="Update <?= $h($package['name'] ?? 'add-on') ?>" aria-label="Update <?= $h($package['name'] ?? 'add-on') ?>">
                    <i class="fal fa-sync-alt" aria-hidden="true"></i>
                  </button>
                </form>
              <?php endif; ?>
              <a class="button button--primary addi-icon-button" href="<?= $h($package['download_url'] ?? '') ?>" title="Download <?= $h($package['name'] ?? 'add-on') ?>" aria-label="Download <?= $h($package['name'] ?? 'add-on') ?>">
                <i class="fal fa-download" aria-hidden="true"></i>
              </a>
              <?php if (! empty($package['settings_available']) && ! empty($package['settings_url'])): ?>
                <a class="button button--default addi-icon-button" href="<?= $h($package['settings_url']) ?>" title="Settings for <?= $h($package['name'] ?? 'add-on') ?>" aria-label="Settings for <?= $h($package['name'] ?? 'add-on') ?>">
                  <i class="fal fa-cog" aria-hidden="true"></i>
                </a>
              <?php else: ?>
                <span class="button button--default addi-icon-button addi-button-disabled" title="Settings unavailable" aria-disabled="true" aria-label="Settings unavailable">
                  <i class="fal fa-cog" aria-hidden="true"></i>
                </span>
              <?php endif; ?>
              <?php if (! empty($package['is_installed']) && ! empty($package['remove_url'])): ?>
                <form class="addi-inline-form" method="post" action="<?= $h($package['remove_url']) ?>" onsubmit="return confirm('Uninstall <?= $h($package['name'] ?? 'this add-on') ?>?');">
                  <?php if ($csrfToken !== ''): ?>
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                    <input type="hidden" name="XID" value="<?= $h($csrfToken) ?>">
                  <?php endif; ?>
                  <button class="button addi-icon-button addi-icon-danger" type="submit" title="Uninstall <?= $h($package['name'] ?? 'add-on') ?>" aria-label="Uninstall <?= $h($package['name'] ?? 'add-on') ?>">
                    <i class="fal fa-trash-alt" aria-hidden="true"></i>
                  </button>
                </form>
              <?php endif; ?>
            </footer>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
