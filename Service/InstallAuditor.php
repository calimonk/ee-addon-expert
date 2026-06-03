<?php

namespace JavidFazaeli\AddonInstaller\Service;

/**
 * Append-only audit log of every install / refresh / trust event.
 *
 * Each line is a self-contained JSON record so a single grep gives you
 * the full history without parsing a multi-line format. Lines look like:
 *
 *   {"ts":1717414800,"event":"install_ok","short_name":"edge_cache_tags",
 *    "repo":"calimonk/ee-edge-cache-tags","version":"2.4.22",
 *    "url":"https://.../zipball/v2.4.22","trust_state":"trusted",
 *    "owner_id":12345,"admin":"ivo"}
 *
 * Rotation: when the file exceeds MAX_BYTES, the oldest half is dropped
 * (cheap; no compress pipeline). For post-incident forensics 1MB of
 * JSONL is ~ten thousand events, more than enough.
 */
class InstallAuditor
{
    /** Soft cap; we rotate when we exceed this. */
    private const MAX_BYTES = 1024 * 1024;

    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?: self::defaultFile();
    }

    public static function defaultFile(): string
    {
        $base = defined('SYSPATH') ? SYSPATH . 'user/cache' : sys_get_temp_dir();
        return rtrim($base, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'addon_installer'
            . DIRECTORY_SEPARATOR . 'install.log';
    }

    /** Resolved path on disk where events are appended. */
    public function logPath(): string
    {
        return $this->file;
    }

    /**
     * Append an event. Failures are swallowed silently — audit logging
     * must NEVER block an install or surface as a CP error to the admin.
     * If logging is broken (disk full, perms), the install still runs.
     */
    public function record(array $event): void
    {
        $event['ts'] = time();

        if (function_exists('ee') && ! isset($event['admin'])) {
            try {
                $login = ee()->session->userdata('username');
                if (is_string($login) && $login !== '') {
                    $event['admin'] = $login;
                }
            } catch (\Throwable $e) {
                // ignore — best-effort attribution
            }
        }

        $line = json_encode($event, JSON_UNESCAPED_SLASHES) . "\n";

        $dir = dirname($this->file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Atomic-ish append with rotation. We don't lock; the worst case
        // is two simultaneous appends producing interleaved bytes — JSONL
        // is more robust to that than most formats, and we can always
        // tail the most recent N lines.
        @file_put_contents($this->file, $line, FILE_APPEND);
        $this->rotateIfNeeded();
    }

    /**
     * Return the last $limit events, newest first. Best-effort: returns
     * an empty array if the log is unreadable.
     *
     * @return array<int,array>
     */
    public function tail(int $limit = 50): array
    {
        if (! is_file($this->file)) {
            return [];
        }
        $body = @file_get_contents($this->file);
        if ($body === false || $body === '') {
            return [];
        }
        $lines = preg_split('#\r?\n#', rtrim($body, "\r\n")) ?: [];
        $out = [];
        for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
            $decoded = json_decode($lines[$i], true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }

    /** Best-effort log rotation. Keeps the most recent half. */
    private function rotateIfNeeded(): void
    {
        clearstatcache(true, $this->file);
        $size = @filesize($this->file);
        if ($size === false || $size < self::MAX_BYTES) {
            return;
        }
        $body = @file_get_contents($this->file);
        if ($body === false) {
            return;
        }
        // Keep the last MAX_BYTES/2 bytes, snapped forward to the first
        // newline so we don't tear a record.
        $keep = substr($body, intdiv(self::MAX_BYTES, 2));
        $cut = strpos($keep, "\n");
        if ($cut !== false) {
            $keep = substr($keep, $cut + 1);
        }
        @file_put_contents($this->file, $keep);
    }
}
