<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

use ExpressionEngine\Service\Addon\Installer;

class Addon_expert_upd extends Installer
{
    public $has_cp_backend = 'y';
    public $has_publish_fields = 'n';

    public function install()
    {
        parent::install();
        $this->migrateLegacyConfig();
        $this->ensureExtensionRegistered();
        return true;
    }

    public function update($current = '')
    {
        parent::update($current);
        $this->migrateLegacyConfig();
        $this->ensureExtensionRegistered();
        return true;
    }

    public function uninstall()
    {
        if (function_exists('ee') && isset(ee()->db)) {
            ee()->db->where('class', 'Addon_expert_ext')->delete('extensions');
        }
        parent::uninstall();
        return true;
    }

    /**
     * Carry config over from the pre-rename add-on (short name
     * `addon_installer`, "Addon Manager +") so an in-place upgrade to
     * Addon Expert keeps the site's GitHub mappings, TOFU trust anchors,
     * settings, requirement overrides, and release cache.
     *
     * COPY, not move — the old `addon_installer` add-on may still be
     * installed at this point (EE treats the new short name as a
     * separate add-on). Leaving the originals intact means the old one
     * keeps working until the admin uninstalls it, and a rollback loses
     * nothing. We never clobber: a file is only copied if the
     * destination doesn't already exist.
     *
     * Idempotent and best-effort: any failure here must not abort the
     * install.
     */
    private function migrateLegacyConfig(): void
    {
        if (! defined('SYSPATH')) {
            return;
        }

        $configDir = rtrim(SYSPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'user' . DIRECTORY_SEPARATOR . 'config';
        $cacheDir  = rtrim(SYSPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'user' . DIRECTORY_SEPARATOR . 'cache';

        // JSON config files: addon_installer_X.json -> addon_expert_X.json
        foreach (['mappings', 'trust', 'settings', 'overrides'] as $name) {
            $old = $configDir . DIRECTORY_SEPARATOR . 'addon_installer_' . $name . '.json';
            $new = $configDir . DIRECTORY_SEPARATOR . 'addon_expert_' . $name . '.json';
            if (is_file($old) && ! is_file($new)) {
                @copy($old, $new);
            }
        }

        // Cache tree: user/cache/addon_installer -> user/cache/addon_expert
        // (release_*.json, repo_*.json, install.log, backups/, pending_finalize/)
        $oldCache = $cacheDir . DIRECTORY_SEPARATOR . 'addon_installer';
        $newCache = $cacheDir . DIRECTORY_SEPARATOR . 'addon_expert';
        if (is_dir($oldCache) && ! is_dir($newCache)) {
            $this->copyTree($oldCache, $newCache);
        }
    }

    private function copyTree(string $from, string $to): void
    {
        if (! is_dir($to) && ! @mkdir($to, 0775, true) && ! is_dir($to)) {
            return;
        }
        $entries = @scandir($from);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $src = $from . DIRECTORY_SEPARATOR . $entry;
            $dst = $to . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($src) && ! is_link($src)) {
                $this->copyTree($src, $dst);
            } else {
                @copy($src, $dst);
            }
        }
    }

    /**
     * Ensure the cp_custom_menu hook row exists for this add-on.
     * Idempotent — safe from both install() and update().
     */
    private function ensureExtensionRegistered(): void
    {
        if (! function_exists('ee') || ! isset(ee()->db)) {
            return;
        }

        $existing = ee()->db
            ->where('class', 'Addon_expert_ext')
            ->where('method', 'cp_custom_menu')
            ->count_all_results('extensions');

        if ($existing > 0) {
            ee()->db
                ->where('class', 'Addon_expert_ext')
                ->update('extensions', ['version' => '2.1.1']);
            return;
        }

        ee()->db->insert('extensions', [
            'class'    => 'Addon_expert_ext',
            'method'   => 'cp_custom_menu',
            'hook'     => 'cp_custom_menu',
            'settings' => '',
            'priority' => 10,
            'version'  => '2.1.1',
            'enabled'  => 'y',
        ]);
    }
}
