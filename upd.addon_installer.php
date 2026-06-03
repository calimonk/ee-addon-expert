<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

use ExpressionEngine\Service\Addon\Installer;

class Addon_installer_upd extends Installer
{
    public $has_cp_backend = 'y';
    public $has_publish_fields = 'n';

    public function install()
    {
        parent::install();
        $this->ensureExtensionRegistered();
        return true;
    }

    public function update($current = '')
    {
        parent::update($current);

        // Make sure the cp_custom_menu hook is registered when upgrading
        // from any pre-1.3.0 install. install() doesn't run on update,
        // so this is where we backfill the extension row.
        $this->ensureExtensionRegistered();
        return true;
    }

    public function uninstall()
    {
        if (function_exists('ee') && isset(ee()->db)) {
            ee()->db->where('class', 'Addon_installer_ext')->delete('extensions');
        }
        parent::uninstall();
        return true;
    }

    /**
     * Ensure the cp_custom_menu hook row exists. Idempotent — safe to
     * call from both install() and update(). We don't touch user-edited
     * settings (the row's `settings` column is left alone if present).
     */
    private function ensureExtensionRegistered(): void
    {
        if (! function_exists('ee') || ! isset(ee()->db)) {
            return;
        }

        $existing = ee()->db
            ->where('class', 'Addon_installer_ext')
            ->where('method', 'cp_custom_menu')
            ->count_all_results('extensions');

        if ($existing > 0) {
            ee()->db
                ->where('class', 'Addon_installer_ext')
                ->update('extensions', ['version' => '1.3.5']);
            return;
        }

        ee()->db->insert('extensions', [
            'class'    => 'Addon_installer_ext',
            'method'   => 'cp_custom_menu',
            'hook'     => 'cp_custom_menu',
            'settings' => '',
            'priority' => 10,
            'version'  => '1.3.5',
            'enabled'  => 'y',
        ]);
    }
}
