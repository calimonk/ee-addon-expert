<?php

namespace Nivoli\AddonExpert\ControlPanel\Routes;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;
use Nivoli\AddonExpert\Service\RegistryKeyStore;
use Nivoli\AddonExpert\Service\UpdateSourceRegistry;

/**
 * Per-add-on update-source mapping. For every installed add-on you choose
 * where its updates come from — a GitHub repo or a license-gated registry —
 * unless its own addon.setup.php already declares one (which wins and shows
 * read-only here). The license key itself lives on the Settings screen
 * (one per vendor host); this screen only configures the source.
 */
class Sources extends AbstractRoute
{
    use LoadsStyle;

    /** @var string */
    protected $route_path = 'sources';

    /** @var string */
    protected $cp_page_title = 'Update Sources';

    public function process($id = false)
    {
        $this->addBreadcrumb('index', 'Addon Expert');
        $this->addBreadcrumb('sources', 'Update Sources');
        $this->loadStyle();

        $installer = ee('addon_expert:packageInstaller');
        $registry  = ee('addon_expert:updateSourceRegistry');
        $keys      = ee('addon_expert:registryKeyStore');
        $selfUrl   = ee('CP/URL')->make('addons/settings/addon_expert/sources');

        if (ee('Request')->isPost() && ee()->input->post('save_sources')) {
            // Rebuild the admin map from every editable (non-manifest) row.
            // Manifest-declared sources aren't posted, so they're untouched.
            $types    = (array) ee()->input->post('source_type');     // short => none|github|registry
            $repos    = (array) ee()->input->post('repo');            // short => owner/repo
            $regUrls  = (array) ee()->input->post('registry_url');    // short => https://…
            $regProds = (array) ee()->input->post('registry_product');// short => slug

            $map = [];
            foreach ($types as $short => $type) {
                $short = (string) $short;
                if (! preg_match('#^[a-z0-9_]+$#', $short)) {
                    continue;
                }
                if ($type === 'github') {
                    $repo = trim((string) ($repos[$short] ?? ''));
                    if ($repo !== '') {
                        $map[$short] = $repo;
                    }
                } elseif ($type === 'registry') {
                    $url = trim((string) ($regUrls[$short] ?? ''));
                    $product = trim((string) ($regProds[$short] ?? ''));
                    if ($url !== '' && $product !== '') {
                        $map[$short] = ['type' => 'registry', 'url' => $url, 'product' => $product];
                    }
                }
                // 'none' (or blank fields) → omitted → mapping cleared.
            }

            if ($registry->saveAll($map)) {
                ee('CP/Alert')->makeBanner('addon-installer-sources')
                    ->asSuccess()
                    ->withTitle('Sources saved')
                    ->addToBody('Update sources updated. Use "Check for updates" on the Releases screen to refresh.')
                    ->defer();
            } else {
                ee('CP/Alert')->makeBanner('addon-installer-sources')
                    ->asIssue()
                    ->withTitle('Sources could not be saved')
                    ->addToBody('Check that system/user/config is writable.')
                    ->defer();
            }
            ee()->functions->redirect($selfUrl);
        }

        $packages = $installer->installedPackages();
        $admin = $registry->all();

        $rows = [];
        foreach ($packages as $pkg) {
            $short = (string) ($pkg['short_name'] ?? '');
            if ($short === '') {
                continue;
            }
            $resolved = $registry->resolve($short);
            $isManifest = $resolved !== null && ($resolved['source'] ?? '') === UpdateSourceRegistry::SOURCE_MANIFEST;

            $adminEntry = $admin[$short] ?? null;
            $adminType = 'none';
            $adminRepo = '';
            $adminRegUrl = '';
            $adminRegProduct = '';
            if (is_string($adminEntry) && $adminEntry !== '') {
                $adminType = 'github';
                $adminRepo = $adminEntry;
            } elseif (is_array($adminEntry) && ($adminEntry['type'] ?? '') === 'registry') {
                $adminType = 'registry';
                $adminRegUrl = (string) ($adminEntry['url'] ?? '');
                $adminRegProduct = (string) ($adminEntry['product'] ?? '');
            }

            // Display fields for a manifest-declared source (read-only).
            $declared = null;
            if ($isManifest) {
                $declared = ($resolved['type'] === 'registry')
                    ? ['kind' => 'registry', 'host' => RegistryKeyStore::hostOf((string) $resolved['url']), 'product' => (string) $resolved['product']]
                    : ['kind' => 'github', 'repo' => (string) $resolved['repo']];
            }

            $rows[] = [
                'short_name'        => $short,
                'name'              => (string) ($pkg['name'] ?? $short),
                'installed'         => (string) ($pkg['installed_version'] ?? ($pkg['version'] ?? '')),
                'is_manifest'       => $isManifest,
                'declared'          => $declared,
                'admin_type'        => $adminType,
                'admin_repo'        => $adminRepo,
                'admin_reg_url'     => $adminRegUrl,
                'admin_reg_product' => $adminRegProduct,
            ];
        }

        $this->setBody('Sources', [
            'rows'         => $rows,
            'save_url'     => $selfUrl->compile(),
            'csrf_token'   => $installer->csrfToken(),
            'manager_url'  => ee('CP/URL')->make('addons')->compile(),
            'releases_url' => ee('CP/URL')->make('addons/settings/addon_expert/releases')->compile(),
            'settings_url' => ee('CP/URL')->make('addons/settings/addon_expert/settings')->compile(),
            'docs_url'     => ee('CP/URL')->make('addons/settings/addon_expert/documentation')->compile(),
        ]);

        return $this;
    }
}
