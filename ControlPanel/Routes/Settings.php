<?php

namespace JavidFazaeli\AddonInstaller\ControlPanel\Routes;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

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
        $this->addBreadcrumb('index', 'Addon Manager +');
        $this->addBreadcrumb('settings', 'Settings');
        $this->loadStyle();

        $store = ee('addon_installer:settingsStore');
        $selfUrl = ee('CP/URL')->make('addons/settings/addon_installer/settings');

        if (ee('Request')->isPost() && ee()->input->post('save_settings')) {
            $incoming = [
                'show_in_custom_menu'    => ee()->input->post('show_in_custom_menu') === 'y' ? 'y' : 'n',
                'custom_menu_target'     => (string) ee()->input->post('custom_menu_target'),
                'custom_menu_show_count' => ee()->input->post('custom_menu_show_count') === 'y' ? 'y' : 'n',
                'custom_menu_label'      => (string) ee()->input->post('custom_menu_label'),
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
        $installer = ee('addon_installer:packageInstaller');

        $this->setBody('Settings', [
            'values'      => $values,
            'save_url'    => $selfUrl->compile(),
            'csrf_token'  => $installer->csrfToken(),
            'manager_url' => ee('CP/URL')->make('addons')->compile(),
            'docs_url'    => ee('CP/URL')->make('addons/settings/addon_installer/documentation')->compile(),
        ]);

        return $this;
    }
}
