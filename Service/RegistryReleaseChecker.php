<?php

namespace Nivoli\AddonExpert\Service;

/**
 * Polls a license-gated release registry (the `registry:` update source)
 * for the latest version of a product, and caches it on disk.
 *
 * Mirrors GitHubReleaseChecker's shape (cached / isStale / latest / refresh,
 * 1h TTL, sentinel-on-failure) so the install pipeline can treat the two
 * sources uniformly. The difference: instead of GitHub's public API, this
 * POSTs `{key, product, current_version}` to a vendor endpoint that
 * validates the license and returns a signed download URL + sha256. The
 * vendor holds the GitHub token; the site holds only a license key.
 *
 * The HTTP transport is injectable (constructor `$http`) so the parsing,
 * caching, and error handling are unit-testable against canned worker
 * responses without a live endpoint.
 *
 * Contract (see docs/registry-design.md):
 *   POST <baseUrl> {key, product, current_version}
 *     200 {ok:true, version, current, notes, url, sha256, size}
 *     4xx/5xx {ok:false, reason}
 */
class RegistryReleaseChecker
{
    private const TTL_SECONDS = 3600;
    private const HTTP_TIMEOUT = 6;
    private const USER_AGENT = 'addon-expert-registry-client';

    private string $cacheDir;

    /** @var callable|null fn(string $url, string $jsonBody, int $timeout): array{0:int,1:?string} */
    private $http;

    /** @var array{code:int,reason:string}|null reason the last refresh() failed */
    private ?array $lastError = null;

    public function __construct(?string $cacheDir = null, ?callable $http = null)
    {
        $this->cacheDir = rtrim($cacheDir ?: self::detectCacheDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->http = $http;
    }

    /**
     * Why the most recent refresh() returned null (HTTP code + a reason
     * token, e.g. invalid_key / not_entitled / expired / not_configured /
     * unreachable / bad_response). Null after a successful refresh.
     *
     * @return array{code:int,reason:string}|null
     */
    public function lastError(): ?array
    {
        return $this->lastError;
    }

    public static function detectCacheDir(): string
    {
        $base = defined('SYSPATH') ? SYSPATH . 'user/cache' : sys_get_temp_dir();
        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'addon_expert';
    }

    /** Loose validator for a registry base URL (https only). */
    public static function isValidEndpoint(string $url): bool
    {
        return (bool) preg_match('~^https://[^\s./]+\.[^\s]+$~i', $url);
    }

    /** Cached manifest for (baseUrl, product), regardless of age; null on miss/sentinel. */
    public function cached(string $baseUrl, string $product): ?array
    {
        $file = $this->cacheFile($baseUrl, $product);
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

    public function lastCheckedAt(string $baseUrl, string $product): int
    {
        $file = $this->cacheFile($baseUrl, $product);
        return is_file($file) ? (int) filemtime($file) : 0;
    }

    public function isStale(string $baseUrl, string $product): bool
    {
        $ts = $this->lastCheckedAt($baseUrl, $product);
        return $ts === 0 || (time() - $ts) >= self::TTL_SECONDS;
    }

    /** Cache-first; refresh only when stale. */
    public function latest(string $baseUrl, string $product, string $licenseKey, string $currentVersion = ''): ?array
    {
        if (! $this->isStale($baseUrl, $product)) {
            return $this->cached($baseUrl, $product);
        }
        return $this->refresh($baseUrl, $product, $licenseKey, $currentVersion);
    }

    /** Hit the endpoint regardless of cache age; persist result (or sentinel). */
    public function refresh(string $baseUrl, string $product, string $licenseKey, string $currentVersion = ''): ?array
    {
        if (! self::isValidEndpoint($baseUrl) || $product === '') {
            return null;
        }

        $payload = json_encode([
            'key'             => $licenseKey,
            'product'         => $product,
            'current_version' => $currentVersion,
        ]);

        [$code, $body] = $this->post($baseUrl, (string) $payload);
        $data = $this->parse($code, $body);
        $this->lastError = $data === null ? $this->errorFrom($code, $body) : null;

        $this->ensureCacheDir();
        @file_put_contents(
            $this->cacheFile($baseUrl, $product),
            json_encode($data ?: ['_empty' => time()])
        );

        return $data;
    }

    public function forget(string $baseUrl, string $product): void
    {
        $file = $this->cacheFile($baseUrl, $product);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Parse a manifest response into the normalized shape, or null on any
     * non-OK / malformed response (caller writes the empty sentinel).
     *
     * @return array{version:string,notes:string,download_url:string,sha256:string,size:int,fetched_at:int}|null
     */
    private function parse(int $code, ?string $body): ?array
    {
        if ($code !== 200 || ! is_string($body) || $body === '') {
            return null;
        }
        $d = json_decode($body, true);
        if (! is_array($d) || empty($d['ok']) || empty($d['version']) || empty($d['url'])) {
            return null;
        }
        return [
            'version'      => ltrim((string) $d['version'], 'vV'),
            'notes'        => (string) ($d['notes'] ?? ''),
            'download_url' => (string) $d['url'],
            'sha256'       => strtolower((string) ($d['sha256'] ?? '')),
            'size'         => (int) ($d['size'] ?? 0),
            'fetched_at'   => time(),
        ];
    }

    /**
     * Derive a {code, reason} pair from a failed response. Prefers the
     * worker's own `reason` field; otherwise maps the HTTP status to a
     * stable token the UI can switch on.
     *
     * @return array{code:int,reason:string}
     */
    private function errorFrom(int $code, ?string $body): array
    {
        $reason = '';
        if (is_string($body) && $body !== '') {
            $d = json_decode($body, true);
            if (is_array($d) && ! empty($d['reason']) && is_string($d['reason'])) {
                $reason = $d['reason'];
            }
        }
        if ($reason === '') {
            $reason = [
                0   => 'unreachable',
                401 => 'invalid_key',
                403 => 'not_entitled',
                404 => 'unknown_product',
                503 => 'not_configured',
            ][$code] ?? ($code === 200 ? 'bad_response' : 'error');
        }
        return ['code' => $code, 'reason' => $reason];
    }

    /** @return array{0:int,1:?string} [http_code, body] */
    private function post(string $url, string $jsonBody): array
    {
        if ($this->http !== null) {
            $r = ($this->http)($url, $jsonBody, self::HTTP_TIMEOUT);
            return [(int) ($r[0] ?? 0), $r[1] ?? null];
        }

        if (! function_exists('curl_init')) {
            return [0, null];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, is_string($body) ? $body : null];
    }

    private function cacheFile(string $baseUrl, string $product): string
    {
        $id = substr(hash('sha256', $baseUrl . '|' . $product), 0, 24);
        return $this->cacheDir . 'registry_' . $id . '.json';
    }

    private function ensureCacheDir(): void
    {
        if (! is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }
}
