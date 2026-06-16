<?php

namespace JavidFazaeli\AddonInstaller\Service;

use RuntimeException;
use ZipArchive;

/**
 * Downloads a GitHub release zip and lands it on disk so EE's native
 * update flow can run the migration.
 *
 * Three kinds of release packaging we handle:
 *
 *   1. Asset zip following the standard Add-on Manager layout —
 *      `{short_name}/addon.setup.php` at the zip root.
 *   2. Asset zip with a wrapper folder — `wrapper/{short_name}/addon.setup.php`.
 *   3. GitHub source zipball — `{owner}-{repo}-{sha}/addon.setup.php`
 *      where the addon files are at the wrapper root (the repo IS the
 *      add-on). Common for single-addon GitHub repos like calimonk's
 *      Edge Cache plugins.
 *
 * All three reduce to: "find the directory inside the zip that contains
 * addon.setup.php whose basename matches the expected short_name, or
 * the wrapper itself if there's exactly one addon.setup.php at depth 1
 * and the wrapper looks like a GitHub source-zipball name." Then unpack
 * that subtree into a staging dir, swap into place, keep one backup.
 *
 * We do not run any code from the downloaded zip. addon.setup.php IS
 * include()d to read metadata, but only AFTER extraction into the
 * staging directory — same trust boundary as a manually-uploaded zip.
 */
class ReleaseInstaller
{
    /** Max bytes we'll accept from a release download. */
    private const MAX_DOWNLOAD_BYTES = 100 * 1024 * 1024;

    /** cURL timeouts for the download step. */
    private const DOWNLOAD_TIMEOUT_SECONDS = 60;
    private const CONNECT_TIMEOUT_SECONDS  = 10;

    private const USER_AGENT = 'addon-installer-ee-cp';

    private string $addonsPath;
    private GitHubReleaseChecker $checker;
    private TrustStore $trust;
    private InstallAuditor $auditor;
    private ?AutoFinalizer $finalizer;

    public function __construct(
        ?string $addonsPath = null,
        ?GitHubReleaseChecker $checker = null,
        ?TrustStore $trust = null,
        ?InstallAuditor $auditor = null,
        ?AutoFinalizer $finalizer = null
    ) {
        $this->addonsPath = rtrim(
            $addonsPath ?: PackageInstaller::detectAddonsPath(),
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR;

        $this->checker = $checker ?: new GitHubReleaseChecker();
        $this->trust   = $trust ?: new TrustStore();
        $this->auditor = $auditor ?: new InstallAuditor();
        $this->finalizer = $finalizer;
    }

    /**
     * Install the latest GitHub release for $shortName from $ownerRepo.
     *
     * Returns a result array on success; throws RuntimeException on any
     * recoverable failure (with the existing install left untouched).
     *
     * @return array{
     *   short_name:string, version:string, source:string,
     *   backup_path:?string, update_url:string
     * }
     */
    public function installLatestRelease(string $shortName, string $ownerRepo, bool $reconfirmTrust = false): array
    {
        if (! GitHubReleaseChecker::isValidRepo($ownerRepo)) {
            throw new RuntimeException('Invalid GitHub repo: ' . $ownerRepo);
        }
        if (! preg_match('#^[a-z0-9_]+$#', $shortName)) {
            throw new RuntimeException('Invalid add-on short name: ' . $shortName);
        }
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required.');
        }

        // 1. Identity verification — ALWAYS fetched fresh at install time,
        //    never trusting the 7-day identity cache. This is the supply
        //    chain gate: a repo transfer or RepoJacking incident shows up
        //    here, and we refuse to swap. The release fetch below uses
        //    the same fresh data so we can't be tricked by stale caches.
        $identity = $this->checker->repoIdentityRefresh($ownerRepo);
        if ($identity === null) {
            $this->auditor->record([
                'event' => 'install_blocked',
                'reason' => 'identity_unreachable',
                'short_name' => $shortName,
                'repo' => $ownerRepo,
            ]);
            throw new RuntimeException(
                'Could not verify ' . $ownerRepo . ' against GitHub. '
                . 'Install refused — this could be a network failure or '
                . 'the repo may have been deleted/renamed. Check '
                . 'connectivity and retry.'
            );
        }

        $comparison = $this->trust->compare($ownerRepo, $identity);

        if ($comparison['state'] === TrustStore::STATE_CHANGED && ! $reconfirmTrust) {
            $this->auditor->record([
                'event' => 'install_blocked',
                'reason' => 'trust_mismatch',
                'short_name' => $shortName,
                'repo' => $ownerRepo,
                'pinned' => $comparison['pinned'],
                'observed' => $comparison['observed'],
                'diff' => $comparison['diff'],
            ]);
            throw new RuntimeException($this->trustMismatchMessage($ownerRepo, $comparison));
        }

        // First install OR explicit reconfirm: pin (or re-pin) anchor.
        if ($comparison['state'] !== TrustStore::STATE_TRUSTED) {
            $pinnedBy = null;
            if (function_exists('ee')) {
                try {
                    $login = ee()->session->userdata('username');
                    $pinnedBy = is_string($login) && $login !== '' ? $login : null;
                } catch (\Throwable $e) {
                    // best-effort
                }
            }
            $this->trust->pin($ownerRepo, $identity, $pinnedBy);
            $this->auditor->record([
                'event' => 'trust_pinned',
                'short_name' => $shortName,
                'repo' => $ownerRepo,
                'reason' => $comparison['state'] === TrustStore::STATE_CHANGED
                    ? 'reconfirm'
                    : 'first_seen',
                'identity' => $identity,
            ]);
        }

        // 2. Refresh the release. Forced — cache may be stale and we
        //    want to install the actual newest release.
        $release = $this->checker->refresh($ownerRepo);
        if ($release === null) {
            $this->auditor->record([
                'event' => 'install_blocked',
                'reason' => 'release_fetch_failed',
                'short_name' => $shortName,
                'repo' => $ownerRepo,
            ]);
            throw new RuntimeException(
                'Could not fetch latest release for ' . $ownerRepo
                . '. Check network connectivity and GitHub rate limits.'
            );
        }

        [$downloadUrl, $sourceLabel] = $this->pickDownloadUrl($release, $shortName);

        try {
            $tmpZip = $this->downloadToTemp($downloadUrl);
        } catch (\Throwable $e) {
            $this->auditor->record([
                'event' => 'install_failed',
                'reason' => 'download',
                'short_name' => $shortName,
                'repo' => $ownerRepo,
                'version' => (string) ($release['version'] ?? ''),
                'url' => $downloadUrl,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        try {
            $stagingDir = $this->extractStaging($tmpZip, $shortName);
        } catch (\Throwable $e) {
            @unlink($tmpZip);
            $this->auditor->record([
                'event' => 'install_failed',
                'reason' => 'extract',
                'short_name' => $shortName,
                'repo' => $ownerRepo,
                'version' => (string) ($release['version'] ?? ''),
                'url' => $downloadUrl,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
        @unlink($tmpZip);

        try {
            $backupPath = $this->swapInto($shortName, $stagingDir);
        } catch (\Throwable $e) {
            $this->auditor->record([
                'event' => 'install_failed',
                'reason' => 'swap',
                'short_name' => $shortName,
                'repo' => $ownerRepo,
                'version' => (string) ($release['version'] ?? ''),
                'url' => $downloadUrl,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->checker->forget($ownerRepo);

        // Self-update = updating addon_installer itself. Surfaced in the
        // audit log so post-incident forensics can distinguish "the
        // installer broke during a self-update" from "during an update
        // of some other addon" — historically the former has been the
        // higher-risk case.
        $isSelf = $shortName === 'addon_installer';

        // Schedule the EE-side finalize. The actual upd::update() +
        // version-bump happens on the NEXT request — for self-update
        // that means PHP loads the new upd class fresh, instead of
        // running the in-memory old version. For other addons it
        // happens whenever the user next loads our Releases screen
        // (which is the immediate post-install redirect target).
        try {
            $this->finalizer()->schedule(
                $shortName,
                $isSelf ? 'unknown' : (function_exists('ee') ? (string) (ee('Addon')->get($shortName)->getInstalledVersion() ?? '') : ''),
                (string) ($release['version'] ?? '')
            );
        } catch (\Throwable $e) {
            // Marker write failure is non-fatal — admin can finalize
            // manually via EE's native Update prompt.
        }

        $this->auditor->record([
            'event' => 'install_ok',
            'short_name' => $shortName,
            'repo' => $ownerRepo,
            'version' => (string) ($release['version'] ?? ''),
            'url' => $downloadUrl,
            'source' => $sourceLabel,
            'backup_path' => $backupPath,
            'owner_id' => (int) ($identity['owner_id'] ?? 0),
            'repo_id' => (int) ($identity['repo_id'] ?? 0),
            'trust_state' => $reconfirmTrust ? 'reconfirmed' : 'trusted',
            'is_self' => $isSelf,
        ]);

        // Post-install redirect target: EE's native Add-Ons list. EE
        // shows the "Update Available" prompt with the correct POST+CSRF
        // form for $shortName, and the admin clicks it to finalize the
        // DB-side version bump + any migrations. Earlier versions
        // redirected straight to `addons/update/{short}` via GET, which
        // EE 7 rejects with 403 — that endpoint is POST-only.
        $postInstallUrl = function_exists('ee')
            ? ee('CP/URL')->make('addons')->compile()
            : '';

        return [
            'short_name'      => $shortName,
            'version'         => (string) ($release['version'] ?? ''),
            'source'          => $sourceLabel,
            'backup_path'     => $backupPath,
            'is_self'         => $isSelf,
            'post_install_url' => $postInstallUrl,
            // Legacy alias — older view code reads `update_url`. Same
            // target as post_install_url now.
            'update_url'      => $postInstallUrl,
        ];
    }

    /**
     * Format a human-readable trust-mismatch message. Lists exactly
     * which fields changed and what they changed from/to. Used as the
     * RuntimeException message bubbled up to the CP banner.
     */
    private function trustMismatchMessage(string $ownerRepo, array $comparison): string
    {
        $lines = [];
        $lines[] = 'TRUST CHECK FAILED for ' . $ownerRepo . '.';
        $lines[] = 'The repo identity does not match what was pinned on first install:';
        foreach ($comparison['diff'] as $field => $pair) {
            $lines[] = sprintf(
                '  %s: pinned=%s, now=%s',
                $field,
                var_export($pair['pinned'], true),
                var_export($pair['observed'], true)
            );
        }
        $observedLogin = (string) ($comparison['observed']['owner_login'] ?? '?');
        $pinnedLogin   = (string) ($comparison['pinned']['owner_login']   ?? '?');
        if ($observedLogin !== $pinnedLogin) {
            $lines[] = '  owner_login: pinned=' . $pinnedLogin . ', now=' . $observedLogin;
        }
        $lines[] = 'This is consistent with ownership transfer or RepoJacking. '
            . 'Install refused. Review on GitHub, then use the "Reconfirm trust" '
            . 'action on the Releases screen if the change is legitimate.';
        return implode("\n", $lines);
    }

    /**
     * Pick the best download URL from a release.
     *
     * Preference order:
     *   1. A release asset whose filename starts with the short_name
     *      (e.g. `edge_cache_tags-2.4.13.zip`) — author opted in.
     *   2. Any single .zip asset.
     *   3. The auto-generated zipball_url (source archive).
     *
     * @return array{0:string,1:string} [url, sourceLabel]
     */
    private function pickDownloadUrl(array $release, string $shortName): array
    {
        $assets = $release['assets'] ?? [];

        $zipAssets = array_filter($assets, static function ($a) {
            return is_array($a)
                && isset($a['url'], $a['name'])
                && preg_match('#\.zip$#i', (string) $a['name']);
        });

        // Prefer assets whose basename starts with the short_name.
        foreach ($zipAssets as $a) {
            $base = strtolower((string) $a['name']);
            if (strncmp($base, strtolower($shortName), strlen($shortName)) === 0) {
                return [(string) $a['url'], 'release-asset:' . $a['name']];
            }
        }

        if (! empty($zipAssets)) {
            $first = reset($zipAssets);
            return [(string) $first['url'], 'release-asset:' . $first['name']];
        }

        $zipball = (string) ($release['zipball_url'] ?? '');
        if ($zipball !== '') {
            return [$zipball, 'source-zipball'];
        }

        throw new RuntimeException('Release has no downloadable zip asset and no zipball_url.');
    }

    /**
     * Download $url to a temp file, enforcing a hard byte limit. Returns
     * the path to the downloaded file.
     */
    private function downloadToTemp(string $url): string
    {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for release downloads.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'addon_release_');
        if ($tmp === false) {
            throw new RuntimeException('Could not create a temp file for the release download.');
        }

        $fh = fopen($tmp, 'wb');
        if (! $fh) {
            @unlink($tmp);
            throw new RuntimeException('Could not open temp file for writing.');
        }

        $bytesWritten = 0;
        $oversize = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => self::DOWNLOAD_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            // Accept: */* on purpose. The two URL shapes we hit have
            // different content-type expectations:
            //   - browser_download_url (release assets): serves binary
            //     regardless of Accept.
            //   - zipball_url (source archives): GitHub returns HTTP 415
            //     "Unsupported Media Type" if Accept is the strict
            //     application/octet-stream, but accepts any of */*,
            //     application/zip, or application/vnd.github+json and
            //     then 302-redirects to codeload.github.com.
            // */* works for both with a single header set.
            CURLOPT_HTTPHEADER     => [
                'Accept: */*',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => function ($_resource, $_dlTotal, $dlNow) use (&$bytesWritten, &$oversize) {
                $bytesWritten = (int) $dlNow;
                if ($bytesWritten > self::MAX_DOWNLOAD_BYTES) {
                    $oversize = true;
                    return 1; // any non-zero aborts cURL
                }
                return 0;
            },
        ]);

        $ok   = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        if ($oversize) {
            @unlink($tmp);
            throw new RuntimeException(sprintf(
                'Release archive exceeded the %d MB safety limit.',
                self::MAX_DOWNLOAD_BYTES / 1024 / 1024
            ));
        }

        if ($ok === false || $code < 200 || $code >= 300) {
            @unlink($tmp);
            throw new RuntimeException(sprintf(
                'Release download failed (HTTP %d%s).',
                $code,
                $err !== '' ? ': ' . $err : ''
            ));
        }

        $size = (int) filesize($tmp);
        if ($size === 0) {
            @unlink($tmp);
            throw new RuntimeException('Release archive was empty.');
        }

        return $tmp;
    }

    /**
     * Extract the addon subtree from $zipPath into a fresh staging
     * directory, returning the staging path.
     *
     * The staging dir lives alongside the addon target so the final swap
     * is a same-filesystem rename. Naming convention keeps it out of
     * EE's package detection (leading dot).
     */
    private function extractStaging(string $zipPath, string $shortName): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Downloaded release archive could not be opened as a ZIP.');
        }

        try {
            $root = $this->locateAddonRoot($zip, $shortName);

            $staging = $this->addonsPath . '.addon_installer_staging_' . $shortName . '_' . time();
            if (is_dir($staging)) {
                $this->removeDirectory($staging);
            }
            if (! @mkdir($staging, 0775, true)) {
                throw new RuntimeException('Could not create staging directory.');
            }

            $extracted = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || $this->isIgnoredEntry($name)) {
                    continue;
                }

                if (! $this->isSafeEntry($name)) {
                    throw new RuntimeException('Refusing to extract unsafe entry: ' . $name);
                }

                $relative = $this->stripRoot($name, $root);
                if ($relative === null || $relative === '') {
                    continue;
                }

                $destination = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

                if (str_ends_with($name, '/')) {
                    if (! is_dir($destination)) {
                        @mkdir($destination, 0775, true);
                    }
                    continue;
                }

                $parent = dirname($destination);
                if (! is_dir($parent)) {
                    @mkdir($parent, 0775, true);
                }

                $stream = $zip->getStream($name);
                if (! $stream) {
                    throw new RuntimeException('Could not read "' . $name . '" from the release archive.');
                }

                $out = fopen($destination, 'wb');
                if (! $out) {
                    fclose($stream);
                    throw new RuntimeException('Could not write "' . $relative . '" to staging.');
                }

                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
                $extracted++;
            }

            if ($extracted === 0) {
                throw new RuntimeException('Release archive contained no extractable files for ' . $shortName . '.');
            }

            $stagedSetup = $staging . DIRECTORY_SEPARATOR . 'addon.setup.php';
            if (! is_file($stagedSetup)) {
                throw new RuntimeException(
                    'Release archive does not contain addon.setup.php at the expected path for ' . $shortName . '.'
                );
            }

            // Pre-flight compatibility check, BEFORE the swap. This is
            // even more important here than on the upload path: if the
            // new release declares (and uses) PHP 8.3 syntax and we swap
            // it onto a PHP 8.1 host, auto-finalize — or any later CP
            // request that loads the addon — would fatal trying to parse
            // it. Refuse at staging so the existing install stays intact.
            // We parse the requires via regex (never include the
            // downloaded PHP) for the same reason: loading 8.3 syntax to
            // read a version would itself crash.
            $requires = $this->parseStagedRequires($stagedSetup);
            $issues = PackageInstaller::checkRequirements($requires);
            if (! empty($issues)) {
                throw new RuntimeException(
                    'Refusing to install ' . $shortName . ': '
                    . implode(' ', $issues)
                    . ' (Declared in the release\'s addon.setup.php. The previous version is untouched.)'
                );
            }

            return $staging;
        } catch (\Throwable $e) {
            $zip->close();
            if (isset($staging) && is_dir($staging)) {
                $this->removeDirectory($staging);
            }
            throw $e;
        } finally {
            $zip->close();
        }
    }

    /**
     * Find the path inside the zip whose direct directory contains
     * addon.setup.php for $shortName. The "root" we return is that
     * directory's prefix — everything before it gets stripped during
     * extraction.
     *
     * Strategy: enumerate all addon.setup.php entries, prefer one whose
     * parent directory basename matches $shortName. Fall back to the
     * first addon.setup.php found (handles GitHub source zipballs of
     * single-addon repos, where the addon files sit at the wrapper
     * root and the wrapper name has nothing to do with the addon).
     */
    private function locateAddonRoot(ZipArchive $zip, string $shortName): string
    {
        $candidates = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || $this->isIgnoredEntry($name)) {
                continue;
            }
            // Normalize Windows separators just in case.
            $name = str_replace('\\', '/', $name);
            if (substr($name, -strlen('/addon.setup.php')) === '/addon.setup.php'
                || $name === 'addon.setup.php') {
                $candidates[] = $name;
            }
        }

        if (empty($candidates)) {
            throw new RuntimeException('Release archive does not contain addon.setup.php anywhere.');
        }

        // Preferred: parent dir basename matches the expected short_name.
        foreach ($candidates as $path) {
            $parent = dirname($path);
            if ($parent === '.' || $parent === '/') {
                continue;
            }
            if (basename($parent) === $shortName) {
                return $parent . '/';
            }
        }

        // Single addon.setup.php anywhere → that's the root regardless of
        // wrapper naming (source-zipball pattern).
        if (count($candidates) === 1) {
            $only = $candidates[0];
            $parent = dirname($only);
            return $parent === '.' || $parent === '/' ? '' : ($parent . '/');
        }

        throw new RuntimeException(
            'Release archive contained multiple addon.setup.php files but none under a folder named "'
            . $shortName . '". Refusing to guess.'
        );
    }

    /**
     * Move the existing addon dir aside and rename staging into place.
     * Keeps exactly one previous backup (older ones are removed first).
     *
     * Backups live in `system/user/cache/addon_installer/backups/{short}/{ts}/`
     * — explicitly OUTSIDE `system/user/addons/` so EE's PSR-4 addon
     * discovery never sees them. The earlier scheme of
     * `system/user/addons/.{short}.backup.{ts}/` collided with the
     * autoloader: even though the directory name starts with a dot, EE
     * walked the addons dir and registered the backup as a second
     * namespace-`JavidFazaeli\AddonInstaller` source, fatally confusing
     * the class loader during self-updates (1.3.0 bug, see issue #N).
     *
     * Cross-filesystem move: the cache dir CAN be on a different mount
     * than the addons dir on some hosts. We attempt rename() first
     * (atomic same-FS), and fall back to copy+remove when rename fails
     * with EXDEV-ish errors.
     */
    private function swapInto(string $shortName, string $stagingDir): ?string
    {
        $targetPath = $this->addonsPath . $shortName;
        $backupPath = null;

        // Sweep ANY prior backups for this short_name — both in the new
        // location (cache) and the legacy location (dot-prefix inside
        // addons/). Legacy sweep is the self-heal path for 1.3.0
        // installs damaged by the autoloader-collision bug.
        $this->sweepPriorBackups($shortName);

        if (is_dir($targetPath)) {
            $backupRoot = $this->backupRoot($shortName);
            if (! is_dir($backupRoot) && ! @mkdir($backupRoot, 0775, true)) {
                throw new RuntimeException(
                    'Could not create backup directory: ' . $backupRoot
                    . '. Check that system/user/cache is writable.'
                );
            }
            $backupPath = rtrim($backupRoot, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . (string) time();

            if (! $this->moveDirectory($targetPath, $backupPath)) {
                throw new RuntimeException(
                    'Could not move existing add-on aside (insufficient permissions?). '
                    . 'Staging directory left at: ' . $stagingDir
                );
            }
        }

        if (! @rename($stagingDir, $targetPath)) {
            // Roll back: try to put the original dir back.
            if ($backupPath !== null && is_dir($backupPath)) {
                $this->moveDirectory($backupPath, $targetPath);
            }
            throw new RuntimeException(
                'Could not rename staging directory into place. The previous version was restored. '
                . 'Staging directory may still exist at: ' . $stagingDir
            );
        }

        // Invalidate opcache for every PHP file in the new install. PHP
        // would normally pick up changes within revalidate_freq seconds
        // (default 2s) but production setups can have it set much higher
        // or set validate_timestamps=0. Explicit invalidation makes sure
        // the next request sees new code regardless of opcache tuning.
        $this->invalidateOpcache($targetPath);

        return $backupPath;
    }

    /**
     * Parse the `requires` block from a staged addon.setup.php by regex.
     * Same approach + format as PackageInstaller::parseRequires — kept
     * local rather than shared because we must read from a file path
     * here (the staged setup) and must NOT include() it (downloaded
     * PHP that may use a too-new syntax). Flat scalar array assumed.
     *
     * @return array<string,string>
     */
    private function parseStagedRequires(string $setupPath): array
    {
        $contents = @file_get_contents($setupPath);
        if ($contents === false || $contents === '') {
            return [];
        }

        if (! preg_match('/[\'"]requires[\'"]\s*=>\s*(?:\[|array\s*\()(.*?)(?:\]|\))/s', $contents, $block)) {
            return [];
        }

        $requires = [];
        foreach (['php', 'ee', 'mysql', 'mariadb'] as $req) {
            if (preg_match("/['\"]" . $req . "['\"]\\s*=>\\s*(['\"])(.*?)\\1/s", $block[1], $match)) {
                $requires[$req] = trim($match[2]);
            }
        }

        return $requires;
    }

    /** Lazy-construct the AutoFinalizer (uses DI if available). */
    private function finalizer(): AutoFinalizer
    {
        if ($this->finalizer === null) {
            $this->finalizer = function_exists('ee')
                ? ee('addon_installer:autoFinalizer')
                : new AutoFinalizer($this->auditor);
        }
        return $this->finalizer;
    }

    /** Per-short_name backup directory root, outside addon discovery. */
    private function backupRoot(string $shortName): string
    {
        $base = defined('SYSPATH')
            ? SYSPATH . 'user/cache'
            : sys_get_temp_dir();

        return rtrim($base, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'addon_installer'
            . DIRECTORY_SEPARATOR . 'backups'
            . DIRECTORY_SEPARATOR . $shortName;
    }

    /**
     * Remove any prior backups for $shortName from BOTH the new
     * cache-based location and the legacy in-addons-dir location.
     * Idempotent — safe to call on any install.
     */
    private function sweepPriorBackups(string $shortName): void
    {
        $newRoot = $this->backupRoot($shortName);
        foreach (glob($newRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $prior) {
            $this->removeDirectory($prior);
        }

        // Legacy: dot-prefixed backups inside system/user/addons/.
        // These existed in 1.3.0; sweeping them here ensures even a
        // damaged 1.3.0 install heals on the next successful update.
        foreach (glob($this->addonsPath . '.' . $shortName . '.backup.*', GLOB_ONLYDIR) ?: [] as $legacy) {
            $this->removeDirectory($legacy);
        }
    }

    /**
     * Move a directory, preferring atomic rename(). Falls back to a
     * recursive copy + source-removal when rename fails (typical on
     * cross-filesystem moves between addons dir and cache dir).
     */
    private function moveDirectory(string $from, string $to): bool
    {
        if (@rename($from, $to)) {
            return true;
        }
        if (! @mkdir($to, 0775, true) && ! is_dir($to)) {
            return false;
        }
        if (! $this->copyDirectory($from, $to)) {
            $this->removeDirectory($to);
            return false;
        }
        $this->removeDirectory($from);
        return true;
    }

    private function copyDirectory(string $from, string $to): bool
    {
        $from = rtrim($from, DIRECTORY_SEPARATOR);
        $to   = rtrim($to, DIRECTORY_SEPARATOR);
        $entries = @scandir($from);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $src = $from . DIRECTORY_SEPARATOR . $entry;
            $dst = $to . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($src) && ! is_link($src)) {
                if (! @mkdir($dst, 0775, true) && ! is_dir($dst)) {
                    return false;
                }
                if (! $this->copyDirectory($src, $dst)) {
                    return false;
                }
            } else {
                if (! @copy($src, $dst)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Best-effort opcache invalidation across the new install. Silent
     * failure — if opcache isn't loaded or is disabled per-CLI, we
     * still want the install to complete.
     */
    private function invalidateOpcache(string $targetPath): void
    {
        if (! function_exists('opcache_invalidate')) {
            return;
        }
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($targetPath, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if (! $fileInfo->isFile()) {
                    continue;
                }
                $path = (string) $fileInfo;
                if (substr($path, -4) === '.php') {
                    @opcache_invalidate($path, true);
                }
            }
        } catch (\Throwable $e) {
            // Swallow — failure to invalidate is non-fatal; opcache
            // will revalidate via mtime within revalidate_freq seconds.
        }
    }

    /**
     * Path-safety filter applied to every ZIP entry name before any
     * filesystem operation. Rejects absolute paths and any segment that
     * could escape the staging dir.
     */
    private function isSafeEntry(string $name): bool
    {
        $normalized = str_replace('\\', '/', $name);
        if ($normalized === '' || $normalized[0] === '/' || preg_match('#^[A-Za-z]:#', $normalized)) {
            return false;
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..' || $segment === '') {
                // Empty segments are fine only as the trailing slash of a
                // directory entry; reject any empty non-trailing segment.
                if ($segment === '..') {
                    return false;
                }
            }
        }
        return true;
    }

    /** Skip noise files that some authors ship in releases. */
    private function isIgnoredEntry(string $name): bool
    {
        $base = basename(str_replace('\\', '/', $name));
        return in_array($base, ['.DS_Store', 'Thumbs.db'], true)
            || strncmp($name, '__MACOSX/', 9) === 0;
    }

    /**
     * Strip $root (a forward-slash-terminated prefix, possibly empty)
     * from $name. Returns null when the entry doesn't live under root.
     */
    private function stripRoot(string $name, string $root): ?string
    {
        $name = str_replace('\\', '/', $name);
        if ($root === '') {
            return $name;
        }
        if (strncmp($name, $root, strlen($root)) !== 0) {
            return null;
        }
        return substr($name, strlen($root));
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $entries = scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full) && ! is_link($full)) {
                $this->removeDirectory($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
