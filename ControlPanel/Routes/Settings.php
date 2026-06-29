<?php

namespace Nivoli\AddonExpert\ControlPanel\Routes;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;
use Nivoli\AddonExpert\Service\RegistryKeyStore;

class Settings extends AbstractRoute
{
    use LoadsStyle;

    /** @var string */
    protected $route_path = 'settings';

    /** @var string */
    protected $cp_page_title = 'Settings';

    /**
     * @param false $id
     * @return AbstractRoute
     */
    public function process($id = false)
    {
        $this->addBreadcrumb('index', 'Addon Expert');
        $this->addBreadcrumb('settings', 'Settings');
        $this->loadStyle();

        $store = ee('addon_expert:settingsStore');
        $keys = ee('addon_expert:registryKeyStore');
        $selfUrl = ee('CP/URL')->make('addons/settings/addon_expert/settings');

        // POST: save registry license keys (separate form from the
        // behaviour toggles so the two save independently).
        if (ee('Request')->isPost() && ee()->input->post('save_registry_keys')) {
            $incoming = [];
            $default = trim((string) ee()->input->post('registry_key_default'));
            if ($default !== '') {
                $incoming[RegistryKeyStore::DEFAULT_HOST] = $default;
            }
            foreach ((array) ee()->input->post('registry_key') as $host => $key) {
                $host = (string) $host;
                $key = trim((string) $key);
                if ($host !== '' && $key !== '') {
                    $incoming[$host] = $key;
                }
            }

            if ($keys->saveAll($incoming)) {
                ee('CP/Alert')->makeBanner('addon-installer-registry-keys')
                    ->asSuccess()
                    ->withTitle('License keys saved')
                    ->addToBody('Registry license keys updated. Use "Check for updates" on the Releases screen to refresh.')
                    ->defer();
            } else {
                ee('CP/Alert')->makeBanner('addon-installer-registry-keys')
                    ->asIssue()
                    ->withTitle('License keys could not be saved')
                    ->addToBody('Check that system/user/config is writable.')
                    ->defer();
            }
            ee()->functions->redirect($selfUrl);
        }

        if (ee('Request')->isPost() && ee()->input->post('save_settings')) {
            $incoming = [
                'show_in_custom_menu'    => ee()->input->post('show_in_custom_menu') === 'y' ? 'y' : 'n',
                'custom_menu_target'     => (string) ee()->input->post('custom_menu_target'),
                'custom_menu_show_count' => ee()->input->post('custom_menu_show_count') === 'y' ? 'y' : 'n',
                'custom_menu_label'      => (string) ee()->input->post('custom_menu_label'),
                'auto_finalize'          => ee()->input->post('auto_finalize') === 'y' ? 'y' : 'n',
                'lazy_refresh'           => ee()->input->post('lazy_refresh') === 'y' ? 'y' : 'n',
            ];

            if ($store->saveAll($incoming)) {
                ee('CP/Alert')->makeBanner('addon-installer-settings')
                    ->asSuccess()
                    ->withTitle('Settings saved')
                    ->addToBody('Custom-menu behavior updated.')
                    ->defer();
            } else {
                ee('CP/Alert')->makeBanner('addon-installer-settings')
                    ->asIssue()
                    ->withTitle('Settings could not be saved')
                    ->addToBody('Check that system/user/config is writable.')
                    ->defer();
            }
            ee()->functions->redirect($selfUrl);
        }

        $values = $store->all();
        $installer = ee('addon_expert:packageInstaller');

        // Discover the registry vendor hosts in play from installed add-ons
        // that declare a `registry:` source, so we can show a key field per
        // host (one key per vendor).
        $stored = $keys->stored();
        $registryHosts = [];
        foreach ($installer->installedPackages() as $pkg) {
            if (($pkg['remote_kind'] ?? null) !== 'registry') {
                continue;
            }
            $url = (string) ($pkg['remote_registry_url'] ?? '');
            $host = RegistryKeyStore::hostOf($url);
            if ($host === '') {
                continue;
            }
            if (! isset($registryHosts[$host])) {
                [$eff, $source] = $keys->resolve($host);
                $registryHosts[$host] = [
                    'host'       => $host,
                    'stored_key' => (string) ($stored[$host] ?? ''),
                    'source'     => $source,            // config|env|file|none
                    'locked'     => in_array($source, ['config', 'env'], true),
                    'has_key'    => $eff !== '',
                    'addons'     => [],
                ];
            }
            $registryHosts[$host]['addons'][] = (string) ($pkg['name'] ?? $pkg['short_name']);
        }

        // Effective default-key state (resolve via a host that can't match
        // a per-host entry, so we see only the default chain).
        [$defEff, $defSource] = $keys->resolve('\\__no_such_host__');

        $this->setBody('Settings', [
            'values'             => $values,
            'save_url'           => $selfUrl->compile(),
            'csrf_token'         => $installer->csrfToken(),
            'manager_url'        => ee('CP/URL')->make('addons')->compile(),
            'docs_url'           => ee('CP/URL')->make('addons/settings/addon_expert/documentation')->compile(),
            'registry_hosts'     => array_values($registryHosts),
            'registry_default'   => (string) ($stored[RegistryKeyStore::DEFAULT_HOST] ?? ''),
            'registry_default_source' => $defSource,
            'registry_default_locked' => in_array($defSource, ['config', 'env'], true),
        ]);

        return $this;
    }
}
