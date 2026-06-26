<?php

namespace Nivoli\AddonExpert\ControlPanel\Routes;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

class AuditLog extends AbstractRoute
{
    use LoadsStyle;

    /** @var string */
    protected $route_path = 'audit-log';

    /** @var string */
    protected $cp_page_title = 'Audit Log';

    /**
     * @param false $id
     * @return AbstractRoute
     */
    public function process($id = false)
    {
        $this->addBreadcrumb('index', 'Addon Expert');
        $this->addBreadcrumb('audit-log', 'Audit Log');
        $this->loadStyle();

        $auditor = ee('addon_expert:installAuditor');

        // Pull a larger window than the inline preview on Releases —
        // this is the dedicated screen, the user came here on purpose.
        $entries = $auditor->tail(200);

        // Breakdown by event type for the summary header. Cheap to
        // compute over 200 rows; gives the admin a single-glance read
        // of "did anything go wrong recently?" without scanning the
        // whole table.
        $counts = [];
        foreach ($entries as $event) {
            $name = (string) ($event['event'] ?? 'unknown');
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        ksort($counts);

        $this->setBody('AuditLog', [
            'entries'      => $entries,
            'counts'       => $counts,
            'log_file'     => method_exists($auditor, 'logPath')
                ? $auditor->logPath()
                : 'system/user/cache/addon_expert/install.log',
            'releases_url' => ee('CP/URL')->make('addons/settings/addon_expert/releases')->compile(),
            'packages_url' => ee('CP/URL')->make('addons/settings/addon_expert/packages')->compile(),
            'docs_url'     => ee('CP/URL')->make('addons/settings/addon_expert/documentation')->compile(),
            'manager_url'  => ee('CP/URL')->make('addons')->compile(),
        ]);

        return $this;
    }
}
