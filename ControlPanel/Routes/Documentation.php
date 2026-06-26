<?php

namespace Nivoli\AddonExpert\ControlPanel\Routes;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

class Documentation extends AbstractRoute
{
    use LoadsStyle;

    /**
     * @var string
     */
    protected $route_path = 'documentation';

    /**
     * @var string
     */
    protected $cp_page_title = 'Documentation';

    /**
     * @param false $id
     * @return AbstractRoute
     */
    public function process($id = false)
    {
        $this->addBreadcrumb('index', 'Addon Expert');
        $this->addBreadcrumb('documentation', 'Documentation');
        $this->loadStyle();

        $this->setBody('Documentation', [
            'upload_url' => ee('CP/URL')->make('addons/settings/addon_expert/index')->compile(),
            'packages_url' => ee('CP/URL')->make('addons/settings/addon_expert/packages')->compile(),
            'releases_url' => ee('CP/URL')->make('addons/settings/addon_expert/releases')->compile(),
            'audit_url' => ee('CP/URL')->make('addons/settings/addon_expert/audit-log')->compile(),
            'settings_url' => ee('CP/URL')->make('addons/settings/addon_expert/settings')->compile(),
            'manager_url' => ee('CP/URL')->make('addons')->compile(),
        ]);

        return $this;
    }
}
