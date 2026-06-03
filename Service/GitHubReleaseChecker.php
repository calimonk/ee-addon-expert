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
    /** Seconds between live fetches for the same repo. */
    private const TTL_SECONDS = 12 * 3600;

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

        return [
            'tag'          => (string) $decoded['tag_name'],
            'version'      => ltrim((string) $decoded['tag_name'], 'vV'),
            'name'         => (string) ($decoded['name'] ?? $decoded['tag_name']),
            'html_url'     => (string) ($decoded['html_url'] ?? ''),
            'published_at' => (string) ($decoded['published_at'] ?? ''),
            'body'         => (string) ($decoded['body'] ?? ''),
            'fetched_at'   => time(),
        ];
    }
}
