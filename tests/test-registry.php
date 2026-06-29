<?php
/**
 * RegistryReleaseChecker (the `registry:` update source client) + the
 * registry manifest-source parsing. The HTTP transport is injected, so the
 * parse / cache / sentinel / error paths are exercised against canned
 * worker responses — no live endpoint needed.
 *
 *   php tests/test-registry.php
 */

require __DIR__ . '/_bootstrap.php';

use Nivoli\AddonExpert\Service\RegistryReleaseChecker;
use Nivoli\AddonExpert\Service\UpdateSourceRegistry;

$endpoint = 'https://reg.example/releases';
$product  = 'cf_image';
$key      = 'cfimg-abc123';

// A fake HTTP transport: returns whatever [code, body] we queue, and counts
// calls so we can assert caching behaviour.
function fake_http(array $responses, ?int &$calls): callable
{
    $i = 0;
    return function ($url, $jsonBody, $timeout) use ($responses, &$i, &$calls) {
        $calls = ($calls ?? 0) + 1;
        $r = $responses[min($i, count($responses) - 1)];
        $i++;
        return $r;
    };
}

function checker(callable $http): RegistryReleaseChecker
{
    $dir = SYSPATH . 'user/cache/reg_' . bin2hex(random_bytes(4));
    return new RegistryReleaseChecker($dir, $http);
}

$ok = json_encode([
    'ok' => true, 'product' => 'cf_image', 'version' => '1.11.0', 'current' => false,
    'notes' => 'changelog', 'url' => 'https://reg.example/releases/download?t=abc',
    'sha256' => 'DEADBEEF', 'size' => 12345,
]);

section('successful manifest');

$calls = 0;
$c = checker(fake_http([[200, $ok]], $calls));
$m = $c->refresh($endpoint, $product, $key, '1.10.0');
check('parses version (v-stripped)', $m && $m['version'] === '1.11.0');
check('carries download_url', $m && $m['download_url'] === 'https://reg.example/releases/download?t=abc');
check('sha256 lowercased', $m && $m['sha256'] === 'deadbeef');
check('size int', $m && $m['size'] === 12345);

section('caching + sentinel');

$calls = 0;
$c = checker(fake_http([[200, $ok]], $calls));
$c->refresh($endpoint, $product, $key);
$first = $calls;
$c->latest($endpoint, $product, $key);   // fresh cache → must NOT hit http again
check('latest() served from cache (no second HTTP call)', $calls === $first && $first === 1);

$calls = 0;
$c = checker(fake_http([[403, json_encode(['ok' => false, 'reason' => 'not_entitled'])]], $calls));
check('not_entitled (403) → null', $c->refresh($endpoint, $product, $key) === null);
check('failure cached as sentinel → cached() null', $c->cached($endpoint, $product) === null);
$c->latest($endpoint, $product, $key);   // within TTL, even a sentinel
check('sentinel respected within TTL (no re-hit)', $calls === 1);

section('error + malformed responses → null');

foreach ([
    'invalid_key (401)'   => [401, json_encode(['ok' => false, 'reason' => 'invalid_key'])],
    'expired (403)'       => [403, json_encode(['ok' => false, 'reason' => 'expired'])],
    'not_configured (503)'=> [503, json_encode(['ok' => false, 'reason' => 'not_configured'])],
    'ok=false at 200'     => [200, json_encode(['ok' => false])],
    'missing version'     => [200, json_encode(['ok' => true, 'url' => 'x'])],
    'missing url'         => [200, json_encode(['ok' => true, 'version' => '1.0'])],
    'malformed json'      => [200, '{not json'],
    'empty body'          => [200, ''],
    'transport failure'   => [0, null],
] as $label => $resp) {
    $cnt = 0;
    $c = checker(fake_http([$resp], $cnt));
    check($label . ' → null', $c->refresh($endpoint, $product, $key) === null);
}

section('lastError reason capture');

$n = 0;
$c = checker(fake_http([[401, json_encode(['ok' => false, 'reason' => 'invalid_key'])]], $n));
$c->refresh($endpoint, $product, $key);
check('401 → lastError invalid_key + code', ($c->lastError()['reason'] ?? '') === 'invalid_key' && ($c->lastError()['code'] ?? 0) === 401);

$c = checker(fake_http([[403, json_encode(['ok' => false, 'reason' => 'not_entitled'])]], $n));
$c->refresh($endpoint, $product, $key);
check('403 → not_entitled (from body)', ($c->lastError()['reason'] ?? '') === 'not_entitled');

$c = checker(fake_http([[403, json_encode(['ok' => false])]], $n));
$c->refresh($endpoint, $product, $key);
check('403 w/o body reason → mapped not_entitled', ($c->lastError()['reason'] ?? '') === 'not_entitled');

$c = checker(fake_http([[404, json_encode(['ok' => false])]], $n));
$c->refresh($endpoint, $product, $key);
check('404 → unknown_product', ($c->lastError()['reason'] ?? '') === 'unknown_product');

$c = checker(fake_http([[0, null]], $n));
$c->refresh($endpoint, $product, $key);
check('transport failure → unreachable', ($c->lastError()['reason'] ?? '') === 'unreachable');

$c = checker(fake_http([[200, $ok]], $n));
$c->refresh($endpoint, $product, $key);
check('success → lastError null', $c->lastError() === null);

section('endpoint validation');

check('https endpoint valid', RegistryReleaseChecker::isValidEndpoint('https://x.y/releases'));
check('http endpoint rejected', ! RegistryReleaseChecker::isValidEndpoint('http://x.y/releases'));
check('garbage rejected', ! RegistryReleaseChecker::isValidEndpoint('not a url'));

section('registry manifest source parsing');

$addons = SYSPATH . 'user/addons';
@mkdir($addons . '/reg_addon', 0775, true);
file_put_contents($addons . '/reg_addon/addon.setup.php',
    "<?php return ['name' => 'Reg', 'registry' => ['url' => 'https://reg.example/releases', 'product' => 'Cf-Image!!']];");
@mkdir($addons . '/gh_addon', 0775, true);
file_put_contents($addons . '/gh_addon/addon.setup.php',
    "<?php return ['name' => 'Gh', 'github_repo' => 'owner/repo'];");

$reg = new UpdateSourceRegistry(SYSPATH . 'user/config/map.json', $addons);
$r = $reg->resolve('reg_addon');
check('registry manifest → type registry', $r && $r['type'] === 'registry');
check('registry url parsed', $r && $r['url'] === 'https://reg.example/releases');
check('product slug normalized to [a-z0-9_]', $r && $r['product'] === 'cfimage');
$g = $reg->resolve('gh_addon');
check('github manifest still → type github + repo', $g && $g['type'] === 'github' && $g['repo'] === 'owner/repo');

section('admin-map source mapping (github + registry)');

$mapFile = SYSPATH . 'user/config/admin_map_' . bin2hex(random_bytes(3)) . '.json';
$emptyAddons = SYSPATH . 'user/addons_empty';
@mkdir($emptyAddons, 0775, true);
$am = new UpdateSourceRegistry($mapFile, $emptyAddons);

$am->saveAll([
    'gh_one'   => 'owner/repo',
    'reg_one'  => ['type' => 'registry', 'url' => 'https://reg.example/releases', 'product' => 'Cf-Image!!'],
    'bad_repo' => 'not a repo',                                              // invalid → dropped
    'bad_reg'  => ['type' => 'registry', 'url' => 'http://x', 'product' => 'p'], // http → dropped
    'blank'    => '',                                                        // dropped
]);

$g1 = $am->resolve('gh_one');
check('admin github → github + repo + source admin', $g1 && $g1['type'] === 'github' && $g1['repo'] === 'owner/repo' && $g1['source'] === UpdateSourceRegistry::SOURCE_ADMIN);
$r1 = $am->resolve('reg_one');
check('admin registry → registry + url', $r1 && $r1['type'] === 'registry' && $r1['url'] === 'https://reg.example/releases');
check('admin registry product normalized', $r1 && $r1['product'] === 'cfimage');
check('admin registry source = admin', $r1 && $r1['source'] === UpdateSourceRegistry::SOURCE_ADMIN);
check('invalid repo dropped', $am->resolve('bad_repo') === null);
check('http registry dropped', $am->resolve('bad_reg') === null);
check('blank dropped', $am->resolve('blank') === null);

$am2 = new UpdateSourceRegistry($mapFile, $emptyAddons);
check('github persists across reload', ($am2->resolve('gh_one')['repo'] ?? '') === 'owner/repo');
check('registry persists across reload', ($am2->resolve('reg_one')['type'] ?? '') === 'registry');

$onDisk = json_decode((string) file_get_contents($mapFile), true);
check('github stored as plain string (back-compat)', isset($onDisk['gh_one']) && is_string($onDisk['gh_one']));
check('registry stored as array', isset($onDisk['reg_one']) && is_array($onDisk['reg_one']));

section('manifest precedence over admin map');

// gh_addon (from the manifest section above) declares github_repo in its
// setup.php; an admin registry entry must NOT override it.
$precFile = SYSPATH . 'user/config/admin_map_prec.json';
$prec = new UpdateSourceRegistry($precFile, $addons);
$prec->saveAll(['gh_addon' => ['type' => 'registry', 'url' => 'https://other.example/releases', 'product' => 'x']]);
$pr = $prec->resolve('gh_addon');
check('manifest wins over admin entry', $pr && $pr['type'] === 'github' && $pr['source'] === UpdateSourceRegistry::SOURCE_MANIFEST);

done();
