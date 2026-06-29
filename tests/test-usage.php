<?php
/**
 * UsageScanner — the pre-removal "is this add-on used anywhere?" scan. The DB
 * access is injected (a probe), so the validation + assembly + breakage-flag
 * logic is exercised without a live EE.
 *
 *   php tests/test-usage.php
 */

require __DIR__ . '/_bootstrap.php';

use Nivoli\AddonExpert\Service\UsageScanner;

function probe(array $map): callable
{
    return function ($kind, $short) use ($map) {
        return $map[$kind] ?? ['count' => 0, 'names' => []];
    };
}

section('short validation + tag needle');

check('valid short', UsageScanner::isValidShort('cf_image'));
check('hyphen rejected', ! UsageScanner::isValidShort('cf-image'));
check('dot rejected', ! UsageScanner::isValidShort('a.b'));
check('tag needle', UsageScanner::tagNeedle('cf_image') === '{exp:cf_image:');

section('scan with usage (templates + fields)');

$r = (new UsageScanner(probe([
    'templates' => ['count' => 3, 'names' => ['news/index', 'news/_entry', 'layouts/.default']],
    'fields'    => ['count' => 1, 'names' => ['Hero image']],
])))->scan('cf_image');

check('valid', $r['valid'] === true);
check('has_usage true', $r['has_usage'] === true);
check('templates count', $r['templates']['count'] === 3);
check('templates first name', ($r['templates']['names'][0] ?? '') === 'news/index');
check('fields count', $r['fields']['count'] === 1);
check('snippets default zero', $r['snippets']['count'] === 0);

section('extensions alone is informational');

$r2 = (new UsageScanner(probe(['extensions' => ['count' => 2, 'names' => ['Cf_image_ext']]])))->scan('cf_image');
check('extensions counted', $r2['extensions']['count'] === 2);
check('has_usage false when only extensions', $r2['has_usage'] === false);

section('no usage at all');

$r3 = (new UsageScanner(probe([])))->scan('cf_image');
check('has_usage false', $r3['has_usage'] === false);
check('valid true', $r3['valid'] === true);

section('invalid short short-circuits (probe not consulted)');

$r4 = (new UsageScanner(probe(['templates' => ['count' => 5, 'names' => ['x']]])))->scan('bad-name');
check('valid false', $r4['valid'] === false);
check('has_usage false', $r4['has_usage'] === false);
check('templates zero (not probed)', $r4['templates']['count'] === 0);

done();
