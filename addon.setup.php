<?php

use JavidFazaeli\AddonInstaller\Service\GitHubReleaseChecker;
use JavidFazaeli\AddonInstaller\Service\PackageInstaller;
use JavidFazaeli\AddonInstaller\Service\UpdateSourceRegistry;

return [
    'name'              => 'Addon Manager +',
    'description'       => 'Manage ExpressionEngine add-ons from ZIP packages through the control panel.',
    'version'           => '1.2.0-dev',
    'author'            => 'Javid Fazaeli',
    'author_url'        => 'https://fazaeli.dev',
    'namespace'         => 'JavidFazaeli\AddonInstaller',
    'settings_exist'    => true,
    'github_repo'       => 'calimonk/ee-addon-manager',
    'services.singletons' => [
        'githubReleaseChecker' => function($addon) {
            return new GitHubReleaseChecker();
        },
        'GitHubReleaseChecker' => function($addon) {
            return ee('addon_installer:githubReleaseChecker');
        },
        'updateSourceRegistry' => function($addon) {
            return new UpdateSourceRegistry();
        },
        'UpdateSourceRegistry' => function($addon) {
            return ee('addon_installer:updateSourceRegistry');
        },
        'packageInstaller' => function($addon) {
            return new PackageInstaller(
                null,
                ee('addon_installer:updateSourceRegistry'),
                ee('addon_installer:githubReleaseChecker')
            );
        },
        'PackageInstaller' => function($addon) {
            return ee('addon_installer:packageInstaller');
        },
    ],
];
