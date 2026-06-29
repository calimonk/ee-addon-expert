<?php

namespace Nivoli\AddonExpert\Service;

use RuntimeException;
use ZipArchive;

class PackageInstaller
{
    private string $addonsPath;

    private ?UpdateSourceRegistry $sources = null;
    private ?GitHubReleaseChecker $releases = null;
    private ?AutoFinalizer $finalizer = null;
    private ?InstallAuditor $auditor = null;
    private ?OverrideStore $overrides = null;
    private ?CompatibilityScanner $scanner = null;

    public function __construct(
        ?string $addonsPath = null,
        ?UpdateSourceRegistry $sources = null,
        ?GitHubReleaseChecker $releases = null,
        ?AutoFinalizer $finalizer = null,
        ?InstallAuditor $auditor = null,
        ?OverrideStore $overrides = null,
        ?CompatibilityScanner $scanner = null
    ) {
        $this->addonsPath = rtrim($addonsPath ?: self::detectAddonsPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->sources = $sources;
        $this->releases = $releases;
        $this->finalizer = $finalizer;
        $this->auditor = $auditor;
        $this->overrides = $overrides;
        $this->scanner = $scanner;
    }

    private function sources(): UpdateSourceRegistry
    {
        if ($this->sources === null) {
            $this->sources = function_exists('ee')
                ? ee('addon_expert:updateSourceRegistry')
                : new UpdateSourceRegistry(null, $this->addonsPath);
        }
        return $this->sources;
    }

    private function releases(): GitHubReleaseChecker
    {
        if ($this->releases === null) {
            $this->releases = function_exists('ee')
                ? ee('addon_expert:githubReleaseChecker')
                : new GitHubReleaseChecker();
        }
        return $this->releases;
    }

    private function finalizer(): AutoFinalizer
    {
        if ($this->finalizer === null) {
            $this->finalizer = function_exists('ee')
                ? ee('addon_expert:autoFinalizer')
                : new AutoFinalizer();
        }
        return $this->finalizer;
    }

    private function auditor(): InstallAuditor
    {
        if ($this->auditor === null) {
            $this->auditor = function_exists('ee')
                ? ee('addon_expert:installAuditor')
                : new InstallAuditor();
        }
        return $this->auditor;
    }

    private function overrides(): OverrideStore
    {
        if ($this->overrides === null) {
            $this->overrides = function_exists('ee')
                ? ee('addon_expert:overrideStore')
                : new OverrideStore();
        }
        return $this->overrides;
    }

    private function scanner(): CompatibilityScanner
    {
        if ($this->scanner === null) {
            $this->scanner = function_exists('ee')
                ? ee('addon_expert:compatibilityScanner')
                : new CompatibilityScanner();
        }
        return $this->scanner;
    }

    /**
     * Collect [name => contents] for every .php file under $root inside
     * an open zip, for the compatibility scanner. Bounded so a hostile
     * or huge archive can't blow memory.
     *
     * @return array<string,string>
     */
    private function collectZipPhp(ZipArchive $zip, string $root): array
    {
        $files = [];
        $prefix = rtrim($root, '/') . '/';
        for ($i = 0; $i < $zip->numFiles && count($files) < 400; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || $this->isIgnoredPath($name)) {
                continue;
            }
            $normalized = ltrim(str_replace('\\', '/', $name), '/');
            if (strncmp($normalized, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            if (substr($normalized, -4) !== '.php') {
                continue;
            }
            $contents = $zip->getFromName($name);
            if (is_string($contents) && $contents !== '') {
                $files[substr($normalized, strlen($prefix))] = $contents;
            }
        }
        return $files;
    }

    public static function detectAddonsPath(): string
    {
        $candidates = [];

        if (defined('PATH_THIRD')) {
            $candidates[] = PATH_THIRD;
        }

        if (defined('APPPATH')) {
            $candidates[] = rtrim(APPPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'user' . DIRECTORY_SEPARATOR . 'addons';
        }

        $candidates[] = dirname(__DIR__, 2);

        foreach ($candidates as $candidate) {
            $path = rtrim((string) $candidate, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (is_dir($path) && is_file($path . 'addon_expert' . DIRECTORY_SEPARATOR . 'addon.setup.php')) {
                return $path;
            }
        }

        return rtrim(dirname(__DIR__, 2), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public function status(): array
    {
        return [
            'addons_path' => $this->addonsPath,
            'addons_path_writable' => is_writable($this->addonsPath),
            'zip_available' => class_exists(ZipArchive::class),
            'upload_limit' => ini_get('upload_max_filesize'),
            'post_limit' => ini_get('post_max_size'),
        ];
    }

    public function csrfToken(): string
    {
        return ee()->functions->add_form_security_hash('{XID_HASH}');
    }

    public function installedPackages(): array
    {
        $packages = [];
        $returnUrl = ee('CP/URL')->make('addons/settings/addon_expert/packages')->encode();
        $releasesPostUrl = ee('CP/URL')->make('addons/settings/addon_expert/releases')->compile();

        foreach (glob($this->addonsPath . '*', GLOB_ONLYDIR) ?: [] as $path) {
            $setup = $path . DIRECTORY_SEPARATOR . 'addon.setup.php';
            if (! is_file($setup)) {
                continue;
            }

            $shortName = basename($path);
            $meta = $this->readSetupMetadata($setup);
            $addon = ee('Addon')->get($shortName);
            $isInstalled = $addon ? (bool) $addon->isInstalled() : false;
            $updateAvailable = $addon ? (bool) $addon->hasUpdate() : false;
            $settingsAvailable = $isInstalled && $addon && (bool) $addon->get('settings_exist');

            // On-disk version drives the GitHub comparison even when the
            // add-on isn't installed yet — that way "Not Installed but a
            // newer GitHub release exists" still surfaces.
            $diskVersion = $addon ? (string) $addon->getVersion() : (string) ($meta['version'] ?? '');

            $remote = $this->resolveRemote($shortName, $diskVersion);

            // Compatibility of what's on disk vs the running environment.
            // For a not-yet-installed package this tells the admin up
            // front whether clicking EE's native Install will be refused.
            $compatIssues = self::checkRequirements((array) ($meta['requires'] ?? []));
            $override = $this->overrides()->get($shortName);

            $packages[] = [
                'short_name' => $shortName,
                'name' => $addon ? $addon->getName() : ($meta['name'] ?? $shortName),
                'version' => $addon ? $addon->getVersion() : ($meta['version'] ?? ''),
                'installed_version' => $addon ? (string) $addon->getInstalledVersion() : '',
                'description' => $addon ? (string) $addon->get('description') : ($meta['description'] ?? ''),
                'author' => $addon ? $addon->getAuthor() : ($meta['author'] ?? ''),
                'is_installed' => $isInstalled,
                'update_available' => $updateAvailable,
                'settings_available' => $settingsAvailable,
                'compat_issues' => $compatIssues,
                'is_overridden' => $override !== null,
                'override_info' => $override,
                'remote_repo' => $remote['repo'],
                'remote_repo_source' => $remote['source'],
                'remote_version' => $remote['version'],
                'remote_release_url' => $remote['release_url'],
                'remote_release_name' => $remote['release_name'],
                'remote_published_at' => $remote['published_at'],
                'remote_checked_at' => $remote['checked_at'],
                'remote_update_available' => $remote['update_available'],
                'remote_status' => $remote['status'],
                'remote_trust_state' => $remote['trust_state'],
                'remote_trust_diff' => $remote['trust_diff'] ?? [],
                'remote_trust_pinned' => $remote['trust_pinned'] ?? null,
                'remote_trust_observed' => $remote['trust_observed'] ?? null,
                // POST target for the "Update from GitHub" button. Empty
                // when no repo is configured for this short_name.
                'remote_install_url' => $remote['repo'] !== null ? $releasesPostUrl : '',
                'settings_url' => $settingsAvailable
                    ? ee('CP/URL')->make('addons/settings/' . $shortName)->compile()
                    : '',
                'manager_url' => ee('CP/URL')->make('addons')->compile(),
                'install_url' => ! $isInstalled
                    ? ee('CP/URL')->make('addons/install/' . $shortName, ['return' => $returnUrl])->compile()
                    : '',
                'update_url' => $updateAvailable
                    ? ee('CP/URL')->make('addons/update/' . $shortName, ['return' => $returnUrl])->compile()
                    : '',
                'remove_url' => $isInstalled
                    ? ee('CP/URL')->make('addons/remove/' . $shortName, ['return' => $returnUrl])->compile()
                    : '',
                'download_url' => ee('CP/URL')->make('addons/settings/addon_expert/packages', [
                    'download' => $shortName,
                ])->compile(),
            ];
        }

        usort($packages, static function ($a, $b) {
            $installed = (int) $a['is_installed'] <=> (int) $b['is_installed'];

            return $installed !== 0 ? $installed : strcasecmp($a['name'], $b['name']);
        });

        return $packages;
    }

    /**
     * Resolve a single add-on against the configured release source. Returns
     * a fully-populated remote_* block — fields are empty/null when no
     * source is configured or no fresh cache entry exists. This method
     * never makes HTTP calls; refresh() is the only path that hits GitHub.
     *
     * @return array{
     *   repo:?string, source:?string, version:?string, release_url:?string,
     *   release_name:?string, published_at:?string, checked_at:int,
     *   update_available:bool, status:string
     * }
     */
    public function resolveRemote(string $shortName, string $installedVersion): array
    {
        $empty = [
            'repo' => null,
            'source' => null,
            'version' => null,
            'release_url' => null,
            'release_name' => null,
            'published_at' => null,
            'checked_at' => 0,
            'update_available' => false,
            // status ∈ {unconfigured, never_checked, stale, fresh, error}
            'status' => 'unconfigured',
            // trust_state ∈ {none, unverified, trusted, changed}
            'trust_state' => 'none',
            'trust_diff' => [],
            'trust_pinned' => null,
            'trust_observed' => null,
        ];

        $mapping = $this->sources()->resolve($shortName);
        if ($mapping === null) {
            return $empty;
        }
        // This method resolves GitHub remote state only. A `registry:`
        // source is handled by its own checker/UI — treat it as no GitHub
        // mapping here so the GitHub Releases surface stays stable.
        if (($mapping['type'] ?? 'github') !== 'github') {
            return $empty;
        }

        $repo = $mapping['repo'];
        $checker = $this->releases();
        $checkedAt = $checker->lastCheckedAt($repo);
        $cached = $checker->cached($repo);

        // Trust comparison uses the *cached* identity (no HTTP from
        // listing). Install-time uses fresh identity — that's the
        // authoritative gate. This view-time comparison is best-effort
        // visibility: if the cached identity is stale or absent the
        // trust column shows "unverified" until the next refresh.
        $cachedIdentity = $checker->repoIdentityCached($repo);
        $trustCmp = function_exists('ee')
            ? ee('addon_expert:trustStore')->compare($repo, $cachedIdentity)
            : (new TrustStore())->compare($repo, $cachedIdentity);
        $trustState = $cachedIdentity === null
            ? ($trustCmp['pinned'] !== null ? 'trusted' : 'none')
            : $trustCmp['state'];

        if ($checkedAt === 0) {
            return array_merge($empty, [
                'repo' => $repo,
                'source' => $mapping['source'],
                'status' => 'never_checked',
            ]);
        }

        if ($cached === null) {
            // Cache exists but is a sentinel — last fetch failed.
            return array_merge($empty, [
                'repo' => $repo,
                'source' => $mapping['source'],
                'checked_at' => $checkedAt,
                'status' => 'error',
            ]);
        }

        $remoteVersion = (string) ($cached['version'] ?? '');
        $isNewer = $remoteVersion !== ''
            && $installedVersion !== ''
            && GitHubReleaseChecker::isNewer($remoteVersion, $installedVersion);

        return [
            'repo' => $repo,
            'source' => $mapping['source'],
            'version' => $remoteVersion,
            'release_url' => (string) ($cached['html_url'] ?? ''),
            'release_name' => (string) ($cached['name'] ?? ''),
            'published_at' => (string) ($cached['published_at'] ?? ''),
            'checked_at' => $checkedAt,
            'update_available' => $isNewer,
            'status' => $checker->isStale($repo) ? 'stale' : 'fresh',
            'trust_state' => $trustState,
            'trust_diff' => $trustCmp['diff'] ?? [],
            'trust_pinned' => $trustCmp['pinned'] ?? null,
            'trust_observed' => $trustCmp['observed'] ?? null,
        ];
    }

    /**
     * Force a fresh GitHub fetch for every add-on with a configured source.
     * Returns per-add-on outcomes so callers can show a useful summary.
     *
     * @return array<string,array{repo:string,ok:bool,version:?string}>
     */
    public function refreshAllReleases(): array
    {
        $checker = $this->releases();
        $sources = $this->sources();
        $results = [];

        foreach (glob($this->addonsPath . '*', GLOB_ONLYDIR) ?: [] as $path) {
            $setup = $path . DIRECTORY_SEPARATOR . 'addon.setup.php';
            if (! is_file($setup)) {
                continue;
            }

            $shortName = basename($path);
            $mapping = $sources->resolve($shortName);
            if ($mapping === null || ($mapping['type'] ?? 'github') !== 'github') {
                continue;
            }

            $data = $checker->refresh($mapping['repo']);

            // Refresh repo identity only when stale (7d TTL). Keeps the
            // GitHub API budget reasonable on Check-for-updates while
            // still surfacing trust changes within a week of them
            // happening. Install-time check is the authoritative gate.
            if ($checker->repoIdentityIsStale($mapping['repo'])) {
                $checker->repoIdentityRefresh($mapping['repo']);
            }

            $results[$shortName] = [
                'repo' => $mapping['repo'],
                'source' => $mapping['source'],
                'ok' => $data !== null,
                'version' => $data['version'] ?? null,
            ];
        }

        return $results;
    }

    /** Count of installed-packages cards that currently flag an upstream update. */
    public function remoteUpdateCount(): int
    {
        $count = 0;
        foreach ($this->installedPackages() as $pkg) {
            if (! empty($pkg['remote_update_available'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Validate an upload and inspect+scan it WITHOUT committing anything.
     * Returns the metadata, compatibility issues, and (when incompatible)
     * the feature-scan verdict, plus the tmp path so the caller can
     * either install it immediately or quarantine it for confirmation.
     */
    public function inspectUpload(array $file): array
    {
        $this->assertReady();

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        $tmpName = $file['tmp_name'] ?? '';
        $originalName = $file['name'] ?? 'package.zip';
        if (! is_uploaded_file($tmpName) && ! is_file($tmpName)) {
            throw new RuntimeException('The uploaded package could not be read.');
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('Upload a .zip package.');
        }

        $inspect = $this->inspectForInstall($tmpName);
        $inspect['tmp_name'] = $tmpName;
        $inspect['original_name'] = $originalName;
        return $inspect;
    }

    /**
     * Inspect + compat-check + (if incompatible) feature-scan a zip on
     * disk, no side effects.
     *
     * @return array{short_name:string,name:string,version:string,requires:array,issues:string[],scan:?array}
     */
    public function inspectForInstall(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The ZIP package could not be opened.');
        }
        try {
            $info = $this->inspectZip($zip);
            $requires = (array) ($info['metadata']['requires'] ?? []);
            $issues = self::checkRequirements($requires);
            $scan = null;
            if (! empty($issues)) {
                try {
                    $scan = $this->scanner()->scanFiles($this->collectZipPhp($zip, $info['root']), PHP_VERSION);
                } catch (\Throwable $e) {
                    // advisory only
                }
            }
            return [
                'short_name' => $info['short_name'],
                'name'       => $info['metadata']['name'] ?? $info['short_name'],
                'version'    => (string) ($info['metadata']['version'] ?? ''),
                'requires'   => $requires,
                'issues'     => $issues,
                'scan'       => $scan,
            ];
        } finally {
            $zip->close();
        }
    }

    /* ---- quarantine (hold an incompatible upload for confirmation) ---- */

    private function quarantineDir(): string
    {
        $base = defined('SYSPATH') ? SYSPATH . 'user/cache' : sys_get_temp_dir();
        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'addon_expert' . DIRECTORY_SEPARATOR . 'quarantine';
    }

    /** Move an uploaded tmp file into quarantine + write its meta. Returns token. */
    public function quarantineStore(string $tmpName, array $meta): string
    {
        $dir = $this->quarantineDir();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create the quarantine directory.');
        }
        $token = bin2hex(random_bytes(8));
        $zipDest = $dir . DIRECTORY_SEPARATOR . $token . '.zip';

        $moved = is_uploaded_file($tmpName)
            ? @move_uploaded_file($tmpName, $zipDest)
            : @copy($tmpName, $zipDest);
        if (! $moved) {
            throw new RuntimeException('Could not quarantine the uploaded package.');
        }

        $meta['token'] = $token;
        $meta['created_at'] = time();
        @file_put_contents($dir . DIRECTORY_SEPARATOR . $token . '.json', json_encode($meta, JSON_UNESCAPED_SLASHES));
        return $token;
    }

    /** Load a quarantined package's meta + zip path, or null if absent/invalid. */
    public function quarantineGet(string $token): ?array
    {
        if (! preg_match('/^[a-f0-9]{16}$/', $token)) {
            return null;
        }
        $metaFile = $this->quarantineDir() . DIRECTORY_SEPARATOR . $token . '.json';
        $zipFile  = $this->quarantineDir() . DIRECTORY_SEPARATOR . $token . '.zip';
        if (! is_file($metaFile) || ! is_file($zipFile)) {
            return null;
        }
        $meta = json_decode((string) @file_get_contents($metaFile), true);
        if (! is_array($meta)) {
            return null;
        }
        $meta['zip_path'] = $zipFile;
        return $meta;
    }

    public function quarantineClear(string $token): void
    {
        if (! preg_match('/^[a-f0-9]{16}$/', $token)) {
            return;
        }
        foreach (['.zip', '.json'] as $ext) {
            $f = $this->quarantineDir() . DIRECTORY_SEPARATOR . $token . $ext;
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /** Drop quarantined packages older than $maxAge seconds (default 1h). */
    public function sweepQuarantine(int $maxAge = 3600): void
    {
        foreach (glob($this->quarantineDir() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $metaFile) {
            $meta = json_decode((string) @file_get_contents($metaFile), true);
            $created = is_array($meta) ? (int) ($meta['created_at'] ?? 0) : 0;
            if ($created === 0 || (time() - $created) > $maxAge) {
                $this->quarantineClear(basename($metaFile, '.json'));
            }
        }
    }

    public function installUploaded(array $file, bool $overwrite = false, bool $overrideRequirements = false, ?string $overrideReason = null): array
    {
        $this->assertReady();

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $tmpName = $file['tmp_name'] ?? '';
        $originalName = $file['name'] ?? 'package.zip';

        if (! is_uploaded_file($tmpName) && ! is_file($tmpName)) {
            throw new RuntimeException('The uploaded package could not be read.');
        }

        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('Upload a .zip package.');
        }

        return $this->installFromZip($tmpName, $overwrite, $overrideRequirements, $overrideReason);
    }

    /**
     * Install from a zip already on disk — an uploaded tmp file or a
     * quarantined package awaiting force-confirmation. Shared by
     * installUploaded() and the quarantine confirm flow so both run
     * identical inspect → compat → extract → override → finalize logic.
     */
    public function installFromZip(string $zipPath, bool $overwrite = false, bool $overrideRequirements = false, ?string $overrideReason = null): array
    {
        $this->assertReady();

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The ZIP package could not be opened.');
        }

        try {
            $info = $this->inspectZip($zip);
            $shortName = $info['short_name'];
            $targetPath = $this->addonsPath . $shortName;

            // Pre-flight compatibility check. EE's native installer reads
            // the same `requires` block and refuses incompatible installs
            // — but only at the install step, AFTER we've extracted and
            // reported "Package uploaded". Surfacing it here means the
            // admin learns the package can't run on this server before we
            // touch the filesystem, instead of hitting EE's wall later.
            //
            // The $overrideRequirements escape hatch lets an admin force
            // an install despite the declared requirement (use case:
            // author over-declared a PHP baseline the code doesn't
            // actually need). When set, we proceed and patch the
            // extracted setup.php further down — never silently, always
            // recorded + badged + audited.
            $requires = (array) ($info['metadata']['requires'] ?? []);
            $issues = self::checkRequirements($requires);

            // When incompatible, run the heuristic feature scan so the
            // verdict ("appears safe to force" / "uses json_validate()")
            // is available both for the refuse message and, if forced,
            // the override record + audit trail. Scoped to the PHP
            // target (the running version) so it reports only features
            // newer than what this server can run.
            $scan = null;
            if (! empty($issues)) {
                try {
                    $scan = $this->scanner()->scanFiles(
                        $this->collectZipPhp($zip, $info['root']),
                        PHP_VERSION
                    );
                } catch (\Throwable $e) {
                    // Scan is advisory; failure must not block the flow.
                }
            }

            if (! empty($issues) && ! $overrideRequirements) {
                $msg = 'This package cannot be installed on this server: '
                    . implode(' ', $issues)
                    . ' (Declared in the add-on\'s addon.setup.php; EE would refuse to install it too.)';
                if ($scan !== null) {
                    $msg .= ' — ' . $scan['summary'];
                }
                $msg .= ' Tick "Override version requirements" to force it anyway.';
                throw new RuntimeException($msg);
            }

            if (is_dir($targetPath) && ! $overwrite) {
                throw new RuntimeException('An add-on folder named "' . $shortName . '" already exists. Enable overwrite to replace it.');
            }

            // Capture the installed-in-DB version BEFORE we mutate any
            // disk state. After the swap, EE's getInstalledVersion()
            // still returns the DB-side number (which is what we want
            // for the "from" of the finalize banner) — but reading
            // it now is harmless and unambiguous.
            $oldVersion = '';
            if (function_exists('ee')) {
                try {
                    $addon = ee('Addon')->get($shortName);
                    if ($addon) {
                        $oldVersion = (string) $addon->getInstalledVersion();
                    }
                } catch (\Throwable $e) {
                    // best effort
                }
            }

            if (is_dir($targetPath)) {
                $this->removeDirectory($targetPath);
            }

            mkdir($targetPath, 0775, true);

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || $this->isIgnoredPath($name)) {
                    continue;
                }

                $relativePath = $this->stripRoot($name, $info['root']);
                if ($relativePath === null || $relativePath === '') {
                    continue;
                }

                $destination = $this->safeDestination($targetPath, $relativePath);
                if (str_ends_with($name, '/')) {
                    if (! is_dir($destination)) {
                        mkdir($destination, 0775, true);
                    }
                    continue;
                }

                $parent = dirname($destination);
                if (! is_dir($parent)) {
                    mkdir($parent, 0775, true);
                }

                $stream = $zip->getStream($name);
                if (! $stream) {
                    throw new RuntimeException('Unable to read "' . $name . '" from the ZIP package.');
                }

                $out = fopen($destination, 'wb');
                if (! $out) {
                    fclose($stream);
                    throw new RuntimeException('Unable to write "' . $relativePath . '".');
                }

                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
            }

            $newVersion = (string) ($info['metadata']['version'] ?? '');

            // Apply the requirement override (if forced + actually
            // incompatible). Patches the freshly-extracted addon.setup.php
            // so EE's native install gate reads a satisfied requirement,
            // records the original in the override registry for the
            // badge + audit trail, and re-applies cleanly on future
            // updates of the same add-on.
            $overrideApplied = false;
            if (! empty($issues) && $overrideRequirements) {
                $setupOnDisk = $targetPath . DIRECTORY_SEPARATOR . 'addon.setup.php';
                $original = self::patchRequiresInFile($setupOnDisk);
                if (! empty($original)) {
                    $overrideApplied = true;
                    $by = null;
                    if (function_exists('ee')) {
                        try {
                            $login = ee()->session->userdata('username');
                            $by = is_string($login) && $login !== '' ? $login : null;
                        } catch (\Throwable $e) {
                            // best effort
                        }
                    }
                    $patchedTo = [];
                    foreach (array_keys($original) as $k) {
                        $patchedTo[$k] = $k === 'php'
                            ? PHP_VERSION
                            : (defined('APP_VER') ? APP_VER : '');
                    }
                    $scanSummary = $scan['summary'] ?? null;
                    $scanVerdict = $scan['verdict'] ?? null;
                    $this->overrides()->record($shortName, $original, $patchedTo, $by, $overrideReason, $scanSummary);

                    try {
                        $this->auditor()->record([
                            'event'        => 'requirement_override',
                            'short_name'   => $shortName,
                            'version'      => $newVersion,
                            'source'       => 'upload_zip',
                            'original'     => $original,
                            'patched_to'   => $patchedTo,
                            'reason'       => $overrideReason,
                            'scan_verdict' => $scanVerdict,
                            'scan'         => $scanSummary,
                            'is_self'      => $shortName === 'addon_expert',
                        ]);
                    } catch (\Throwable $e) {
                        // non-fatal
                    }
                }
            }

            // Invalidate PHP opcache for every PHP file we just wrote.
            // Without this, the GET request that lands the user back
            // here can still see the OLD bytecode for addon.setup.php
            // for up to `opcache.revalidate_freq` seconds — at which
            // point EE reads the old version, hasUpdate() returns
            // false, and AutoFinalizer correctly decides there's
            // nothing to do. From the admin's POV the finalize banner
            // is missing "sometimes" (when revalidate_freq hadn't
            // elapsed). Explicit invalidation closes the race.
            $this->invalidateOpcache($targetPath);

            // Schedule the EE-side finalize. The next page load through
            // any of our routes (Install ZIP, Packages, Releases) will
            // see the marker and run upd::update() + the DB version
            // bump — same flow the GitHub one-click install uses.
            //
            // Without this the admin had to wait minutes for EE's own
            // periodic addon scan to surface the "Update Available"
            // prompt on Developer → Add-Ons. Now it fires
            // immediately, on the redirect back from the upload.
            try {
                $this->finalizer()->schedule($shortName, $oldVersion, $newVersion);
            } catch (\Throwable $e) {
                // Marker write failure is non-fatal — admin can finalize
                // manually via EE's native Update prompt.
            }

            // Mirror ReleaseInstaller's audit-log pattern so the
            // upload-zip and one-click paths both leave a forensic
            // trail. source=upload_zip distinguishes them from
            // github-driven installs (release-asset:* / source-zipball).
            try {
                $this->auditor()->record([
                    'event'       => 'install_ok',
                    'short_name'  => $shortName,
                    'version'     => $newVersion,
                    'from'        => $oldVersion,
                    'source'      => 'upload_zip',
                    'is_self'     => $shortName === 'addon_expert',
                ]);
            } catch (\Throwable $e) {
                // Audit failure is non-fatal.
            }

            return [
                'short_name'  => $shortName,
                'name'        => $info['metadata']['name'] ?? $shortName,
                'version'     => $newVersion,
                'target_path' => $targetPath,
                'override_applied' => $overrideApplied,
                'settings_url' => ee('CP/URL')->make('addons/settings/' . $shortName)->compile(),
                'manager_url'  => ee('CP/URL')->make('addons')->compile(),
                'install_url'  => ee('CP/URL')->make('addons/install/' . $shortName, [
                    'return' => ee('CP/URL')->make('addons/settings/addon_expert/packages')->encode(),
                ])->compile(),
            ];
        } finally {
            $zip->close();
        }
    }

    public function createPackageZip(string $shortName): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive is required to create add-on package downloads.');
        }

        $shortName = $this->normalizeShortName($shortName);
        if ($shortName === '') {
            throw new RuntimeException('Choose an add-on package to download.');
        }

        $packagePath = $this->addonsPath . $shortName;
        if (! is_dir($packagePath) || ! is_file($packagePath . DIRECTORY_SEPARATOR . 'addon.setup.php')) {
            throw new RuntimeException('The requested add-on package could not be found.');
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'addon-installer-');
        if ($tmpPath === false) {
            throw new RuntimeException('Unable to create a temporary package file.');
        }

        $zipPath = $tmpPath . '.zip';
        rename($tmpPath, $zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            throw new RuntimeException('Unable to create the add-on ZIP package.');
        }

        try {
            $this->addDirectoryToZip($zip, $packagePath, $shortName);
        } finally {
            $zip->close();
        }

        return $zipPath;
    }

    private function assertReady(): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive is required to install add-on packages.');
        }

        if (! is_dir($this->addonsPath) || ! is_writable($this->addonsPath)) {
            throw new RuntimeException('The ExpressionEngine add-ons folder is not writable: ' . $this->addonsPath);
        }
    }

    private function inspectZip(ZipArchive $zip): array
    {
        $setupPath = null;
        $root = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || $this->isIgnoredPath($name)) {
                continue;
            }

            $this->assertSafeRelativePath($name);

            if (basename($name) === 'addon.setup.php') {
                $root = trim(dirname(str_replace('\\', '/', $name)), '/.');
                if ($root === '') {
                    throw new RuntimeException('The ZIP must contain an add-on folder, not loose add-on files.');
                }

                $setupPath = $name;
                break;
            }
        }

        if ($setupPath === null || $root === null) {
            throw new RuntimeException('No addon.setup.php file was found in the ZIP package.');
        }

        $shortName = basename($root);
        if ($shortName !== $this->normalizeShortName($shortName)) {
            throw new RuntimeException('The detected add-on folder must be a valid ExpressionEngine add-on short name: lowercase letters, numbers, and underscores.');
        }

        $metadata = $this->readSetupString($zip->getFromName($setupPath));

        return [
            'root' => $root,
            'short_name' => $shortName,
            'metadata' => $metadata,
        ];
    }

    private function readSetupMetadata(string $setupPath): array
    {
        return $this->parseSetupMetadata((string) file_get_contents($setupPath));
    }

    private function readSetupString($contents): array
    {
        if (! is_string($contents) || trim($contents) === '') {
            return [];
        }

        return $this->parseSetupMetadata($contents);
    }

    private function parseSetupMetadata(string $contents): array
    {
        $metadata = [];

        foreach (['name', 'description', 'version', 'author', 'author_url', 'namespace'] as $key) {
            if (preg_match("/['\"]" . preg_quote($key, '/') . "['\"]\\s*=>\\s*(['\"])(.*?)\\1/s", $contents, $match)) {
                $metadata[$key] = stripcslashes($match[2]);
            }
        }

        $requires = $this->parseRequires($contents);
        if (! empty($requires)) {
            $metadata['requires'] = $requires;
        }

        return $metadata;
    }

    /**
     * Extract the `requires` block from a setup.php string. EE's format is
     * a flat array of scalars:
     *
     *   'requires' => ['php' => '8.3', 'ee' => '7.0.0', 'mysql' => '5.6'],
     *
     * We parse by regex rather than include() — the upload flow must NOT
     * execute untrusted PHP (a security property the README advertises),
     * and the GitHub flow must not load PHP-8.3 syntax on a PHP-8.1 host
     * (a parse fatal would take down the request). We grab the substring
     * from `requires =>` to the first closing bracket and pull scalar
     * values from it. Nested arrays inside requires (non-standard) would
     * truncate early — acceptable degradation, never a crash.
     *
     * @return array<string,string>
     */
    private function parseRequires(string $contents): array
    {
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

    /**
     * Compare a parsed `requires` array against the running environment.
     * Mirrors EE's own enforcement in
     * Controller\Addons\Addons (version_compare with '<'), so we surface
     * the SAME verdict EE would at install time — just earlier, before we
     * extract or swap anything.
     *
     * @return string[] Human-readable incompatibility messages (empty = OK)
     */
    public static function checkRequirements(array $requires): array
    {
        $issues = [];

        if (! empty($requires['php']) && version_compare(PHP_VERSION, (string) $requires['php'], '<')) {
            $issues[] = sprintf(
                'Requires PHP %s — this server runs PHP %s.',
                $requires['php'],
                PHP_VERSION
            );
        }

        if (! empty($requires['ee']) && defined('APP_VER') && version_compare(APP_VER, (string) $requires['ee'], '<')) {
            $issues[] = sprintf(
                'Requires ExpressionEngine %s — this site runs %s.',
                $requires['ee'],
                APP_VER
            );
        }

        return $issues;
    }

    /**
     * Rewrite the `requires` block of an on-disk addon.setup.php so the
     * failing keys point at the running environment, making EE's native
     * install gate pass. Only the keys that are actually too-high are
     * lowered (php → PHP_VERSION, ee → APP_VER); satisfied keys are left
     * alone. Returns the ORIGINAL requires values that were changed, for
     * the override registry.
     *
     * Edits are scoped to the requires block region (located by regex
     * with offset capture) so we never touch a stray 'php' => '...'
     * elsewhere in the file. No include()/eval — pure string surgery.
     *
     * @return array<string,string> original values of changed keys (empty = nothing changed)
     */
    public static function patchRequiresInFile(string $setupPath): array
    {
        $contents = @file_get_contents($setupPath);
        if ($contents === false || $contents === '') {
            return [];
        }

        if (! preg_match('/[\'"]requires[\'"]\s*=>\s*(?:\[|array\s*\()/s', $contents, $open, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $blockStart = $open[0][1] + strlen($open[0][0]);
        // Find the first closing bracket after the opener — requires
        // arrays are flat, so this is the block end.
        $closePos = strcspn(substr($contents, $blockStart), '])');
        $blockEnd = $blockStart + $closePos;
        $block = substr($contents, $blockStart, $blockEnd - $blockStart);

        $changed = [];
        $newBlock = $block;

        $lowerTargets = [
            'php' => PHP_VERSION,
            'ee'  => defined('APP_VER') ? APP_VER : null,
        ];

        foreach ($lowerTargets as $key => $runningVersion) {
            if ($runningVersion === null) {
                continue;
            }
            if (! preg_match("/(['\"]" . $key . "['\"]\\s*=>\\s*)(['\"])([^'\"]*)\\2/s", $newBlock, $m)) {
                continue;
            }
            $declared = $m[3];
            // Only lower keys that are actually blocking.
            if (version_compare($runningVersion, $declared, '>=')) {
                continue;
            }
            $changed[$key] = $declared;
            $replacement = $m[1] . $m[2] . $runningVersion . $m[2];
            $newBlock = str_replace($m[0], $replacement, $newBlock);
        }

        if (empty($changed)) {
            return [];
        }

        $patched = substr($contents, 0, $blockStart) . $newBlock . substr($contents, $blockEnd);
        if (@file_put_contents($setupPath, $patched) === false) {
            throw new RuntimeException('Could not write the requirement override to ' . $setupPath . '.');
        }

        return $changed;
    }

    private function safeDestination(string $targetPath, string $relativePath): string
    {
        $this->assertSafeRelativePath($relativePath);

        return rtrim($targetPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function assertSafeRelativePath(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        $parts = explode('/', $normalized);

        if (str_starts_with($normalized, '/') || preg_match('#^[a-zA-Z]:/#', $normalized)) {
            throw new RuntimeException('The ZIP contains an absolute path and was rejected.');
        }

        if (in_array('..', $parts, true)) {
            throw new RuntimeException('The ZIP contains a parent-directory path and was rejected.');
        }
    }

    private function stripRoot(string $path, string $root): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $prefix = rtrim($root, '/') . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : null;
    }

    private function normalizeShortName(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9_]/', '_', $value));
    }

    private function isIgnoredPath(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        return $path === '' || str_starts_with($path, '__MACOSX/') || str_contains($path, '/.DS_Store') || str_ends_with($path, '.DS_Store');
    }

    private function removeDirectory(string $path): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }

    /**
     * Best-effort opcache invalidation for every PHP file under
     * $targetPath. Silent on failure — opcache may be disabled, the
     * extension may not be loaded, or the install may still complete
     * fine without forced invalidation (just slower convergence).
     *
     * Same shape as ReleaseInstaller::invalidateOpcache. Worth
     * extracting to a shared trait if a third caller ever needs it.
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
            // Swallow — non-fatal.
        }
    }

    private function addDirectoryToZip(ZipArchive $zip, string $path, string $shortName): void
    {
        $basePath = rtrim($path, DIRECTORY_SEPARATOR);
        $baseLength = strlen($basePath) + 1;

        $zip->addEmptyDir($shortName);

        $directory = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $item) {
                return ! in_array($item->getFilename(), ['.git', '.DS_Store'], true);
            }
        );

        $items = new \RecursiveIteratorIterator(
            $directory,
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            $relativePath = $shortName . '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), $baseLength));
            if ($item->isDir()) {
                $zip->addEmptyDir($relativePath);
                continue;
            }

            $zip->addFile($item->getPathname(), $relativePath);
        }
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded ZIP is larger than the server upload limit.',
            UPLOAD_ERR_PARTIAL => 'The ZIP upload did not complete.',
            UPLOAD_ERR_NO_FILE => 'Choose a ZIP package to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload temp directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded ZIP.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the ZIP upload.',
            default => 'The ZIP upload failed.',
        };
    }
}
