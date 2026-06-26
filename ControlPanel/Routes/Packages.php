<?php

namespace Nivoli\AddonExpert\ControlPanel\Routes;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

class Packages extends AbstractRoute
{
    use LoadsStyle;

    /**
     * @var string
     */
    protected $route_path = 'packages';

    /**
     * @var string
     */
    protected $cp_page_title = 'Packages';

    /**
     * @param false $id
     * @return AbstractRoute
     */
    public function process($id = false)
    {
        $this->addBreadcrumb('index', 'Addon Expert');
        $this->addBreadcrumb('packages', 'Packages');
        $this->loadStyle();

        $installer = ee('addon_expert:packageInstaller');
        $download = (string) ee()->input->get('download', true);

        if ($download !== '') {
            $this->downloadPackage($installer, $download);
        }

        // Same auto-finalize trigger as Index/Releases — covers users
        // who navigate to Packages after a ZIP upload or GitHub
        // install and skip the route the redirect actually targeted.
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

        $this->setBody('Packages', [
            'packages' => $installer->installedPackages(),
            'upload_url' => ee('CP/URL')->make('addons/settings/addon_expert/index')->compile(),
            'docs_url' => ee('CP/URL')->make('addons/settings/addon_expert/documentation')->compile(),
            'manager_url' => ee('CP/URL')->make('addons')->compile(),
            'csrf_token' => $installer->csrfToken(),
            'finalize_results' => $finalizeResults,
        ]);

        return $this;
    }

    private function downloadPackage($installer, string $shortName): void
    {
        $path = $installer->createPackageZip($shortName);
        $filename = preg_replace('/[^a-z0-9_]/', '_', strtolower(basename($shortName))) . '.zip';

        ee()->output->enable_profiler(false);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');

        readfile($path);
        @unlink($path);
        exit;
    }
}
