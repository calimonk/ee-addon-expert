<?php
/**
 * PackageInstaller inspect + quarantine round-trip — the 2.1.0 upload flow
 * that holds an incompatible package for a one-click force instead of
 * losing the uploaded file.
 *
 *   php tests/test-quarantine.php
 */

require __DIR__ . '/_bootstrap.php';

use Nivoli\AddonExpert\Service\PackageInstaller;

// Make the addon_expert dir exist so detectAddonsPath-style checks are happy,
// and so installedPackages-style globbing has a real root.
@mkdir(SYSPATH . 'user/addons/addon_expert', 0775, true);
file_put_contents(SYSPATH . 'user/addons/addon_expert/addon.setup.php', "<?php return ['version' => '2.2.0'];");

$pi = new PackageInstaller(SYSPATH . 'user/addons');

// A package that declares an impossible PHP requirement.
$zip = make_zip([
    'lasting_impressions_pro/addon.setup.php' => "<?php return ['name' => 'LI Pro', 'version' => '5.0.4', 'requires' => ['php' => '99.0']];",
    'lasting_impressions_pro/mod.lasting_impressions_pro.php' => '<?php class X {}',
]);

section('inspectForInstall');

$ins = $pi->inspectForInstall($zip);
check('detects short_name', $ins['short_name'] === 'lasting_impressions_pro');
check('flags incompatible (php 99)', ! empty($ins['issues']));
check('runs the feature scan', is_array($ins['scan']));
check('scan clear (no 99-only syntax in a trivial file)', ($ins['scan']['verdict'] ?? '') === 'clear');

section('quarantine round-trip');

$token = $pi->quarantineStore($zip, [
    'short_name' => 'lasting_impressions_pro',
    'name'       => 'LI Pro',
    'version'    => '5.0.4',
    'issues'     => $ins['issues'],
    'scan'       => $ins['scan']['summary'] ?? null,
    'overwrite'  => false,
]);
check('token is 16 hex chars', (bool) preg_match('/^[a-f0-9]{16}$/', $token));

$q = $pi->quarantineGet($token);
check('quarantineGet returns meta', is_array($q) && $q['short_name'] === 'lasting_impressions_pro');
check('quarantined zip exists on disk', is_array($q) && is_file($q['zip_path']));
check('meta carries the scan summary', is_array($q) && ! empty($q['scan']));
check('invalid token rejected', $pi->quarantineGet('not-a-valid-token') === null);

$pi->quarantineClear($token);
check('clear removes the entry', $pi->quarantineGet($token) === null);

section('sweep drops stale entries');

$t2 = $pi->quarantineStore($zip, ['short_name' => 'x', 'issues' => ['x'], 'overwrite' => false]);
$metaFile = SYSPATH . 'user/cache/addon_expert/quarantine/' . $t2 . '.json';
$m = json_decode((string) file_get_contents($metaFile), true);
$m['created_at'] = time() - 99999;            // backdate beyond the 1h window
file_put_contents($metaFile, json_encode($m));
$pi->sweepQuarantine(3600);
check('stale entry swept', $pi->quarantineGet($t2) === null);

@unlink($zip);
done();
