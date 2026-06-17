<?php

namespace Codebit\AddonExpert\Service;

/**
 * Persistent TOFU (Trust On First Use) anchor for GitHub repo mappings.
 *
 * For each `owner/repo` we install from, we pin three GitHub-controlled
 * stable identifiers on first observation:
 *
 *   - `owner_id`   — GitHub's numeric user/org ID. Stable across username
 *                    renames, so a username change does NOT trigger a
 *                    false alarm — but a real ownership *transfer*
 *                    does (the numeric ID changes).
 *   - `repo_id`    — Numeric repo ID. Stable across renames; changes
 *                    when a repo is deleted and re-created (RepoJacking).
 *   - `created_at` — Repo creation timestamp. Belt-and-braces: also
 *                    changes on delete+recreate.
 *
 * Lives in `system/user/config/` so it survives cache wipes. If this
 * file is deleted, every mapping reverts to "unverified" and the next
 * successful refresh re-pins (which means a deletion at the WRONG
 * moment effectively erases trust history — so don't `rm` the trust
 * store as a "fix" if you see a warning).
 */
class TrustStore
{
    public const STATE_UNVERIFIED = 'unverified';
    public const STATE_TRUSTED    = 'trusted';
    public const STATE_CHANGED    = 'changed';

    private string $file;

    /** Lazy-loaded map: [owner/repo => fingerprint]. */
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
            . 'addon_expert_trust.json';
    }

    /**
     * Return the pinned fingerprint for $ownerRepo, or null if no anchor
     * has been established yet.
     *
     * @return array{
     *   owner_id:int, owner_login:string, repo_id:int, created_at:string,
     *   full_name:string, first_seen_at:int, pinned_by:?string
     * }|null
     */
    public function fingerprint(string $ownerRepo): ?array
    {
        $store = $this->load();
        return $store[$ownerRepo] ?? null;
    }

    /**
     * Pin (or re-pin) the fingerprint for $ownerRepo. `pinned_by` is the
     * EE member login of the admin who triggered the pin — written to
     * the audit log too so post-incident review is trivial.
     */
    public function pin(string $ownerRepo, array $identity, ?string $pinnedBy = null): void
    {
        $store = $this->load();

        $store[$ownerRepo] = [
            'owner_id'      => (int) ($identity['owner_id'] ?? 0),
            'owner_login'   => (string) ($identity['owner_login'] ?? ''),
            'repo_id'       => (int) ($identity['repo_id'] ?? 0),
            'created_at'    => (string) ($identity['created_at'] ?? ''),
            'full_name'     => (string) ($identity['full_name'] ?? $ownerRepo),
            'first_seen_at' => isset($store[$ownerRepo]['first_seen_at'])
                ? (int) $store[$ownerRepo]['first_seen_at']
                : time(),
            'last_pinned_at' => time(),
            'pinned_by'      => $pinnedBy,
        ];

        $this->store = $store;
        $this->persist();
    }

    /** Forget the fingerprint for $ownerRepo. */
    public function unpin(string $ownerRepo): void
    {
        $store = $this->load();
        if (! array_key_exists($ownerRepo, $store)) {
            return;
        }
        unset($store[$ownerRepo]);
        $this->store = $store;
        $this->persist();
    }

    /**
     * Compare an observed identity to the pinned anchor. Returns one of
     * the STATE_* constants. The diff is also returned so callers can
     * surface a precise human explanation.
     *
     * @return array{state:string, diff:array<string,array{pinned:mixed,observed:mixed}>, pinned:?array, observed:?array}
     */
    public function compare(string $ownerRepo, ?array $observed): array
    {
        $pinned = $this->fingerprint($ownerRepo);

        if ($observed === null) {
            return [
                'state' => $pinned !== null ? self::STATE_TRUSTED : self::STATE_UNVERIFIED,
                'diff' => [],
                'pinned' => $pinned,
                'observed' => null,
            ];
        }

        if ($pinned === null) {
            return [
                'state' => self::STATE_UNVERIFIED,
                'diff' => [],
                'pinned' => null,
                'observed' => $observed,
            ];
        }

        $diff = [];
        foreach (['owner_id', 'repo_id', 'created_at'] as $field) {
            $a = $pinned[$field] ?? null;
            $b = $observed[$field] ?? null;
            if ($a !== null && $b !== null && (string) $a !== (string) $b) {
                $diff[$field] = ['pinned' => $a, 'observed' => $b];
            }
        }

        return [
            'state' => empty($diff) ? self::STATE_TRUSTED : self::STATE_CHANGED,
            'diff' => $diff,
            'pinned' => $pinned,
            'observed' => $observed,
        ];
    }

    /** All pinned fingerprints, read-only. */
    public function all(): array
    {
        return $this->load();
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
