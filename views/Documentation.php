<?php
$h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<div class="addi-wrap">
  <p class="addi-toolbar">
    <a class="button button--primary" href="<?= $h($upload_url) ?>">Install ZIP</a>
    <a class="button button--default" href="<?= $h($packages_url) ?>">Packages</a>
    <a class="button button--default" href="<?= $h($releases_url ?? '') ?>">Releases</a>
    <a class="button button--default" href="<?= $h($audit_url ?? '') ?>">Audit Log</a>
    <a class="button button--default" href="<?= $h($settings_url ?? '') ?>">Settings</a>
    <a class="button button--default" href="<?= $h($manager_url) ?>">Add-on Manager</a>
  </p>

  <section class="addi-card">
    <h2>What Addon Expert does</h2>
    <p class="addi-muted">
      Addon Expert installs, updates, and tracks ExpressionEngine add-ons from
      the control panel. It started as <a href="https://github.com/jfaza/addon-manager-plus" target="_blank" rel="noopener">Addon Manager +</a>
      by Javid Fazaeli (the ZIP uploader below) and adds GitHub release
      tracking, one-click updates, auto-finalize, supply-chain checks, and a
      requirement override. The screens are: <strong>Install ZIP</strong>,
      <strong>Packages</strong>, <strong>Releases</strong>,
      <strong>Audit Log</strong>, and <strong>Settings</strong>.
    </p>
  </section>

  <section class="addi-card">
    <h2>1. Install from ZIP</h2>
    <p class="addi-muted">Upload an add-on ZIP; the real add-on folder is detected from its <code>addon.setup.php</code> and extracted into <code>system/user/addons/</code>.</p>
    <ul class="addi-list">
      <li>The ZIP may contain a wrapper folder, e.g. <code>vendor-package/my_addon/addon.setup.php</code>; the folder holding <code>addon.setup.php</code> is used.</li>
      <li>Loose add-on files at the ZIP root are rejected — there's no folder name to install into.</li>
      <li>The detected folder name must use lowercase letters, numbers, and underscores.</li>
      <li><strong>Overwrite</strong> replaces an existing folder of the same short name.</li>
      <li>The upload is <strong>inspected and scanned before anything is committed</strong> (see "Compatibility &amp; force override" below).</li>
    </ul>
  </section>

  <section class="addi-card">
    <h2>2. Packages</h2>
    <p class="addi-muted">Every detected package as a card. Actions use ExpressionEngine's own install / update / settings / uninstall flow.</p>
    <ul class="addi-list">
      <li>Status badges: <strong>Installed</strong>, <strong>Not Installed</strong>, <strong>Update Available</strong> (newer files on disk than EE recorded).</li>
      <li><code>GitHub: vX.Y.Z ↗</code> badge when a newer release exists on the mapped repo — links to the release.</li>
      <li><strong>⚠ incompatible</strong> when the package's declared PHP/EE requirement isn't met here.</li>
      <li><strong>⚠ requirement override</strong> when an add-on was force-installed past its requirement (shows the original requirement and the scan verdict from force time).</li>
      <li>The <strong>Download</strong> action exports any detected add-on as a ZIP, generated on demand (not stored).</li>
    </ul>
  </section>

  <section class="addi-card">
    <h2>3. Tracking GitHub releases</h2>
    <p class="addi-muted">Point an add-on at a GitHub repo and Addon Expert polls <code>/releases/latest</code>, compares it to the installed version, and surfaces a pending-update badge plus a sidebar count.</p>
    <ul class="addi-list">
      <li><strong>Author-declared:</strong> the add-on's <code>addon.setup.php</code> contains <code>'github_repo' =&gt; 'owner/repo'</code>. Read-only on the Releases screen.</li>
      <li><strong>Admin-mapped:</strong> fill in <code>owner/repo</code> on the Releases screen for any add-on whose author didn't declare one. Saved to <code>system/user/config/addon_expert_mappings.json</code>.</li>
      <li>Results cache for 1 hour; stale entries refresh in parallel when the Releases screen loads. <strong>Check for updates</strong> forces a full refresh.</li>
      <li>GitHub API calls are unauthenticated (public repos only).</li>
    </ul>
  </section>

  <section class="addi-card">
    <h2>4. One-click update &amp; auto-finalize</h2>
    <p class="addi-muted">When a newer release exists, install it in one click — download, safe-extract, atomic swap.</p>
    <ul class="addi-list">
      <li>Prefers a release asset named after the add-on, then any <code>.zip</code> asset, then the source zipball. Download is capped at 100&nbsp;MB.</li>
      <li>The previous version is kept as a backup under <code>system/user/cache/addon_expert/backups/</code> (outside the add-ons directory, so EE doesn't try to load it). One rolling backup per add-on.</li>
      <li>PHP opcache is invalidated for the new files so the next request runs the new code.</li>
      <li><strong>Auto-finalize</strong> runs the add-on's <code>upd.php</code> and bumps the EE version rows, so you don't have to click through Developer → Add-Ons. Works for both GitHub installs and manual ZIP uploads. Toggle in Settings.</li>
    </ul>
  </section>

  <section class="addi-card">
    <h2>5. Supply-chain protection</h2>
    <p class="addi-muted">A GitHub repo can change hands or be deleted and re-claimed under the same name (RepoJacking). Addon Expert pins a trust anchor on first install and verifies it on every install.</p>
    <ul class="addi-list">
      <li>On first install it pins the repo's stable numeric identifiers (owner ID, repo ID, created date) to <code>system/user/config/addon_expert_trust.json</code>.</li>
      <li>Every install re-fetches identity from GitHub (bypassing cache) and <strong>hard-blocks</strong> if it changed — a username rename does <em>not</em> trip it (the numeric ID is stable), only a real ownership transfer or delete+recreate.</li>
      <li>The Releases screen shows the trust state (<code>✓ trusted</code> / <code>⚠ CHANGED</code> / <code>unverified</code>) with a <strong>Reconfirm trust</strong> action for legitimate changes.</li>
      <li>Every install / trust / override event is written to the <strong>Audit Log</strong> (<code>system/user/cache/addon_expert/install.log</code>, JSONL).</li>
    </ul>
  </section>

  <section class="addi-card">
    <h2>6. Compatibility &amp; force override</h2>
    <p class="addi-muted">EE refuses to install an add-on that declares a newer PHP/EE than the server runs. Addon Expert surfaces that verdict earlier and lets you force it when the requirement is over-declared.</p>
    <ul class="addi-list">
      <li>Incompatible uploads are <strong>held</strong> and show a confirm screen with the unmet requirement and a heuristic <strong>feature scan</strong> ("no PHP 8.3 features detected — appears safe to force" vs "uses <code>json_validate()</code> — will fatal").</li>
      <li><strong>Force install</strong> patches the add-on's declared requirement so EE accepts it, records the original, and flags the add-on with a requirement-override badge. The original requirement is preserved and re-applied on future updates.</li>
      <li>The scan is best-effort (it can't see dynamic calls or <code>eval</code>) — verify before relying on it. Forcing an add-on that genuinely uses newer syntax will fatal at runtime.</li>
    </ul>
  </section>

  <section class="addi-card">
    <h2>7. Settings &amp; CP menu</h2>
    <ul class="addi-list">
      <li><strong>Custom-menu integration:</strong> show Addon Expert in the CP Custom menu with a configurable label (default "Addons") and an optional pending-update count, e.g. <code>Addons (3)</code>. You still add the entry once via <strong>Settings → Menu Manager</strong>.</li>
      <li><strong>Auto-finalize</strong> the EE-side update after an install (default on).</li>
      <li><strong>Lazy refresh</strong> of stale release caches when the Releases screen loads (default on).</li>
    </ul>
  </section>

  <section class="addi-card">
    <h2>Where data lives</h2>
    <ul class="addi-list">
      <li><code>system/user/config/addon_expert_mappings.json</code> — admin repo mappings</li>
      <li><code>system/user/config/addon_expert_trust.json</code> — pinned trust anchors</li>
      <li><code>system/user/config/addon_expert_overrides.json</code> — requirement overrides</li>
      <li><code>system/user/config/addon_expert_settings.json</code> — this screen's settings</li>
      <li><code>system/user/cache/addon_expert/</code> — release cache, audit log, backups, and held uploads</li>
    </ul>
  </section>

  <section class="addi-card">
    <h2>Safety checks</h2>
    <ul class="addi-list">
      <li>ZIP support requires PHP <code>ZipArchive</code>; GitHub downloads require <code>cURL</code>.</li>
      <li>Absolute paths, drive-letter paths, and <code>..</code> traversal are rejected before extraction.</li>
      <li>Uploaded add-on PHP is never executed to read its metadata — it's parsed as text.</li>
      <li>ExpressionEngine still controls the final install / update step (with its own permission and CSRF checks).</li>
      <li>Do not grant control panel access to untrusted users — extracted add-on code runs with full add-on privileges.</li>
    </ul>
  </section>
</div>
