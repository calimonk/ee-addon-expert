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

    public function __construct(?string $addonsPath = null, ?GitHubReleaseChecker $checker = null)
    {
        $this->addonsPath = rtrim(
            $addonsPath ?: PackageInstaller::detectAddonsPath(),
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR;

        $this->checker = $checker ?: new GitHubReleaseChecker();
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
    public function installLatestRelease(string $shortName, string $ownerRepo): array
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

        // Always re-fetch — the cache may be stale and we want to install
        // the actual newest release, not a 12h-old snapshot.
        $release = $this->checker->refresh($ownerRepo);
        if ($release === null) {
            throw new RuntimeException(
                'Could not fetch latest release for ' . $ownerRepo
                . '. Check network connectivity and GitHub rate limits.'
            );
        }

        [$downloadUrl, $sourceLabel] = $this->pickDownloadUrl($release, $shortName);

        $tmpZip = $this->downloadToTemp($downloadUrl);

        try {
            $stagingDir = $this->extractStaging($tmpZip, $shortName);
        } finally {
            @unlink($tmpZip);
        }

        $backupPath = $this->swapInto($shortName, $stagingDir);

        // The new version is now on disk. Forget the cache so the next CP
        // view reflects state directly from the new addon.setup.php.
        $this->checker->forget($ownerRepo);

        return [
            'short_name'  => $shortName,
            'version'     => (string) ($release['version'] ?? ''),
            'source'      => $sourceLabel,
            'backup_path' => $backupPath,
            'update_url'  => function_exists('ee')
                ? ee('CP/URL')->make('addons/update/' . $shortName, [
                    'return' => ee('CP/URL')
                        ->make('addons/settings/addon_installer/packages')
                        ->encode(),
                ])->compile()
                : '',
        ];
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
            CURLOPT_HTTPHEADER     => [
                'Accept: application/octet-stream',
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
     */
    private function swapInto(string $shortName, string $stagingDir): ?string
    {
        $targetPath = $this->addonsPath . $shortName;
        $backupPath = null;

        // Drop any prior backups for this short_name — we keep only the
        // most recent one so updates don't accumulate stale copies.
        $priorBackups = glob($this->addonsPath . '.' . $shortName . '.backup.*', GLOB_ONLYDIR) ?: [];
        foreach ($priorBackups as $prior) {
            $this->removeDirectory($prior);
        }

        if (is_dir($targetPath)) {
            $backupPath = $this->addonsPath . '.' . $shortName . '.backup.' . time();
            if (! @rename($targetPath, $backupPath)) {
                throw new RuntimeException(
                    'Could not move existing add-on aside (insufficient permissions?). '
                    . 'Staging directory left at: ' . $stagingDir
                );
            }
        }

        if (! @rename($stagingDir, $targetPath)) {
            // Roll back: try to put the original dir back.
            if ($backupPath !== null && is_dir($backupPath)) {
                @rename($backupPath, $targetPath);
            }
            throw new RuntimeException(
                'Could not rename staging directory into place. The previous version was restored. '
                . 'Staging directory may still exist at: ' . $stagingDir
            );
        }

        return $backupPath;
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
