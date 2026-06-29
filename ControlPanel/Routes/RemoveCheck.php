<?php

namespace Nivoli\AddonExpert\ControlPanel\Routes;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

/**
 * Pre-removal safety check. The Packages "remove" action routes here first:
 * we scan for the add-on's footprint (template/snippet tags, channel fields,
 * extension hooks) and show the admin what removing it might break, with an
 * explicit "proceed" that hands off to EE's native uninstall.
 */
class RemoveCheck extends AbstractRoute
{
    use LoadsStyle;

    /** @var string */
    protected $route_path = 'remove-check';

    /** @var string */
    protected $cp_page_title = 'Remove add-on';

    public function process($id = false)
    {
        $this->addBreadcrumb('index', 'Addon Expert');
        $this->addBreadcrumb('packages', 'Packages');
        $this->addBreadcrumb('remove-check', 'Remove');
        $this->loadStyle();

        $packagesUrl = ee('CP/URL')->make('addons/settings/addon_expert/packages');

        // POST: removal confirmed. EE's native uninstall can't be hooked, so
        // we record the audit entry here, then hand off to it.
        if (ee('Request')->isPost() && ee()->input->post('confirm_remove')) {
            $rs = (string) ee()->input->post('short_name');
            $a = preg_match('#^[a-z0-9_]+$#', $rs) ? ee('Addon')->get($rs) : null;
            if ($a && $a->isInstalled()) {
                ee('addon_expert:installAuditor')->record([
                    'event'      => 'uninstall',
                    'short_name' => $rs,
                    'version'    => (string) $a->getInstalledVersion(),
                    'source'     => 'addon_expert_remove',
                ]);
                $returnUrl = ee('CP/URL')->make('addons/settings/addon_expert/packages')->encode();
                $this->setBody('RemoveCheck', [
                    'handoff'    => true,
                    'short'      => $rs,
                    'name'       => $a->getName() ?: $rs,
                    'remove_url' => ee('CP/URL')->make('addons/remove/' . $rs, ['return' => $returnUrl])->compile(),
                    'csrf_token' => ee('addon_expert:packageInstaller')->csrfToken(),
                    'cancel_url' => $packagesUrl->compile(),
                ]);
                return $this;
            }
            ee()->functions->redirect($packagesUrl);
        }

        $short = (string) ee()->input->get('short');
        if (! preg_match('#^[a-z0-9_]+$#', $short)) {
            ee()->functions->redirect($packagesUrl);
        }

        $addon = ee('Addon')->get($short);
        if (! $addon || ! $addon->isInstalled()) {
            ee('CP/Alert')->makeBanner('addon-installer-remove')
                ->asIssue()
                ->withTitle('Nothing to remove')
                ->addToBody('“' . $short . '” is not installed.')
                ->defer();
            ee()->functions->redirect($packagesUrl);
        }

        $name = $addon->getName() ?: $short;
        $usage = ee('addon_expert:usageScanner')->scan($short);

        // EE's native uninstall (POST + CSRF). Returns to Packages after.
        $returnUrl = ee('CP/URL')->make('addons/settings/addon_expert/packages')->encode();
        $removeUrl = ee('CP/URL')->make('addons/remove/' . $short, ['return' => $returnUrl])->compile();

        $this->setBody('RemoveCheck', [
            'short'        => $short,
            'name'         => $name,
            'usage'        => $usage,
            'tag'          => \Nivoli\AddonExpert\Service\UsageScanner::tagNeedle($short),
            'remove_url'   => $removeUrl,
            'confirm_url'  => ee('CP/URL')->make('addons/settings/addon_expert/remove-check')->compile(),
            'cancel_url'   => $packagesUrl->compile(),
            'csrf_token'   => ee('addon_expert:packageInstaller')->csrfToken(),
        ]);

        return $this;
    }
}
