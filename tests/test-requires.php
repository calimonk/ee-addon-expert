<?php
/**
 * PackageInstaller requirement parsing + the compatibility verdict
 * (checkRequirements), which mirrors EE's own version_compare gate.
 *
 *   php tests/test-requires.php
 */

require __DIR__ . '/_bootstrap.php';

use Nivoli\AddonExpert\Service\PackageInstaller;

$pi = new PackageInstaller(SYSPATH . 'user/addons');
$parse = new ReflectionMethod(PackageInstaller::class, 'parseSetupMetadata');
$parse->setAccessible(true);
$req = fn(string $src) => ($parse->invoke($pi, $src)['requires'] ?? []);

section('parse requires block');

check('modern bracket array', $req("<?php return ['requires' => ['php' => '8.3', 'ee' => '7.0.0']];") === ['php' => '8.3', 'ee' => '7.0.0']);
check('legacy array() syntax', $req("<?php return array('requires' => array('php' => '7.4'));") === ['php' => '7.4']);
check('php + mysql', $req("<?php return ['requires' => ['php' => '8.1', 'mysql' => '5.7']];") === ['php' => '8.1', 'mysql' => '5.7']);
check('no requires block', $req("<?php return ['name' => 'X', 'version' => '1.0'];") === []);
check('multiline / spaced / double-quoted', $req("<?php return [\n  'requires' => [\n    'php'  =>  \"8.2\",\n    'ee'   =>  \"7.2.1\",\n  ],\n];") === ['php' => '8.2', 'ee' => '7.2.1']);

section('checkRequirements verdict (running PHP ' . PHP_VERSION . ', APP_VER ' . APP_VER . ')');

check('php far future → blocks', PackageInstaller::checkRequirements(['php' => '99.0']) !== []);
check('php ancient → ok', PackageInstaller::checkRequirements(['php' => '5.6']) === []);
check('ee far future → blocks', PackageInstaller::checkRequirements(['ee' => '99.0.0']) !== []);
check('ee ancient → ok', PackageInstaller::checkRequirements(['ee' => '6.0.0']) === []);
check('empty requires → ok', PackageInstaller::checkRequirements([]) === []);
check('satisfied php + unmet ee → blocks on ee only', (function () {
    $issues = PackageInstaller::checkRequirements(['php' => '5.6', 'ee' => '99.0.0']);
    return count($issues) === 1 && strpos($issues[0], 'ExpressionEngine') !== false;
})());

done();
