<?php

namespace JavidFazaeli\AddonInstaller\ControlPanel\Routes;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;
use JavidFazaeli\AddonInstaller\Service\GitHubReleaseChecker;
use JavidFazaeli\AddonInstaller\Service\UpdateSourceRegistry;

class Releases extends AbstractRoute
{
    use LoadsStyle;

    /** @var string */
    protected $route_path = 'releases';

    /** @var string */
    protected $cp_page_title = 'Releases';

    /**
     * @param false $id
     * @return AbstractRoute
     */
    public function process($id = false)
    {
        $this->addBreadcrumb('index', 'Addon Manager +');
        $this->addBreadcrumb('releases', 'Releases');
        $this->loadStyle();

        $installer = ee('addon_installer:packageInstaller');
        $registry  = ee('addon_installer:updateSourceRegistry');
        $checker   = ee('addon_installer:githubReleaseChecker');

        $selfUrl = ee('CP/URL')->make('addons/settings/addon_installer/releases');

        // POST: refresh all known release feeds.
        if (ee('Request')->isPost() && ee()->input->post('refresh_releases')) {
            $results = $installer->refreshAllReleases();
            $ok   = count(array_filter($results, fn($r) => $r['ok']));
            $fail = count($results) - $ok;
            $banner = ee('CP/Alert')->makeBanner('addon-installer-release-refresh')
                ->withTitle('Release feeds refreshed');
            if ($fail === 0) {
                $banner->asSuccess()
                    ->addToBody(sprintf('Checked %d repo%s.', $ok, $ok === 1 ? '' : 's'));
            } else {
                $banner->asIssue()
                    ->addToBody(sprintf('%d ok, %d failed. Failed entries keep their last known release.', $ok, $fail));
            }
            $banner->defer();
            ee()->functions->redirect($selfUrl);
        }

        // POST: save admin mappings.
        if (ee('Request')->isPost() && ee()->input->post('save_mappings')) {
            $raw = (array) ee()->input->post('repo');
            $map = [];
            foreach ($raw as $shortName => $repo) {
                $shortName = (string) $shortName;
                $repo = trim((string) $repo);
                if ($shortName !== '' && $repo !== '') {
                    $map[$shortName] = $repo;
                }
            }

            if ($registry->saveAll($map)) {
                ee('CP/Alert')->makeBanner('addon-installer-release-mappings')
                    ->asSuccess()
                    ->withTitle('Mappings saved')
                    ->addToBody('GitHub release sources updated. Click "Check for updates" to refresh.')
                    ->defer();
            } else {
                ee('CP/Alert')->makeBanner('addon-installer-release-mappings')
                    ->asIssue()
                    ->withTitle('Mappings could not be saved')
                    ->addToBody('Check that system/user/config is writable.')
                    ->defer();
            }

            ee()->functions->redirect($selfUrl);
        }

        $packages = $installer->installedPackages();
        $adminMap = $registry->all();

        $updatesCount = 0;
        foreach ($packages as $pkg) {
            if (! empty($pkg['remote_update_available'])) {
                $updatesCount++;
            }
        }

        $this->setBody('Releases', [
            'packages'      => $packages,
            'admin_map'     => $adminMap,
            'updates_count' => $updatesCount,
            'refresh_url'   => $selfUrl->compile(),
            'save_url'      => $selfUrl->compile(),
            'csrf_token'    => $installer->csrfToken(),
            'manager_url'   => ee('CP/URL')->make('addons')->compile(),
            'packages_url'  => ee('CP/URL')->make('addons/settings/addon_installer/packages')->compile(),
            'docs_url'      => ee('CP/URL')->make('addons/settings/addon_installer/documentation')->compile(),
        ]);

        return $this;
    }
}
