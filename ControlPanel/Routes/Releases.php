<?php

namespace Nivoli\AddonExpert\ControlPanel\Routes;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;
use Nivoli\AddonExpert\Service\GitHubReleaseChecker;
use Nivoli\AddonExpert\Service\ReleaseInstaller;
use Nivoli\AddonExpert\Service\UpdateSourceRegistry;

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
        $this->addBreadcrumb('index', 'Addon Expert');
        $this->addBreadcrumb('releases', 'Releases');
        $this->loadStyle();

        $installer        = ee('addon_expert:packageInstaller');
        $registry         = ee('addon_expert:updateSourceRegistry');
        $checker          = ee('addon_expert:githubReleaseChecker');
        $releaseInstaller = ee('addon_expert:releaseInstaller');
        $trust            = ee('addon_expert:trustStore');
        $auditor          = ee('addon_expert:installAuditor');
        $finalizer        = ee('addon_expert:autoFinalizer');
        $settings         = ee('addon_expert:settingsStore');

        $selfUrl = ee('CP/URL')->make('addons/settings/addon_expert/releases');

        // ---- Auto-finalize pending EE-side updates ----
        // Any release the installer recently swapped on disk has a
        // marker file waiting; finalize them now so the admin doesn't
        // have to navigate to Developer → Add-Ons and click each
        // Update prompt by hand. Setting can disable this.
        $finalizeResults = null;
        if ($settings->get('auto_finalize') === 'y') {
            try {
                $pending = $finalizer->pending();
                if (! empty($pending)) {
                    $finalizeResults = $finalizer->finalizeAllPending();
                }
            } catch (\Throwable $e) {
                // Never let finalize errors block the screen.
            }
        }

        // ---- Lazy refresh of stale release caches ----
        // On-view parallel refresh of any mapping whose cache is older
        // than the release TTL. Single round-trip via curl_multi; total
        // wait time = slowest single fetch (~4s ceiling), not N * 4s.
        // The explicit "Check for updates" button still forces a full
        // refresh of every mapping.
        if ($settings->get('lazy_refresh') === 'y') {
            try {
                $this->lazyRefreshStaleReleases($installer, $registry, $checker);
            } catch (\Throwable $e) {
                // Refresh failures must never break the screen render.
            }
        }

        // POST: explicit "reconfirm trust" — admin has reviewed the
        // identity change on GitHub and accepted it. We re-fetch the
        // identity (fresh, no cache) and overwrite the pinned anchor.
        if (ee('Request')->isPost() && ee()->input->post('reconfirm_trust')) {
            $shortName = (string) ee()->input->post('short_name');
            $shortName = preg_match('#^[a-z0-9_]+$#', $shortName) ? $shortName : '';
            $mapping = $shortName !== '' ? $registry->resolve($shortName) : null;

            // Trust pinning is a GitHub-only concept; registry sources are
            // gated by the license + signed URL + checksum instead.
            if ($mapping === null || ($mapping['type'] ?? 'github') !== 'github') {
                ee('CP/Alert')->makeBanner('addon-installer-trust')
                    ->asIssue()
                    ->withTitle('Reconfirm failed')
                    ->addToBody($mapping === null
                        ? 'No mapping found for ' . $shortName . '.'
                        : 'Trust reconfirmation only applies to GitHub sources.')
                    ->defer();
                ee()->functions->redirect($selfUrl);
            }

            $identity = $checker->repoIdentityRefresh($mapping['repo']);
            if ($identity === null) {
                ee('CP/Alert')->makeBanner('addon-installer-trust')
                    ->asIssue()
                    ->withTitle('Reconfirm failed')
                    ->addToBody('Could not fetch identity from GitHub. Try again.')
                    ->defer();
                ee()->functions->redirect($selfUrl);
            }

            $pinnedBy = null;
            try {
                $login = ee()->session->userdata('username');
                $pinnedBy = is_string($login) && $login !== '' ? $login : null;
            } catch (\Throwable $e) {
                // best effort
            }
            $trust->pin($mapping['repo'], $identity, $pinnedBy);

            $auditor->record([
                'event' => 'trust_reconfirmed_manual',
                'short_name' => $shortName,
                'repo' => $mapping['repo'],
                'identity' => $identity,
            ]);

            ee('CP/Alert')->makeBanner('addon-installer-trust')
                ->asSuccess()
                ->withTitle('Trust reconfirmed')
                ->addToBody('The new identity for ' . $mapping['repo'] . ' is now pinned. '
                    . 'You can install the release.')
                ->defer();
            ee()->functions->redirect($selfUrl);
        }

        // POST: install/update an add-on from its mapped GitHub release.
        // Routed to here from both the Packages and Releases screens so
        // there's a single code path for the one-click flow. On success
        // we hand off to EE's native update screen so the admin still
        // explicitly approves any migration steps.
        if (ee('Request')->isPost() && ee()->input->post('install_release')) {
            $shortName = (string) ee()->input->post('short_name');
            $shortName = preg_match('#^[a-z0-9_]+$#', $shortName) ? $shortName : '';

            if ($shortName === '') {
                ee('CP/Alert')->makeBanner('addon-installer-release-install')
                    ->asIssue()
                    ->withTitle('Update failed')
                    ->addToBody('Missing or malformed short_name.')
                    ->defer();
                ee()->functions->redirect($selfUrl);
            }

            $mapping = $registry->resolve($shortName);
            if ($mapping === null) {
                ee('CP/Alert')->makeBanner('addon-installer-release-install')
                    ->asIssue()
                    ->withTitle('Update failed')
                    ->addToBody('No update source is configured for ' . $shortName . '.')
                    ->defer();
                ee()->functions->redirect($selfUrl);
            }

            try {
                $result = (($mapping['type'] ?? 'github') === 'registry')
                    ? $releaseInstaller->installLatestFromRegistry($shortName, $mapping['url'], $mapping['product'])
                    : $releaseInstaller->installLatestRelease($shortName, $mapping['repo']);

                $isSelf = ! empty($result['is_self']);
                $addonsUrl = ee('CP/URL')->make('addons')->compile();
                $body = sprintf(
                    '%s v%s was extracted from %s.',
                    $shortName,
                    $result['version'] !== '' ? $result['version'] : 'latest',
                    $result['source']
                );
                $body .= ' Next step: open ';
                $body .= '<a href="' . htmlspecialchars($addonsUrl, ENT_QUOTES, 'UTF-8') . '">Developer → Add-Ons</a>';
                $body .= ' and click the <strong>Update</strong> prompt on the ';
                $body .= htmlspecialchars($isSelf ? 'Addon Expert' : $shortName, ENT_QUOTES, 'UTF-8');
                $body .= ' card to finalize the install (DB version bump + any migrations).';

                ee('CP/Alert')->makeBanner('addon-installer-release-install')
                    ->asSuccess()
                    ->withTitle('Release installed')
                    ->addToBody($body)
                    ->defer();

                // Redirect back to our own Releases screen rather than
                // EE's native cp/addons listing. Earlier versions
                // redirected to cp/addons hoping EE's "Update Available"
                // prompt would naturally pick up the install, but that
                // target returned a transient 403 in some EE 7 setups
                // (suspected: session-token validation interaction with
                // the very-fresh on-disk version that EE hasn't fully
                // re-cached yet). Staying inside our own authorized
                // routes is reliable; the banner gives the user a
                // direct link to Developer → Add-Ons to finalize.
                ee()->functions->redirect($selfUrl);
            } catch (\Throwable $e) {
                ee('CP/Alert')->makeBanner('addon-installer-release-install')
                    ->asIssue()
                    ->withTitle('Update failed')
                    ->addToBody($e->getMessage())
                    ->defer();
                ee()->functions->redirect($selfUrl);
            }
        }

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

        // Source mapping moved to its own screen (Update Sources). The
        // Releases screen is now read-only about *where* updates come from;
        // it just tracks + installs them.

        $packages = $installer->installedPackages();

        $updatesCount = 0;
        foreach ($packages as $pkg) {
            if (! empty($pkg['remote_update_available'])) {
                $updatesCount++;
            }
        }

        $this->setBody('Releases', [
            'packages'         => $packages,
            'updates_count'    => $updatesCount,
            'refresh_url'      => $selfUrl->compile(),
            'csrf_token'       => $installer->csrfToken(),
            'manager_url'      => ee('CP/URL')->make('addons')->compile(),
            'packages_url'     => ee('CP/URL')->make('addons/settings/addon_expert/packages')->compile(),
            'audit_url'        => ee('CP/URL')->make('addons/settings/addon_expert/audit-log')->compile(),
            'docs_url'         => ee('CP/URL')->make('addons/settings/addon_expert/documentation')->compile(),
            'sources_url'      => ee('CP/URL')->make('addons/settings/addon_expert/sources')->compile(),
            'settings_url'     => ee('CP/URL')->make('addons/settings/addon_expert/settings')->compile(),
            'finalize_results' => $finalizeResults,
        ]);

        return $this;
    }

    /**
     * Refresh any release-cache entry older than the TTL in parallel.
     * No-op when nothing's stale (cheap on every page load).
     */
    private function lazyRefreshStaleReleases($installer, $registry, $checker): void
    {
        $packages = $installer->installedPackages();
        $stale = [];
        $staleRegistry = [];
        foreach ($packages as $pkg) {
            $kind = $pkg['remote_kind'] ?? null;
            if ($kind === 'registry') {
                // Only when a license key is present — no point hitting the
                // endpoint to be told we're unauthenticated.
                if (! empty($pkg['remote_key_present'])
                    && ($pkg['remote_registry_url'] ?? '') !== ''
                    && ($pkg['remote_registry_product'] ?? '') !== '') {
                    $staleRegistry[] = $pkg;
                }
                continue;
            }
            $repo = (string) ($pkg['remote_repo'] ?? '');
            if ($repo === '') continue;
            if (! $checker->isStale($repo)) continue;
            $stale[$repo] = true;
        }

        if (! empty($stale)) {
            $checker->refreshMultiple(array_keys($stale));
        }

        if (! empty($staleRegistry)) {
            $registryChecker = ee('addon_expert:registryReleaseChecker');
            $keys = ee('addon_expert:registryKeyStore');
            foreach ($staleRegistry as $pkg) {
                $url = (string) $pkg['remote_registry_url'];
                $product = (string) $pkg['remote_registry_product'];
                if (! $registryChecker->isStale($url, $product)) continue;
                $key = $keys->keyForUrl($url);
                if ($key === '') continue;
                $current = '';
                try {
                    $addon = ee('Addon')->get($pkg['short_name']);
                    $current = $addon ? (string) $addon->getVersion() : '';
                } catch (\Throwable $e) {
                    // best-effort
                }
                $registryChecker->refresh($url, $product, $key, $current);
            }
        }
    }
}
