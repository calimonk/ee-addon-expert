<?php

namespace Nivoli\AddonExpert\Service;

use RuntimeException;

/**
 * Finishes the EE-side half of an addon update.
 *
 * Our one-click installer swaps files on disk. EE detects the on-disk
 * version is newer than `exp_modules.module_version` and shows the
 * "Update Available" prompt. That prompt fires `upd::update()` + bumps
 * the DB version row. AutoFinalizer does exactly that programmatically.
 *
 * The flow we replicate is what EE's own
 * `ExpressionEngine\Controller\Addons\Addons::update()` does:
 *
 *   1. `ee('Addon')->get($shortName)` → addon info
 *   2. `ee()->addons->get_installed('modules', true)` → installed
 *       module rows (path, current version, module_id)
 *   3. `new {InstallerClass}()->update($currentVersion)` — runs the
 *       addon's own upd.php migration code
 *   4. Bump `exp_modules.module_version` to the on-disk version
 *   5. Same for extension class + `exp_extensions.version` (if any)
 *
 * Self-update note: when finalizing addon_expert itself, this is
 * called in a NEW request (the one after the install-time 302), so
 * PHP loads the NEW upd class fresh — we run the new code, not the
 * old in-memory version. That's exactly why the install writes a
 * marker file instead of attempting finalize in the install request.
 */
class AutoFinalizer
{
    private const PENDING_DIR = 'pending_finalize';

    /** After this many consecutive failures, give up on a marker. */
    private const MAX_ATTEMPTS = 3;

    private InstallAuditor $auditor;
    private string $cacheRoot;

    public function __construct(?InstallAuditor $auditor = null, ?string $cacheRoot = null)
    {
        $this->auditor = $auditor ?: new InstallAuditor();
        $this->cacheRoot = rtrim(
            $cacheRoot ?: self::defaultCacheRoot(),
            DIRECTORY_SEPARATOR
        );
    }

    public static function defaultCacheRoot(): string
    {
        $base = defined('SYSPATH') ? SYSPATH . 'user/cache' : sys_get_temp_dir();
        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'addon_expert';
    }

    private function pendingDir(): string
    {
        return $this->cacheRoot . DIRECTORY_SEPARATOR . self::PENDING_DIR;
    }

    private function markerFile(string $shortName): string
    {
        $safe = preg_replace('#[^a-z0-9_]#', '_', strtolower($shortName));
        return $this->pendingDir() . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    /**
     * Schedule a finalize for $shortName. Idempotent — overwrites any
     * existing marker (the most recent install is authoritative).
     */
    public function schedule(string $shortName, string $fromVersion, string $toVersion): void
    {
        $dir = $this->pendingDir();
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents(
            $this->markerFile($shortName),
            json_encode([
                'short_name'   => $shortName,
                'from_version' => $fromVersion,
                'to_version'   => $toVersion,
                'scheduled_at' => time(),
                'attempts'     => 0,
            ], JSON_UNESCAPED_SLASHES)
        );
    }

    /** Forget a pending finalize. Used on explicit success. */
    public function clear(string $shortName): void
    {
        $file = $this->markerFile($shortName);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** All currently-pending finalizes. */
    public function pending(): array
    {
        $out = [];
        foreach (glob($this->pendingDir() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $marker) {
            $body = @file_get_contents($marker);
            $data = $body !== false ? json_decode($body, true) : null;
            if (is_array($data) && ! empty($data['short_name'])) {
                $out[$data['short_name']] = $data;
            }
        }
        return $out;
    }

    /**
     * Run finalize for every marker. Returns per-addon results so the
     * caller can surface a banner. Failures don't stop the loop — each
     * addon's finalize is independent.
     *
     * @return array{
     *   finalized: array<string,array>,
     *   failed: array<string,array>,
     *   skipped: array<string,string>
     * }
     */
    public function finalizeAllPending(): array
    {
        $result = ['finalized' => [], 'failed' => [], 'skipped' => []];

        foreach ($this->pending() as $shortName => $marker) {
            try {
                $outcome = $this->finalizeOne($shortName);
                if ($outcome['updated']) {
                    $this->clear($shortName);
                    $this->auditor->record([
                        'event'      => 'auto_finalized',
                        'short_name' => $shortName,
                        'from'       => $outcome['from'] ?? ($marker['from_version'] ?? null),
                        'to'         => $outcome['to'] ?? ($marker['to_version'] ?? null),
                        'parts'      => $outcome['parts'] ?? [],
                        'is_self'    => $shortName === 'addon_expert',
                    ]);
                    $result['finalized'][$shortName] = $outcome;
                } else {
                    $this->clear($shortName);
                    $reason = (string) ($outcome['reason'] ?? 'unknown');
                    $this->auditor->record([
                        'event'      => 'auto_finalize_skipped',
                        'short_name' => $shortName,
                        'reason'     => $reason,
                    ]);
                    $result['skipped'][$shortName] = $reason;
                }
            } catch (\Throwable $e) {
                $attempts = (int) ($marker['attempts'] ?? 0) + 1;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->clear($shortName);
                    $this->auditor->record([
                        'event'      => 'auto_finalize_abandoned',
                        'short_name' => $shortName,
                        'attempts'   => $attempts,
                        'error'      => $e->getMessage(),
                    ]);
                } else {
                    $marker['attempts'] = $attempts;
                    @file_put_contents($this->markerFile($shortName), json_encode($marker));
                    $this->auditor->record([
                        'event'      => 'auto_finalize_failed',
                        'short_name' => $shortName,
                        'attempts'   => $attempts,
                        'error'      => $e->getMessage(),
                    ]);
                }
                $result['failed'][$shortName] = [
                    'attempts' => $attempts,
                    'error'    => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * Run the EE-side update for one addon. Implementation mirrors
     * `Controller\Addons\Addons::update()` from EE 7.dev — module
     * version bump + extension version bump.
     *
     * @return array{updated:bool, reason?:string, from?:string, to?:string, parts?:array}
     */
    public function finalizeOne(string $shortName): array
    {
        if (! function_exists('ee')) {
            throw new RuntimeException('EE not loaded');
        }

        $addonInfo = ee('Addon')->get($shortName);
        if (! $addonInfo) {
            return ['updated' => false, 'reason' => 'addon_not_registered'];
        }

        if (! $addonInfo->hasUpdate()) {
            // Disk version already matches DB. Nothing to do; treat as
            // success so the marker gets cleared.
            return ['updated' => false, 'reason' => 'no_update_needed'];
        }

        $newVersion = (string) $addonInfo->getVersion();
        $parts = [];

        // === MODULE ===
        $installedModules = ee()->addons->get_installed('modules', true);
        if (isset($installedModules[$shortName])) {
            $modRow = $installedModules[$shortName];
            $oldVersion = (string) $modRow['module_version'];

            $class = $addonInfo->getInstallerClass();
            // Make sure the package path is on the autoloader so the
            // upd file is loadable. EE's own update flow does this.
            ee()->load->add_package_path($modRow['path']);
            if (! class_exists($class)) {
                // Last-resort load by convention.
                $updFile = rtrim($modRow['path'], DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR . 'upd.' . $shortName . '.php';
                if (is_file($updFile)) {
                    require_once $updFile;
                }
            }
            if (! class_exists($class)) {
                throw new RuntimeException('Installer class not found: ' . $class);
            }

            $UPD = new $class();
            $updateReturn = $UPD->update($oldVersion);

            // EE: if update() returns false, it has explicitly declined.
            // Anything else (null/true) is success.
            if ($updateReturn !== false && version_compare($oldVersion, $newVersion, '<')) {
                $module = ee('Model')->get('Module', $modRow['module_id'])->first();
                if ($module) {
                    $module->module_version = $newVersion;
                    $module->save();
                    $parts['module'] = ['from' => $oldVersion, 'to' => $newVersion];
                }
            }
        }

        // === EXTENSION ===
        $extClass = (string) $addonInfo->getExtensionClass();
        if ($extClass !== '' && class_exists($extClass)) {
            $extensions = ee('Model')->get('Extension')
                ->filter('class', $extClass)
                ->all();

            if (count($extensions) > 0) {
                $extInstance = new $extClass();
                $oldExtVersion = (string) $extensions->first()->version;

                if (method_exists($extInstance, 'update_extension')) {
                    $extInstance->update_extension($oldExtVersion);
                }

                foreach ($extensions as $extension) {
                    $extension->version = $newVersion;
                    $extension->save();
                }
                $parts['extension'] = ['from' => $oldExtVersion, 'to' => $newVersion];
            }
        }

        if (empty($parts)) {
            return ['updated' => false, 'reason' => 'nothing_to_update'];
        }

        // `from` reports the PRE-finalize installed version. Reading
        // $addonInfo->getInstalledVersion() here would now return the
        // POST-save value — we'd render "1.4.1 → 1.4.1" in the banner.
        // Use the captured `from` value from the parts we actually
        // mutated; module takes precedence over extension since the
        // module is the user-facing version row in EE's UI.
        $from = (string) ($parts['module']['from']
            ?? $parts['extension']['from']
            ?? '');

        return [
            'updated' => true,
            'from'    => $from,
            'to'      => $newVersion,
            'parts'   => $parts,
        ];
    }
}
