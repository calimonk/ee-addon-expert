<?php

namespace Codebit\AddonExpert\ControlPanel\Routes;

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
    protected $cp_page_title = 'Addon Expert';

    /**
     * @param false $id
     * @return AbstractRoute
     */
    public function process($id = false)
    {
        $this->addBreadcrumb('index', 'Addon Expert');
        $this->loadStyle();

        $installer = ee('addon_expert:packageInstaller');
        $result = null;
        $selfUrl = ee('CP/URL')->make('addons/settings/addon_expert/index');

        // Expire stale quarantined uploads on every visit.
        try { $installer->sweepQuarantine(); } catch (\Throwable $e) {}

        $okBanner = function ($result) {
            $body = $result['name'] . ' was extracted to the ExpressionEngine add-ons folder.';
            if (! empty($result['override_applied'])) {
                $body .= ' Version requirements were OVERRIDDEN — see the requirement-override badge on Packages.';
            }
            ee('CP/Alert')->makeBanner('addon-installer-upload')
                ->asSuccess()->withTitle('Package uploaded')->addToBody($body)->defer();
        };
        $failBanner = function (\Throwable $e) {
            ee('CP/Alert')->makeBanner('addon-installer-upload')
                ->asIssue()->withTitle('Package was not installed')->addToBody($e->getMessage())->defer();
        };

        // POST: a fresh upload. Inspect + scan BEFORE committing. If it's
        // compatible (or the admin pre-ticked override) install straight
        // away. If incompatible and not pre-authorized, quarantine the
        // uploaded zip and bounce to a confirm screen showing the scan
        // verdict + a one-click Force button — no re-upload needed.
        if (ee('Request')->isPost() && ee()->input->post('install_package')) {
            $overwrite = (bool) ee()->input->post('overwrite_existing');
            $override  = (bool) ee()->input->post('override_requirements');
            $reason    = trim((string) ee()->input->post('override_reason')) ?: null;
            try {
                $inspect = $installer->inspectUpload($_FILES['addon_package'] ?? []);

                if (empty($inspect['issues']) || $override) {
                    $result = $installer->installFromZip($inspect['tmp_name'], $overwrite, $override, $reason);
                    $okBanner($result);
                    ee()->functions->redirect(ee('CP/URL')->make('addons/settings/addon_expert/index', ['installed' => $result['short_name']]));
                }

                // Incompatible + not pre-authorized → hold for confirmation.
                $token = $installer->quarantineStore($inspect['tmp_name'], [
                    'short_name'   => $inspect['short_name'],
                    'name'         => $inspect['name'],
                    'version'      => $inspect['version'],
                    'issues'       => $inspect['issues'],
                    'scan'         => $inspect['scan']['summary'] ?? null,
                    'scan_verdict' => $inspect['scan']['verdict'] ?? null,
                    'overwrite'    => $overwrite,
                ]);
                ee()->functions->redirect(ee('CP/URL')->make('addons/settings/addon_expert/index', ['confirm' => $token]));
            } catch (\Throwable $e) {
                $failBanner($e);
                ee()->functions->redirect($selfUrl);
            }
        }

        // POST: force-install a quarantined (incompatible) package.
        if (ee('Request')->isPost() && ee()->input->post('force_quarantine')) {
            $token = (string) ee()->input->post('quarantine_token');
            $q = $installer->quarantineGet($token);
            if ($q === null) {
                ee('CP/Alert')->makeBanner('addon-installer-upload')->asIssue()
                    ->withTitle('Upload expired')
                    ->addToBody('That pending upload is no longer available. Please upload the ZIP again.')
                    ->defer();
                ee()->functions->redirect($selfUrl);
            }
            try {
                $reason = trim((string) ee()->input->post('override_reason')) ?: null;
                $result = $installer->installFromZip($q['zip_path'], (bool) ($q['overwrite'] ?? false), true, $reason);
                $installer->quarantineClear($token);
                $okBanner($result);
                ee()->functions->redirect(ee('CP/URL')->make('addons/settings/addon_expert/index', ['installed' => $result['short_name']]));
            } catch (\Throwable $e) {
                $failBanner($e);
                ee()->functions->redirect($selfUrl);
            }
        }

        // POST: discard a quarantined package.
        if (ee('Request')->isPost() && ee()->input->post('cancel_quarantine')) {
            $installer->quarantineClear((string) ee()->input->post('quarantine_token'));
            ee('CP/Alert')->makeBanner('addon-installer-upload')->asSuccess()
                ->withTitle('Upload discarded')->addToBody('The pending package was removed.')->defer();
            ee()->functions->redirect($selfUrl);
        }

        // GET: a pending-confirmation upload to render.
        $confirm = null;
        $confirmToken = (string) ee()->input->get('confirm', true);
        if ($confirmToken !== '') {
            $confirm = $installer->quarantineGet($confirmToken);
        }

        // Run any pending auto-finalize markers. After a successful
        // upload, the user lands back on this screen via redirect —
        // exactly the right time to fire EE's update flow for the
        // freshly-extracted add-on. Without this, the admin had to
        // wait minutes for EE to scan and surface the Update prompt
        // on Developer → Add-Ons.
        $finalizeResults = null;
        try {
            $settings = ee('addon_expert:settingsStore');
            if ($settings->get('auto_finalize') === 'y') {
                $finalizer = ee('addon_expert:autoFinalizer');
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
        $packagesUrl = ee('CP/URL')->make('addons/settings/addon_expert/packages');

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
            'docs_url' => ee('CP/URL')->make('addons/settings/addon_expert/documentation')->compile(),
            'csrf_token' => $installer->csrfToken(),
            'confirm' => $confirm,
            'confirm_url' => $selfUrl->compile(),
        ]);

        return $this;
    }
}
