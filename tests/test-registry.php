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

done();
