<?php

namespace Nivoli\AddonExpert\Service;

/**
 * Resolves where to look for newer versions of each installed add-on.
 *
 * Resolution priority for a given short_name:
 *
 *   1. Author manifest key — addon.setup.php declares
 *      `'github_repo' => 'owner/repo'` or
 *      `'update_source' => 'github:owner/repo'`. Opt-in, decentralized.
 *
 *   2. Admin map — site admin filled in a repo via Add-on Manager's
 *      Releases screen. Persisted to a JSON file under user/config so it
 *      survives cache wipes.
 *
 * The manifest takes precedence so an opted-in add-on can't be silently
 * misrouted by a stale admin entry.
 */
class UpdateSourceRegistry
{
    public const SOURCE_MANIFEST = 'manifest';
    public const SOURCE_ADMIN    = 'admin';

    private string $mapFile;
    private string $addonsPath;

    /** Lazy-loaded admin map: [short_name => 'owner/repo']. */
    private ?array $adminMap = null;

    public function __construct(?string $mapFile = null, ?string $addonsPath = null)
    {
        $this->mapFile = $mapFile ?: self::defaultMapFile();
        $this->addonsPath = rtrim(
            $addonsPath ?: PackageInstaller::detectAddonsPath(),
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR;
    }

    public static function defaultMapFile(): string
    {
        $base = defined('SYSPATH')
            ? SYSPATH . 'user/config'
            : sys_get_temp_dir();

        return rtrim($base, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'addon_expert_mappings.json';
    }

    /**
     * Resolve $shortName to an update source. Returns null when no mapping
     * exists in either layer. The `type` discriminator is either:
     *   - 'github'   → also carries `repo`   (owner/repo)
     *   - 'registry' → also carries `url` + `product`
     *
     * GitHub results keep `repo` for backward compatibility; consumers that
     * only handle GitHub should check `type` and skip non-github sources.
     *
     * @return array{type:string,source:string,repo?:string,url?:string,product?:string}|null
     */
    public function resolve(string $shortName): ?array
    {
        $manifestRepo = $this->repoFromManifest($shortName);
        if ($manifestRepo !== null) {
            return ['type' => 'github', 'repo' => $manifestRepo, 'source' => self::SOURCE_MANIFEST];
        }

        $manifestRegistry = $this->registryFromManifest($shortName);
        if ($manifestRegistry !== null) {
            return [
                'type'    => 'registry',
                'url'     => $manifestRegistry['url'],
                'product' => $manifestRegistry['product'],
                'source'  => self::SOURCE_MANIFEST,
            ];
        }

        $adminRepo = $this->adminMap()[$shortName] ?? null;
        if (is_string($adminRepo) && $adminRepo !== '') {
            return ['type' => 'github', 'repo' => $adminRepo, 'source' => self::SOURCE_ADMIN];
        }

        return null;
    }

    /** Full admin map (short_name => owner/repo), read-only. */
    public function all(): array
    {
        return $this->adminMap();
    }

    /**
     * Replace the admin map. Caller passes [short_name => owner/repo] —
     * empty values delete the mapping for that short_name. Invalid repo
     * strings are silently dropped.
     */
    public function saveAll(array $map): bool
    {
        $clean = [];
        foreach ($map as $shortName => $repo) {
            $shortName = (string) $shortName;
            $repo = trim((string) $repo);
            if ($shortName === '' || $repo === '') {
                continue;
            }
            if (! GitHubReleaseChecker::isValidRepo($repo)) {
                continue;
            }
            $clean[$shortName] = $repo;
        }

        $this->adminMap = $clean;

        $dir = dirname($this->mapFile);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return false !== @file_put_contents(
            $this->mapFile,
            json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /** Repo declared in the add-on's own setup.php, or null if absent. */
    public function repoFromManifest(string $shortName): ?string
    {
        $setup = $this->addonsPath . $shortName . DIRECTORY_SEPARATOR . 'addon.setup.php';
        if (! is_file($setup)) {
            return null;
        }

        try {
            $meta = include $setup;
        } catch (\Throwable $e) {
            return null;
        }

        if (! is_array($meta)) {
            return null;
        }

        // Preferred key: 'github_repo' => 'owner/repo'.
        if (! empty($meta['github_repo']) && is_string($meta['github_repo'])) {
            $repo = trim($meta['github_repo']);
            if (GitHubReleaseChecker::isValidRepo($repo)) {
                return $repo;
            }
        }

        // Alternate key: 'update_source' => 'github:owner/repo'.
        if (! empty($meta['update_source']) && is_string($meta['update_source'])) {
            $src = trim($meta['update_source']);
            if (strncmp($src, 'github:', 7) === 0) {
                $repo = substr($src, 7);
                if (GitHubReleaseChecker::isValidRepo($repo)) {
                    return $repo;
                }
            }
        }

        return null;
    }

    /**
     * Registry source declared in the add-on's setup.php, or null:
     *
     *   'registry' => ['url' => 'https://vendor/releases', 'product' => 'slug'],
     *
     * The product slug is normalized to [a-z0-9_]; the URL must be https.
     *
     * @return array{url:string,product:string}|null
     */
    public function registryFromManifest(string $shortName): ?array
    {
        $setup = $this->addonsPath . $shortName . DIRECTORY_SEPARATOR . 'addon.setup.php';
        if (! is_file($setup)) {
            return null;
        }
        try {
            $meta = include $setup;
        } catch (\Throwable $e) {
            return null;
        }
        if (! is_array($meta) || empty($meta['registry']) || ! is_array($meta['registry'])) {
            return null;
        }
        $url = trim((string) ($meta['registry']['url'] ?? ''));
        $product = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($meta['registry']['product'] ?? '')));
        if (! RegistryReleaseChecker::isValidEndpoint($url) || $product === '') {
            return null;
        }
        return ['url' => $url, 'product' => $product];
    }

    private function adminMap(): array
    {
        if ($this->adminMap !== null) {
            return $this->adminMap;
        }

        if (! is_file($this->mapFile)) {
            return $this->adminMap = [];
        }

        $body = @file_get_contents($this->mapFile);
        if ($body === false || $body === '') {
            return $this->adminMap = [];
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return $this->adminMap = [];
        }

        $clean = [];
        foreach ($decoded as $shortName => $repo) {
            if (! is_string($shortName) || ! is_string($repo)) {
                continue;
            }
            if (GitHubReleaseChecker::isValidRepo($repo)) {
                $clean[$shortName] = $repo;
            }
        }

        return $this->adminMap = $clean;
    }
}
