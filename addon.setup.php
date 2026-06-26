<?php

use Nivoli\AddonExpert\Service\AutoFinalizer;
use Nivoli\AddonExpert\Service\CompatibilityScanner;
use Nivoli\AddonExpert\Service\GitHubReleaseChecker;
use Nivoli\AddonExpert\Service\InstallAuditor;
use Nivoli\AddonExpert\Service\OverrideStore;
use Nivoli\AddonExpert\Service\PackageInstaller;
use Nivoli\AddonExpert\Service\ReleaseInstaller;
use Nivoli\AddonExpert\Service\SettingsStore;
use Nivoli\AddonExpert\Service\TrustStore;
use Nivoli\AddonExpert\Service\UpdateSourceRegistry;

return [
    'name'              => 'Addon Expert',
    'description'       => 'Install, update, and track ExpressionEngine add-ons — ZIP uploads, GitHub releases, one-click updates, supply-chain checks. Based on Addon Manager + by Javid Fazaeli (MIT).',
    'version'           => '2.2.0',
    'author'            => 'Codebit',
    'author_url'        => 'https://codebit.nl',
    'namespace'         => 'Nivoli\AddonExpert',
    'settings_exist'    => true,
    'github_repo'       => 'calimonk/ee-addon-expert',
    'services.singletons' => [
        'githubReleaseChecker' => function($addon) {
            return new GitHubReleaseChecker();
        },
        'GitHubReleaseChecker' => function($addon) {
            return ee('addon_expert:githubReleaseChecker');
        },
        'updateSourceRegistry' => function($addon) {
            return new UpdateSourceRegistry();
        },
        'UpdateSourceRegistry' => function($addon) {
            return ee('addon_expert:updateSourceRegistry');
        },
        'trustStore' => function($addon) {
            return new TrustStore();
        },
        'TrustStore' => function($addon) {
            return ee('addon_expert:trustStore');
        },
        'installAuditor' => function($addon) {
            return new InstallAuditor();
        },
        'InstallAuditor' => function($addon) {
            return ee('addon_expert:installAuditor');
        },
        'settingsStore' => function($addon) {
            return new SettingsStore();
        },
        'SettingsStore' => function($addon) {
            return ee('addon_expert:settingsStore');
        },
        'overrideStore' => function($addon) {
            return new OverrideStore();
        },
        'OverrideStore' => function($addon) {
            return ee('addon_expert:overrideStore');
        },
        'compatibilityScanner' => function($addon) {
            return new CompatibilityScanner();
        },
        'CompatibilityScanner' => function($addon) {
            return ee('addon_expert:compatibilityScanner');
        },
        'autoFinalizer' => function($addon) {
            return new AutoFinalizer(
                ee('addon_expert:installAuditor')
            );
        },
        'AutoFinalizer' => function($addon) {
            return ee('addon_expert:autoFinalizer');
        },
        'releaseInstaller' => function($addon) {
            return new ReleaseInstaller(
                null,
                ee('addon_expert:githubReleaseChecker'),
                ee('addon_expert:trustStore'),
                ee('addon_expert:installAuditor'),
                ee('addon_expert:autoFinalizer'),
                ee('addon_expert:overrideStore')
            );
        },
        'ReleaseInstaller' => function($addon) {
            return ee('addon_expert:releaseInstaller');
        },
        'packageInstaller' => function($addon) {
            return new PackageInstaller(
                null,
                ee('addon_expert:updateSourceRegistry'),
                ee('addon_expert:githubReleaseChecker'),
                ee('addon_expert:autoFinalizer'),
                ee('addon_expert:installAuditor'),
                ee('addon_expert:overrideStore'),
                ee('addon_expert:compatibilityScanner')
            );
        },
        'PackageInstaller' => function($addon) {
            return ee('addon_expert:packageInstaller');
        },
    ],
];
