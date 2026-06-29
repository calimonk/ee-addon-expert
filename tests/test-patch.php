<?php
/**
 * PackageInstaller::patchRequiresInFile — the force-override surgery that
 * rewrites a declared `requires` down to the running environment so EE's
 * native gate accepts it. Must patch only the failing keys, leave a
 * satisfied key alone, and always produce valid PHP that then passes.
 *
 *   php tests/test-patch.php
 */

require __DIR__ . '/_bootstrap.php';

use Nivoli\AddonExpert\Service\PackageInstaller;

$pi = new PackageInstaller(SYSPATH . 'user/addons');
$parse = new ReflectionMethod(PackageInstaller::class, 'parseSetupMetadata');
$parse->setAccessible(true);

function write_setup(string $src): string
{
    $f = tempnam(sys_get_temp_dir(), 'ae_setup_') . '.php';
    file_put_contents($f, $src);
    return $f;
}

section('patch lowers only failing keys');

// php 99 unmet; ee 7.2.0 satisfied by APP_VER 7.4.0 → only php patched.
$f = write_setup("<?php\nreturn ['name' => 'LI', 'requires' => ['php' => '99.0', 'ee' => '7.2.0']];\n");
$orig = PackageInstaller::patchRequiresInFile($f);
check('php reported changed', isset($orig['php']) && $orig['php'] === '99.0');
check('ee NOT changed (already satisfied)', ! isset($orig['ee']));
$after = file_get_contents($f);
check('php value rewritten to running version', strpos($after, "'" . PHP_VERSION . "'") !== false);
check('ee value left intact', strpos($after, "'7.2.0'") !== false);
check('patched file is valid PHP', is_array(include $f));
$reparsed = $parse->invoke($pi, file_get_contents($f))['requires'] ?? [];
check('patched requires now passes the gate', PackageInstaller::checkRequirements($reparsed) === []);
@unlink($f);

section('no-op + legacy syntax');

$src = "<?php return ['requires' => ['php' => '5.6']];\n";
$f = write_setup($src);
$o = PackageInstaller::patchRequiresInFile($f);
check('already-satisfied → nothing changed', $o === []);
check('already-satisfied → file untouched', file_get_contents($f) === $src);
@unlink($f);

$f = write_setup("<?php\nreturn array('requires' => array('php' => '99.0', 'ee' => '99.0.0'));\n");
$o = PackageInstaller::patchRequiresInFile($f);
check('legacy array(): both keys changed', isset($o['php'], $o['ee']));
check('legacy array(): valid PHP after', is_array(include $f));
$reparsed = $parse->invoke($pi, file_get_contents($f))['requires'] ?? [];
check('legacy array(): passes after patch', PackageInstaller::checkRequirements($reparsed) === []);
@unlink($f);

$f = write_setup("<?php return ['name' => 'X'];\n");
check('no requires block → empty result', PackageInstaller::patchRequiresInFile($f) === []);
@unlink($f);

done();
