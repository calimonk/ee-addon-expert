<?php

namespace JavidFazaeli\AddonInstaller\Service;

/**
 * Fetches the latest release for a GitHub repo and caches it on disk.
 *
 * Scope is intentionally narrow: no auth, public repos only. GitHub allows
 * 60 unauthenticated requests/hour per IP — plenty for daily polling of all
 * tracked add-ons on a single site.
 *
 * Failed fetches write an empty sentinel so repeated CP loads don't hammer
 * GitHub when the network is sad. Sentinel respects the same TTL as a hit.
 */
class GitHubReleaseChecker
{
    /**
     * Seconds between live fetches for the same repo. Short enough that
     * a daily-CP-visit admin always gets fresh data; long enough that
     * cache absorbs rapid CP navigation without hammering GitHub.
     */
    private const TTL_SECONDS = 3600;

    /** Repo-identity cache TTL. Identity changes rarely; weekly is fine. */
    private const IDENTITY_TTL_SECONDS = 7 * 24 * 3600;

    /** Seconds to wait for the GitHub API before giving up. */
    private const HTTP_TIMEOUT = 4;

    /** User-Agent string GitHub requires on API calls. */
    private const USER_AGENT = 'addon-installer-ee-cp';

    private string $cacheDir;

    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = rtrim($cacheDir ?: self::detectCacheDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public static function detectCacheDir(): string
    {
        $base = defined('SYSPATH')
            ? SYSPATH . 'user/cache'
            : sys_get_temp_dir();

        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'addon_installer';
    }

    /**
     * Return the cached release data for $ownerRepo if it exists, regardless
     * of age. Returns null when no cache entry has ever been written, and
     * returns null for a sentinel ("we tried and it failed") entry.
     *
     * Used by package listing — we do NOT want per-package HTTP on every CP
     * page load, so the listing is cache-only. Refreshes are explicit.
     */
    public function cached(string $ownerRepo): ?array
    {
        $file = $this->cacheFile($ownerRepo);
        if (! is_file($file)) {
            return null;
        }

        $body = @file_get_contents($file);
        if ($body === false) {
            return null;
        }

        $data = json_decode($body, true);
        if (! is_array($data) || isset($data['_empty'])) {
            return null;
        }

        return $data;
    }

    /**
     * Last-checked unix timestamp for $ownerRepo regardless of hit/miss.
     * Returns 0 if no cache file exists yet.
     */
    public function lastCheckedAt(string $ownerRepo): int
    {
        $file = $this->cacheFile($ownerRepo);
        return is_file($file) ? (int) filemtime($file) : 0;
    }

    /** True if the cache entry for $ownerRepo is older than TTL_SECONDS. */
    public function isStale(string $ownerRepo): bool
    {
        $ts = $this->lastCheckedAt($ownerRepo);
        return $ts === 0 || (time() - $ts) >= self::TTL_SECONDS;
    }

    /**
     * Hit GitHub regardless of cache age and persist the result. Returns the
     * parsed release on success or null on failure. Failures still write a
     * sentinel so a follow-up call inside TTL won't re-hit the network.
     */
    public function refresh(string $ownerRepo): ?array
    {
        if (! self::isValidRepo($ownerRepo)) {
            return null;
        }

        $data = $this->fetch($ownerRepo);
        $this->ensureCacheDir();
        @file_put_contents(
            $this->cacheFile($ownerRepo),
            json_encode($data ?: ['_empty' => time()])
        );

        return $data;
    }

    /** Hit cache first; refresh only if stale. */
    public function latest(string $ownerRepo): ?array
    {
        if (! $this->isStale($ownerRepo)) {
            return $this->cached($ownerRepo);
        }
        return $this->refresh($ownerRepo);
    }

    /**
     * Refresh many repos in parallel via curl_multi. Whole batch
     * completes within a single round-trip (limited by the slowest
     * response or HTTP_TIMEOUT, whichever comes first), not N
     * round-trips like sequential refresh() calls.
     *
     * Sentinel-on-failure semantics are preserved per repo — a failure
     * to fetch ownerA doesn't affect the cache entry for ownerB. The
     * caller gets back a [ownerRepo => ?array] map matching the input.
     *
     * Falls back to sequential refresh() calls if cURL multi isn't
     * available (e.g. PHP without curl extension).
     *
     * @param string[] $ownerRepos
     * @return array<string,?array>
     */
    public function refreshMultiple(array $ownerRepos): array
    {
        $ownerRepos = array_values(array_unique(array_filter(
            $ownerRepos,
            [self::class, 'isValidRepo']
        )));

        if (empty($ownerRepos)) {
            return [];
        }

        if (! function_exists('curl_multi_init')) {
            $out = [];
            foreach ($ownerRepos as $r) {
                $out[$r] = $this->refresh($r);
            }
            return $out;
        }

        $this->ensureCacheDir();

        $mh = curl_multi_init();
        $handles = [];

        foreach ($ownerRepos as $r) {
            $ch = curl_init('https://api.github.com/repos/' . $r . '/releases/latest');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/vnd.github+json',
                    'X-GitHub-Api-Version: 2022-11-28',
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$r] = $ch;
        }

        // Run all transfers until done — curl_multi_exec returns
        // CURLM_OK once nothing's running. Modern PHP (>=7.2) supports
        // curl_multi_select to avoid the spin-loop.
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $r => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $data = $this->parseReleaseBody($code, $body);

            @file_put_contents(
                $this->cacheFile($r),
                json_encode($data ?: ['_empty' => time()])
            );

            $results[$r] = $data;
        }

        curl_multi_close($mh);
        return $results;
    }

    /**
     * Parse a release-API response body into our cache shape. Returns
     * null on any failure (caller writes the empty sentinel).
     */
    private function parseReleaseBody(int $code, $body): ?array
    {
        if ($code !== 200 || ! is_string($body) || $body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || empty($decoded['tag_name'])) {
            return null;
        }

        $assets = [];
        foreach ((array) ($decoded['assets'] ?? []) as $asset) {
            if (! is_array($asset)) continue;
            $name = (string) ($asset['name'] ?? '');
            $url  = (string) ($asset['browser_download_url'] ?? '');
            if ($name === '' || $url === '') continue;
            $assets[] = [
                'name'         => $name,
                'url'          => $url,
                'size'         => (int) ($asset['size'] ?? 0),
                'content_type' => (string) ($asset['content_type'] ?? ''),
            ];
        }

        return [
            'tag'          => (string) $decoded['tag_name'],
            'version'      => ltrim((string) $decoded['tag_name'], 'vV'),
            'name'         => (string) ($decoded['name'] ?? $decoded['tag_name']),
            'html_url'     => (string) ($decoded['html_url'] ?? ''),
            'published_at' => (string) ($decoded['published_at'] ?? ''),
            'body'         => (string) ($decoded['body'] ?? ''),
            'zipball_url'  => (string) ($decoded['zipball_url'] ?? ''),
            'assets'       => $assets,
            'fetched_at'   => time(),
        ];
    }

    /** Forget the cache entry for $ownerRepo. */
    public function forget(string $ownerRepo): void
    {
        $file = $this->cacheFile($ownerRepo);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Loose owner/repo validator. Mirrors GitHub's allowed character set so
     * we don't make HTTP calls for clearly malformed strings.
     */
    public static function isValidRepo(string $ownerRepo): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*$#', $ownerRepo);
    }

    /**
     * Compare a remote release to an installed version. Tag is normalized by
     * stripping a leading "v"/"V" — matches the loose convention used by
     * the calimonk Edge Cache add-ons (and most EE add-ons that tag
     * releases on GitHub).
     */
    public static function isNewer(string $remoteTag, string $installedVersion): bool
    {
        $remote = ltrim($remoteTag, 'vV');
        $installed = ltrim($installedVersion, 'vV');
        if ($remote === '' || $installed === '') {
            return false;
        }
        return version_compare($remote, $installed, '>');
    }

    private function cacheFile(string $ownerRepo): string
    {
        $safe = preg_replace('#[^A-Za-z0-9._-]+#', '_', $ownerRepo);
        return $this->cacheDir . 'release_' . $safe . '.json';
    }

    private function identityCacheFile(string $ownerRepo): string
    {
        $safe = preg_replace('#[^A-Za-z0-9._-]+#', '_', $ownerRepo);
        return $this->cacheDir . 'repo_' . $safe . '.json';
    }

    /* ---- repo identity (TOFU) -------------------------------------- */

    /**
     * Cached repo identity (owner_id, repo_id, created_at, etc.) without
     * making an HTTP call. Returns null on miss/sentinel.
     *
     * @return array{
     *   owner_id:int, owner_login:string, repo_id:int, full_name:string,
     *   created_at:string, default_branch:string, fetched_at:int
     * }|null
     */
    public function repoIdentityCached(string $ownerRepo): ?array
    {
        $file = $this->identityCacheFile($ownerRepo);
        if (! is_file($file)) {
            return null;
        }
        $body = @file_get_contents($file);
        if ($body === false) {
            return null;
        }
        $data = json_decode($body, true);
        if (! is_array($data) || isset($data['_empty'])) {
            return null;
        }
        return $data;
    }

    public function repoIdentityLastFetchedAt(string $ownerRepo): int
    {
        $file = $this->identityCacheFile($ownerRepo);
        return is_file($file) ? (int) filemtime($file) : 0;
    }

    public function repoIdentityIsStale(string $ownerRepo): bool
    {
        $ts = $this->repoIdentityLastFetchedAt($ownerRepo);
        return $ts === 0 || (time() - $ts) >= self::IDENTITY_TTL_SECONDS;
    }

    /** Hit GitHub regardless of cache age. Used at install time. */
    public function repoIdentityRefresh(string $ownerRepo): ?array
    {
        if (! self::isValidRepo($ownerRepo)) {
            return null;
        }
        $data = $this->fetchRepoIdentity($ownerRepo);
        $this->ensureCacheDir();
        @file_put_contents(
            $this->identityCacheFile($ownerRepo),
            json_encode($data ?: ['_empty' => time()])
        );
        return $data;
    }

    /** Cache-first; refresh on stale or miss. */
    public function repoIdentity(string $ownerRepo): ?array
    {
        if (! $this->repoIdentityIsStale($ownerRepo)) {
            return $this->repoIdentityCached($ownerRepo);
        }
        return $this->repoIdentityRefresh($ownerRepo);
    }

    public function forgetRepoIdentity(string $ownerRepo): void
    {
        $file = $this->identityCacheFile($ownerRepo);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function fetchRepoIdentity(string $ownerRepo): ?array
    {
        $url = 'https://api.github.com/repos/' . $ownerRepo;
        [$code, $body] = $this->httpGet($url);

        if ($code !== 200 || ! is_string($body) || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded) || empty($decoded['id'])) {
            return null;
        }

        return [
            'owner_id'       => (int) ($decoded['owner']['id'] ?? 0),
            'owner_login'    => (string) ($decoded['owner']['login'] ?? ''),
            'repo_id'        => (int) $decoded['id'],
            'full_name'      => (string) ($decoded['full_name'] ?? $ownerRepo),
            'created_at'     => (string) ($decoded['created_at'] ?? ''),
            'default_branch' => (string) ($decoded['default_branch'] ?? ''),
            'fetched_at'     => time(),
        ];
    }

    /**
     * Shared HTTP GET against the GitHub API. Returns [http_code, body].
     * Used by both release-latest and repo-identity fetches.
     *
     * @return array{0:int,1:?string}
     */
    private function httpGet(string $url): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/vnd.github+json',
                    'X-GitHub-Api-Version: 2022-11-28',
                ],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method'        => 'GET',
                    'timeout'       => self::HTTP_TIMEOUT,
                    'ignore_errors' => true,
                    'header'        => "User-Agent: " . self::USER_AGENT . "\r\n"
                        . "Accept: application/vnd.github+json\r\n"
                        . "X-GitHub-Api-Version: 2022-11-28\r\n",
                ],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            $code = 200;
            if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
            if ($body === false) {
                $code = 0;
            }
        }

        return [$code, is_string($body) ? $body : null];
    }

    private function ensureCacheDir(): void
    {
        if (! is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    private function fetch(string $ownerRepo): ?array
    {
        $url = 'https://api.github.com/repos/' . $ownerRepo . '/releases/latest';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/vnd.github+json',
                    'X-GitHub-Api-Version: 2022-11-28',
                ],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method'        => 'GET',
                    'timeout'       => self::HTTP_TIMEOUT,
                    'ignore_errors' => true,
                    'header'        => "User-Agent: " . self::USER_AGENT . "\r\n"
                        . "Accept: application/vnd.github+json\r\n"
                        . "X-GitHub-Api-Version: 2022-11-28\r\n",
                ],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            $code = 200;
            if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
            if ($body === false) {
                $code = 0;
            }
        }

        if ($code !== 200 || ! is_string($body) || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded) || empty($decoded['tag_name'])) {
            return null;
        }

        // Keep only the asset fields we actually consume — the release
        // payload can be very large (~tens of KB) and we don't want to
        // dump it all into the cache file.
        $assets = [];
        foreach ((array) ($decoded['assets'] ?? []) as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            $url  = (string) ($asset['browser_download_url'] ?? '');
            if ($name === '' || $url === '') {
                continue;
            }
            $assets[] = [
                'name'         => $name,
                'url'          => $url,
                'size'         => (int) ($asset['size'] ?? 0),
                'content_type' => (string) ($asset['content_type'] ?? ''),
            ];
        }

        return [
            'tag'          => (string) $decoded['tag_name'],
            'version'      => ltrim((string) $decoded['tag_name'], 'vV'),
            'name'         => (string) ($decoded['name'] ?? $decoded['tag_name']),
            'html_url'     => (string) ($decoded['html_url'] ?? ''),
            'published_at' => (string) ($decoded['published_at'] ?? ''),
            'body'         => (string) ($decoded['body'] ?? ''),
            'zipball_url'  => (string) ($decoded['zipball_url'] ?? ''),
            'assets'       => $assets,
            'fetched_at'   => time(),
        ];
    }
}
