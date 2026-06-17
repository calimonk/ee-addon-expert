<?php

namespace Codebit\AddonExpert\ControlPanel\Routes;

trait LoadsStyle
{
    protected function loadStyle(): void
    {
        $path = dirname(__DIR__, 2) . '/views/css/style.css';
        if (is_file($path)) {
            ee()->cp->add_to_head('<style>' . file_get_contents($path) . '</style>');
        }
    }
}
