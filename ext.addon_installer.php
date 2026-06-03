<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

use JavidFazaeli\AddonInstaller\Service\SettingsStore;

/**
 * Addon Manager + extension.
 *
 * Single hook today: cp_custom_menu — lets the admin pin Add-on Manager +
 * (and its pending-update count) into EE's per-role Custom sidebar
 * section. Behavior is gated by SettingsStore (see Settings screen):
 *
 *   - show_in_custom_menu     'y'|'n'  (default 'y')
 *   - custom_menu_target      'releases'|'packages'|'index'  (default 'releases')
 *   - custom_menu_show_count  'y'|'n'  (default 'y')
 *
 * The admin must STILL add Add-on Manager + to their role's Custom menu
 * via Settings → Menu Manager (/cp/settings/menu-manager/). EE designed
 * it that way so addons can't force-clutter every admin's nav. This
 * hook customises what's rendered once the admin has opted in.
 */
class Addon_installer_ext
{
    public $name        = 'Addon Manager +';
    public $version     = '1.3.3';
    public $description = 'Custom-menu integration for Addon Manager +';
    public $docs_url    = 'https://github.com/calimonk/ee-addon-manager';
    public $settings_exist = 'n'; // Settings live in our own Settings screen.
    public $settings    = [];

    public function __construct($settings = '')
    {
        $this->settings = is_array($settings) ? $settings : [];
    }

    /**
     * EE7 hook. Receives a $menu object with addItem($label, $url).
     * Called per-request when EE renders a Custom-menu entry whose
     * `data` field matches our short_name.
     */
    public function cp_custom_menu($menu)
    {
        if (defined('REQ') && REQ !== 'CP') {
            return true;
        }

        // Prefer the DI singleton; fall back to a direct instance if the
        // EE container isn't available for any reason (early bootstrap,
        // misconfiguration, etc). Either way the SettingsStore reads
        // from the same JSON file.
        try {
            $settings = ee('addon_installer:settingsStore');
            if (! ($settings instanceof SettingsStore)) {
                $settings = new SettingsStore();
            }
        } catch (\Throwable $e) {
            $settings = new SettingsStore();
        }

        if ($settings->get('show_in_custom_menu') !== 'y') {
            return true;
        }

        $target = $settings->get('custom_menu_target');
        $route = in_array($target, ['releases', 'packages', 'index'], true)
            ? $target
            : 'releases';

        $url = ee('CP/URL')->make('addons/settings/addon_installer/' . $route);

        $label = 'Add-on Manager +';
        if ($settings->get('custom_menu_show_count') === 'y') {
            try {
                $count = (int) ee('addon_installer:packageInstaller')->remoteUpdateCount();
                if ($count > 0) {
                    $label .= ' (' . $count . ')';
                }
            } catch (\Throwable $e) {
                // never crash the menu over a count lookup
            }
        }

        $menu->addItem($label, $url);
        return true;
    }

    /**
     * EE calls activate_extension() when the extension is enabled. We
     * also call this from upd.addon_installer.php::install() so the
     * hook is registered the moment the addon is installed.
     */
    public function activate_extension()
    {
        ee()->db->insert('extensions', [
            'class'    => __CLASS__,
            'method'   => 'cp_custom_menu',
            'hook'     => 'cp_custom_menu',
            'settings' => '',
            'priority' => 10,
            'version'  => $this->version,
            'enabled'  => 'y',
        ]);
    }

    public function disable_extension()
    {
        ee()->db->where('class', __CLASS__)->delete('extensions');
    }

    public function update_extension($current = '')
    {
        if ($current === $this->version) {
            return false;
        }

        ee()->db->where('class', __CLASS__)
            ->update('extensions', ['version' => $this->version]);
        return true;
    }
}
