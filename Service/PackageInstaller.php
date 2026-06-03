<?php

namespace JavidFazaeli\AddonInstaller\Service;

use RuntimeException;
use ZipArchive;

class PackageInstaller
{
    private string $addonsPath;

    private ?UpdateSourceRegistry $sources = null;
    private ?GitHubReleaseChecker $releases = null;

    public function __construct(
        ?string $addonsPath = null,
        ?UpdateSourceRegistry $sources = null,
        ?GitHubReleaseChecker $releases = null
    ) {
        $this->addonsPath = rtrim($addonsPath ?: self::detectAddonsPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->sources = $sources;
        $this->releases = $releases;
    }

    private function sources(): UpdateSourceRegistry
    {
        if ($this->sources === null) {
            $this->sources = function_exists('ee')
                ? ee('addon_installer:updateSourceRegistry')
                : new UpdateSourceRegistry(null, $this->addonsPath);
        }
        return $this->sources;
    }

    private function releases(): GitHubReleaseChecker
    {
        if ($this->releases === null) {
            $this->releases = function_exists('ee')
                ? ee('addon_installer:githubReleaseChecker')
                : new GitHubReleaseChecker();
        }
        return $this->releases;
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
            if (is_dir($path) && is_file($path . 'addon_installer' . DIRECTORY_SEPARATOR . 'addon.setup.php')) {
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
        $returnUrl = ee('CP/URL')->make('addons/settings/addon_installer/packages')->encode();

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
                'remote_repo' => $remote['repo'],
                'remote_repo_source' => $remote['source'],
                'remote_version' => $remote['version'],
                'remote_release_url' => $remote['release_url'],
                'remote_release_name' => $remote['release_name'],
                'remote_published_at' => $remote['published_at'],
                'remote_checked_at' => $remote['checked_at'],
                'remote_update_available' => $remote['update_available'],
                'remote_status' => $remote['status'],
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
                'download_url' => ee('CP/URL')->make('addons/settings/addon_installer/packages', [
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
        ];

        $mapping = $this->sources()->resolve($shortName);
        if ($mapping === null) {
            return $empty;
        }

        $repo = $mapping['repo'];
        $checker = $this->releases();
        $checkedAt = $checker->lastCheckedAt($repo);
        $cached = $checker->cached($repo);

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
            if ($mapping === null) {
                continue;
            }

            $data = $checker->refresh($mapping['repo']);
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

    public function installUploaded(array $file, bool $overwrite = false): array
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

        $zip = new ZipArchive();
        if ($zip->open($tmpName) !== true) {
            throw new RuntimeException('The ZIP package could not be opened.');
        }

        try {
            $info = $this->inspectZip($zip);
            $targetPath = $this->addonsPath . $info['short_name'];

            if (is_dir($targetPath) && ! $overwrite) {
                throw new RuntimeException('An add-on folder named "' . $info['short_name'] . '" already exists. Enable overwrite to replace it.');
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

            return [
                'short_name' => $info['short_name'],
                'name' => $info['metadata']['name'] ?? $info['short_name'],
                'version' => $info['metadata']['version'] ?? '',
                'target_path' => $targetPath,
                'settings_url' => ee('CP/URL')->make('addons/settings/' . $info['short_name'])->compile(),
                'manager_url' => ee('CP/URL')->make('addons')->compile(),
                'install_url' => ee('CP/URL')->make('addons/install/' . $info['short_name'], [
                    'return' => ee('CP/URL')->make('addons/settings/addon_installer/packages')->encode(),
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

        return $metadata;
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
