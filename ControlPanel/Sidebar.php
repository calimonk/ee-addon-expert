<?php

namespace JavidFazaeli\AddonInstaller\ControlPanel;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractSidebar;

class Sidebar extends AbstractSidebar
{
    public $automatic = false;

    public $header = 'Addon Manager +';

    private string $base = 'addons/settings/addon_installer/';

    public function process()
    {
        $sidebar = ee('CP/Sidebar')->make();
        $list = $sidebar->addHeader($this->header)->addBasicList();

        $current = ee()->uri->uri_string;
        $mk = fn($suffix) => ee('CP/URL')->make($this->base . $suffix);

        $list->addItem('Install ZIP', $mk('index'))
            ->withIcon('upload')
            ->isActive(
                strpos($current, $this->base . 'index') !== false
                || rtrim($current, '/') === rtrim($this->base, '/')
            );

        $list->addItem('Packages', $mk('packages'))
            ->withIcon('puzzle-piece')
            ->isActive(strpos($current, $this->base . 'packages') !== false);

        // "Releases" entry — append "(N)" when GitHub flags pending updates.
        // The sidebar widget doesn't render arbitrary badges, so the count
        // is baked into the label. Inexpensive (cache-only, no HTTP).
        $count = $this->remoteUpdateCount();
        $releasesLabel = $count > 0
            ? sprintf('Releases (%d)', $count)
            : 'Releases';

        $list->addItem($releasesLabel, $mk('releases'))
            ->withIcon('cloud-download')
            ->isActive(strpos($current, $this->base . 'releases') !== false);

        $list->addItem('Audit Log', $mk('audit-log'))
            ->withIcon('clipboard-list')
            ->isActive(strpos($current, $this->base . 'audit-log') !== false);

        $list->addItem('Settings', $mk('settings'))
            ->withIcon('cog')
            ->isActive(strpos($current, $this->base . 'settings') !== false);

        $list->addItem('Documentation', $mk('documentation'))
            ->withIcon('book')
            ->isActive(strpos($current, $this->base . 'documentation') !== false);

        ee()->view->sidebar = $sidebar->render();
    }

    /**
     * Count of tracked add-ons that currently have a newer GitHub release
     * than what's on disk. Reads cache only — no HTTP, no measurable load
     * cost. Errors here must NEVER crash the sidebar, so anything thrown
     * by the installer falls back to 0.
     */
    private function remoteUpdateCount(): int
    {
        try {
            return (int) ee('addon_installer:packageInstaller')->remoteUpdateCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
