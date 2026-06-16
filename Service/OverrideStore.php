<?php

namespace JavidFazaeli\AddonInstaller\Service;

/**
 * Registry of add-ons whose declared version requirements have been
 * deliberately overridden by an admin ("force install despite the
 * add-on saying it needs PHP/EE X").
 *
 * This is a deliberate footgun with a paper trail. EE enforces the
 * add-on's `requires` block in its install controller by reading the
 * add-on's own addon.setup.php — so the ONLY way to get an
 * incompatible add-on past EE's native gate (short of patching EE
 * core) is to rewrite the declared requirement in the extracted
 * addon.setup.php. When we do that, we record it here so:
 *
 *   - the Packages / Releases UI can show a persistent "requirement
 *     override" badge (you never silently forget you lied to EE),
 *   - the original declared requirement is preserved for reference,
 *   - the GitHub update flow can re-apply the patch on the next
 *     release (otherwise every update re-breaks the install),
 *   - there's an attributable audit trail of who forced what.
 *
 * Persisted to system/user/config so it survives cache wipes.
 */
class OverrideStore
{
    private string $file;
    private ?array $store = null;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?: self::defaultFile();
    }

    public static function defaultFile(): string
    {
        $base = defined('SYSPATH') ? SYSPATH . 'user/config' : sys_get_temp_dir();
        return rtrim($base, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'addon_installer_overrides.json';
    }

    /**
     * @return array{
     *   original_requires:array, patched_to:array, overridden_at:int,
     *   overridden_by:?string, reason:?string
     * }|null
     */
    public function get(string $shortName): ?array
    {
        $store = $this->load();
        return $store[$shortName] ?? null;
    }

    public function has(string $shortName): bool
    {
        return $this->get($shortName) !== null;
    }

    /** All overrides, read-only. */
    public function all(): array
    {
        return $this->load();
    }

    public function record(
        string $shortName,
        array $originalRequires,
        array $patchedTo,
        ?string $by = null,
        ?string $reason = null,
        ?string $scan = null
    ): void {
        $store = $this->load();

        // Preserve the FIRST-seen original requirement across re-applies.
        // A later update re-patches but must not overwrite the genuine
        // original with an already-patched value.
        $original = isset($store[$shortName]['original_requires'])
            ? $store[$shortName]['original_requires']
            : $originalRequires;

        $store[$shortName] = [
            'original_requires' => $original,
            'patched_to'        => $patchedTo,
            'overridden_at'     => isset($store[$shortName]['overridden_at'])
                ? (int) $store[$shortName]['overridden_at']
                : time(),
            'last_applied_at'   => time(),
            'overridden_by'     => $by ?? ($store[$shortName]['overridden_by'] ?? null),
            'reason'            => $reason ?? ($store[$shortName]['reason'] ?? null),
            'scan'              => $scan ?? ($store[$shortName]['scan'] ?? null),
        ];

        $this->store = $store;
        $this->persist();
    }

    public function forget(string $shortName): void
    {
        $store = $this->load();
        if (! array_key_exists($shortName, $store)) {
            return;
        }
        unset($store[$shortName]);
        $this->store = $store;
        $this->persist();
    }

    private function load(): array
    {
        if ($this->store !== null) {
            return $this->store;
        }
        if (! is_file($this->file)) {
            return $this->store = [];
        }
        $body = @file_get_contents($this->file);
        if ($body === false || $body === '') {
            return $this->store = [];
        }
        $decoded = json_decode($body, true);
        return $this->store = is_array($decoded) ? $decoded : [];
    }

    private function persist(): void
    {
        $dir = dirname($this->file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $this->file,
            json_encode($this->store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
