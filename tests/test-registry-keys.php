<?php
/**
 * RegistryKeyStore — host normalization + the resolution precedence that
 * decides which license key a registry endpoint sees. No ee() is defined in
 * the test bootstrap, so the config layer is always empty here; we exercise
 * the file and env layers and their interaction.
 *
 *   php tests/test-registry-keys.php
 */

require __DIR__ . '/_bootstrap.php';

use Nivoli\AddonExpert\Service\RegistryKeyStore;

function keystore(): RegistryKeyStore
{
    return new RegistryKeyStore(SYSPATH . 'user/config/keys_' . bin2hex(random_bytes(4)) . '.json');
}

// Start from a clean environment regardless of the host shell.
putenv('ADDON_EXPERT_REGISTRY_KEY');

section('hostOf normalization');

check('full https url → host', RegistryKeyStore::hostOf('https://reg.example.com/releases') === 'reg.example.com');
check('url with port → host only', RegistryKeyStore::hostOf('https://reg.example.com:8443/x') === 'reg.example.com');
check('bare host lowercased', RegistryKeyStore::hostOf('Reg.Example.COM') === 'reg.example.com');
check('empty → empty', RegistryKeyStore::hostOf('') === '');

section('file layer: per-host + default');

$k = keystore();
check('no key initially', $k->keyForHost('reg.example.com') === '');
check('hasKeyFor false initially', $k->hasKeyFor('reg.example.com') === false);

$k->save('reg.example.com', 'KEY-HOST');
check('per-host saved + read', $k->keyForHost('reg.example.com') === 'KEY-HOST');
check('hasKeyFor true after save', $k->hasKeyFor('reg.example.com') === true);
check('keyForUrl resolves via host', $k->keyForUrl('https://reg.example.com/releases') === 'KEY-HOST');
[$eff, $src] = $k->resolve('reg.example.com');
check('source reported as file', $src === 'file' && $eff === 'KEY-HOST');

$k->setDefault('KEY-DEFAULT');
check('unknown host falls back to default', $k->keyForHost('other.example.com') === 'KEY-DEFAULT');
check('per-host still wins over default', $k->keyForHost('reg.example.com') === 'KEY-HOST');

section('persistence across instances');

$file = SYSPATH . 'user/config/keys_persist.json';
$a = new RegistryKeyStore($file);
$a->save('v.example', 'PERSIST');
$b = new RegistryKeyStore($file);
check('reload sees saved key', $b->keyForHost('v.example') === 'PERSIST');

section('forget + saveAll (full replace)');

$k2 = keystore();
$k2->save('a.example', 'A');
$k2->save('b.example', 'B');
$k2->forget('a.example');
check('forget removes a', $k2->keyForHost('a.example') === '');
check('b remains', $k2->keyForHost('b.example') === 'B');

$k2->saveAll([
    'c.example' => 'C',
    RegistryKeyStore::DEFAULT_HOST => 'DEF',
    'd.example' => '',   // empty → dropped
]);
$stored = $k2->stored();
check('saveAll sets c', $k2->keyForHost('c.example') === 'C');
check('saveAll drops empty d (not stored)', ! array_key_exists('d.example', $stored));
check('saveAll replaced the layer (b not stored)', ! array_key_exists('b.example', $stored));
// keyForHost falls back to the default we just set — that's expected.
check('unknown host resolves to default', $k2->keyForHost('zzz.example') === 'DEF');
check('host without per-host key uses default too', $k2->keyForHost('b.example') === 'DEF');

section('env default + precedence');

putenv('ADDON_EXPERT_REGISTRY_KEY=ENV-KEY');
$k3 = keystore();
check('env provides default when no file', $k3->keyForHost('any.example') === 'ENV-KEY');
[, $s3] = $k3->resolve('any.example');
check('source reported as env', $s3 === 'env');

$k3->save('specific.example', 'FILE-SPECIFIC');
check('file per-host beats env default', $k3->keyForHost('specific.example') === 'FILE-SPECIFIC');

$k3->setDefault('FILE-DEFAULT');
check('env default beats file default', $k3->keyForHost('any.example') === 'ENV-KEY');

putenv('ADDON_EXPERT_REGISTRY_KEY'); // unset — env is read live
check('after env unset, file default applies', $k3->keyForHost('any.example') === 'FILE-DEFAULT');

done();
