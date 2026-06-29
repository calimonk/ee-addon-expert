<?php

namespace Nivoli\AddonExpert\Service;

/**
 * Resolves the license key to present to a registry endpoint, keyed by the
 * endpoint *host*. One key per vendor: every `registry:` add-on that points
 * at the same host shares a key, so a site running several add-ons from one
 * vendor enters that vendor's key once.
 *
 * The key is the only secret the site holds — the vendor's worker holds the
 * GitHub token. So we keep it out of the codebase and let ops pin it without
 * touching the database:
 *
 *   Resolution order for a host (most specific + most authoritative first):
 *     1. config  addon_expert_registry_keys[host]   (per-host, ops-managed)
 *     2. file     keys[host]                          (per-host, UI-managed)
 *     3. config  addon_expert_registry_key           (default,  ops-managed)
 *     4. env      ADDON_EXPERT_REGISTRY_KEY           (default,  ops-managed)
 *     5. file     __default                           (default,  UI-managed)
 *
 * Host-specific always beats a default; within the same specificity, an
 * ops-set config/env value beats a UI-saved file value (so a deploy can pin
 * or rotate a key regardless of what's in the DB-side store). The file lives
 * in user/config alongside the other addon_expert_* stores so it survives
 * cache wipes.
 */
class RegistryKeyStore
{
    public const DEFAULT_HOST = '__default';

    private const ENV_VAR = 'ADDON_EXPERT_REGISTRY_KEY';

    private string $file;
    private ?array $values = null;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?: self::defaultFile();
    }

    public static function defaultFile(): string
    {
        $base = defined('SYSPATH') ? SYSPATH . 'user/config' : sys_get_temp_dir();
        return rtrim($base, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'addon_expert_registry_keys.json';
    }

    /** Normalize an endpoint URL (or bare host) to a lowercase host. */
    public static function hostOf(string $urlOrHost): string
    {
        $urlOrHost = trim($urlOrHost);
        if ($urlOrHost === '') {
            return '';
        }
        $host = strpos($urlOrHost, '://') !== false
            ? (string) parse_url($urlOrHost, PHP_URL_HOST)
            : $urlOrHost;
        return strtolower(trim($host));
    }

    /** Effective key for a registry endpoint URL, or '' if none configured. */
    public function keyForUrl(string $url): string
    {
        return $this->keyForHost(self::hostOf($url));
    }

    /** Effective key for a host, or '' if none configured. */
    public function keyForHost(string $host): string
    {
        [$key, ] = $this->resolve($host);
        return $key;
    }

    /**
     * Effective key + where it came from, for UI display.
     *
     * @return array{0:string,1:string} [key, source] where source ∈
     *   {config, env, file, none}
     */
    public function resolve(string $host): array
    {
        $host = strtolower(trim($host));

        $configKeys = $this->configKeys();
        if ($host !== '' && isset($configKeys[$host]) && trim((string) $configKeys[$host]) !== '') {
            return [trim((string) $configKeys[$host]), 'config'];
        }

        $file = $this->load();
        if ($host !== '' && isset($file[$host]) && trim((string) $file[$host]) !== '') {
            return [trim((string) $file[$host]), 'file'];
        }

        $configDefault = $this->configDefault();
        if ($configDefault !== '') {
            return [$configDefault, 'config'];
        }

        $env = trim((string) getenv(self::ENV_VAR));
        if ($env !== '') {
            return [$env, 'env'];
        }

        if (isset($file[self::DEFAULT_HOST]) && trim((string) $file[self::DEFAULT_HOST]) !== '') {
            return [trim((string) $file[self::DEFAULT_HOST]), 'file'];
        }

        return ['', 'none'];
    }

    public function hasKeyFor(string $host): bool
    {
        return $this->keyForHost($host) !== '';
    }

    /** File-stored keys only (host => key), for editing in the UI. */
    public function stored(): array
    {
        return $this->load();
    }

    /**
     * Persist a key for a host (or the default sentinel). An empty value
     * deletes the stored entry. Config/env-provided keys are unaffected —
     * this only writes the file layer.
     */
    public function save(string $host, string $key): bool
    {
        $host = $host === self::DEFAULT_HOST ? $host : self::hostOf($host);
        if ($host === '') {
            return false;
        }
        $values = $this->load();
        $key = trim($key);
        if ($key === '') {
            unset($values[$host]);
        } else {
            $values[$host] = $key;
        }
        return $this->persist($values);
    }

    public function setDefault(string $key): bool
    {
        return $this->save(self::DEFAULT_HOST, $key);
    }

    public function forget(string $host): bool
    {
        return $this->save($host === self::DEFAULT_HOST ? $host : self::hostOf($host), '');
    }

    /**
     * Bulk replace the file layer from a UI submission. Keys are host strings
     * (or the default sentinel); empty values drop the entry.
     */
    public function saveAll(array $incoming): bool
    {
        $clean = [];
        foreach ($incoming as $host => $key) {
            $host = (string) $host === self::DEFAULT_HOST ? self::DEFAULT_HOST : self::hostOf((string) $host);
            $key = trim((string) $key);
            if ($host === '' || $key === '') {
                continue;
            }
            $clean[$host] = $key;
        }
        $this->values = $clean;
        return $this->persist($clean);
    }

    private function persist(array $values): bool
    {
        $this->values = $values;
        $dir = dirname($this->file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return false !== @file_put_contents(
            $this->file,
            json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function load(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }
        if (! is_file($this->file)) {
            return $this->values = [];
        }
        $body = @file_get_contents($this->file);
        if ($body === false || $body === '') {
            return $this->values = [];
        }
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return $this->values = [];
        }
        $clean = [];
        foreach ($decoded as $host => $key) {
            if (is_string($host) && is_string($key) && trim($key) !== '') {
                $clean[strtolower($host) === self::DEFAULT_HOST ? self::DEFAULT_HOST : strtolower($host)] = trim($key);
            }
        }
        return $this->values = $clean;
    }

    private function configKeys(): array
    {
        if (! function_exists('ee')) {
            return [];
        }
        try {
            $k = ee()->config->item('addon_expert_registry_keys');
        } catch (\Throwable $e) {
            return [];
        }
        if (! is_array($k)) {
            return [];
        }
        $out = [];
        foreach ($k as $host => $key) {
            if (is_string($host) && is_scalar($key)) {
                $out[strtolower(trim($host))] = (string) $key;
            }
        }
        return $out;
    }

    private function configDefault(): string
    {
        if (! function_exists('ee')) {
            return '';
        }
        try {
            $v = ee()->config->item('addon_expert_registry_key');
        } catch (\Throwable $e) {
            return '';
        }
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
