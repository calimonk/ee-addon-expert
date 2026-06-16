<?php

use JavidFazaeli\AddonInstaller\Service\AutoFinalizer;
use JavidFazaeli\AddonInstaller\Service\CompatibilityScanner;
use JavidFazaeli\AddonInstaller\Service\GitHubReleaseChecker;
use JavidFazaeli\AddonInstaller\Service\InstallAuditor;
use JavidFazaeli\AddonInstaller\Service\OverrideStore;
use JavidFazaeli\AddonInstaller\Service\PackageInstaller;
use JavidFazaeli\AddonInstaller\Service\ReleaseInstaller;
use JavidFazaeli\AddonInstaller\Service\SettingsStore;
use JavidFazaeli\AddonInstaller\Service\TrustStore;
use JavidFazaeli\AddonInstaller\Service\UpdateSourceRegistry;

return [
    'name'              => 'Addon Manager +',
    'description'       => 'Manage ExpressionEngine add-ons from ZIP packages through the control panel.',
    'version'           => '1.7.0',
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
        'trustStore' => function($addon) {
            return new TrustStore();
        },
        'TrustStore' => function($addon) {
            return ee('addon_installer:trustStore');
        },
        'installAuditor' => function($addon) {
            return new InstallAuditor();
        },
        'InstallAuditor' => function($addon) {
            return ee('addon_installer:installAuditor');
        },
        'settingsStore' => function($addon) {
            return new SettingsStore();
        },
        'SettingsStore' => function($addon) {
            return ee('addon_installer:settingsStore');
        },
        'overrideStore' => function($addon) {
            return new OverrideStore();
        },
        'OverrideStore' => function($addon) {
            return ee('addon_installer:overrideStore');
        },
        'compatibilityScanner' => function($addon) {
            return new CompatibilityScanner();
        },
        'CompatibilityScanner' => function($addon) {
            return ee('addon_installer:compatibilityScanner');
        },
        'autoFinalizer' => function($addon) {
            return new AutoFinalizer(
                ee('addon_installer:installAuditor')
            );
        },
        'AutoFinalizer' => function($addon) {
            return ee('addon_installer:autoFinalizer');
        },
        'releaseInstaller' => function($addon) {
            return new ReleaseInstaller(
                null,
                ee('addon_installer:githubReleaseChecker'),
                ee('addon_installer:trustStore'),
                ee('addon_installer:installAuditor'),
                ee('addon_installer:autoFinalizer'),
                ee('addon_installer:overrideStore')
            );
        },
        'ReleaseInstaller' => function($addon) {
            return ee('addon_installer:releaseInstaller');
        },
        'packageInstaller' => function($addon) {
            return new PackageInstaller(
                null,
                ee('addon_installer:updateSourceRegistry'),
                ee('addon_installer:githubReleaseChecker'),
                ee('addon_installer:autoFinalizer'),
                ee('addon_installer:installAuditor'),
                ee('addon_installer:overrideStore'),
                ee('addon_installer:compatibilityScanner')
            );
        },
        'PackageInstaller' => function($addon) {
            return ee('addon_installer:packageInstaller');
        },
    ],
];
