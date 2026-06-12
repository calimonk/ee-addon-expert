<?php

namespace JavidFazaeli\AddonInstaller\ControlPanel\Routes;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

class Index extends AbstractRoute
{
    use LoadsStyle;

    /**
     * @var string
     */
    protected $route_path = 'index';

    /**
     * @var string
     */
    protected $cp_page_title = 'Addon Manager +';

    /**
     * @param false $id
     * @return AbstractRoute
     */
    public function process($id = false)
    {
        $this->addBreadcrumb('index', 'Addon Manager +');
        $this->loadStyle();

        $installer = ee('addon_installer:packageInstaller');
        $result = null;

        if (ee('Request')->isPost() && ee()->input->post('install_package')) {
            try {
                $result = $installer->installUploaded(
                    $_FILES['addon_package'] ?? [],
                    (bool) ee()->input->post('overwrite_existing')
                );

                ee('CP/Alert')->makeBanner('addon-installer-upload')
                    ->asSuccess()
                    ->withTitle('Package uploaded')
                    ->addToBody($result['name'] . ' was extracted to the ExpressionEngine add-ons folder.')
                    ->defer();

                ee()->functions->redirect(ee('CP/URL')->make('addons/settings/addon_installer/index', [
                    'installed' => $result['short_name'],
                ]));
            } catch (\Throwable $e) {
                ee('CP/Alert')->makeBanner('addon-installer-upload')
                    ->asIssue()
                    ->withTitle('Package was not installed')
                    ->addToBody($e->getMessage())
                    ->defer();

                ee()->functions->redirect(ee('CP/URL')->make('addons/settings/addon_installer/index'));
            }
        }

        // Run any pending auto-finalize markers. After a successful
        // upload, the user lands back on this screen via redirect —
        // exactly the right time to fire EE's update flow for the
        // freshly-extracted add-on. Without this, the admin had to
        // wait minutes for EE to scan and surface the Update prompt
        // on Developer → Add-Ons.
        $finalizeResults = null;
        try {
            $settings = ee('addon_installer:settingsStore');
            if ($settings->get('auto_finalize') === 'y') {
                $finalizer = ee('addon_installer:autoFinalizer');
                if (! empty($finalizer->pending())) {
                    $finalizeResults = $finalizer->finalizeAllPending();
                }
            }
        } catch (\Throwable $e) {
            // Never let finalize errors block the screen render.
        }

        $installedShortName = (string) ee()->input->get('installed', true);
        $installedAddon = $installedShortName !== '' ? ee('Addon')->get($installedShortName) : null;
        $isInstalled = $installedAddon ? (bool) $installedAddon->isInstalled() : false;
        $updateAvailable = $installedAddon ? (bool) $installedAddon->hasUpdate() : false;
        $settingsAvailable = $isInstalled && $installedAddon && (bool) $installedAddon->get('settings_exist');
        $packagesUrl = ee('CP/URL')->make('addons/settings/addon_installer/packages');

        $this->setBody('Index', [
            'status' => $installer->status(),
            'installed_short_name' => $installedShortName,
            'installed_is_installed' => $isInstalled,
            'update_available' => $updateAvailable,
            'finalize_results' => $finalizeResults,
            'manager_url' => ee('CP/URL')->make('addons')->compile(),
            'install_url' => $installedShortName !== '' && ! $isInstalled
                ? ee('CP/URL')->make('addons/install/' . $installedShortName, [
                    'return' => $packagesUrl->encode(),
                ])->compile()
                : '',
            'update_url' => $updateAvailable
                ? ee('CP/URL')->make('addons/update/' . $installedShortName, [
                    'return' => $packagesUrl->encode(),
                ])->compile()
                : '',
            'remove_url' => $isInstalled
                ? ee('CP/URL')->make('addons/remove/' . $installedShortName, [
                    'return' => $packagesUrl->encode(),
                ])->compile()
                : '',
            'settings_url' => $settingsAvailable
                ? ee('CP/URL')->make('addons/settings/' . $installedShortName)->compile()
                : '',
            'packages_url' => $packagesUrl->compile(),
            'docs_url' => ee('CP/URL')->make('addons/settings/addon_installer/documentation')->compile(),
            'csrf_token' => $installer->csrfToken(),
        ]);

        return $this;
    }
}
