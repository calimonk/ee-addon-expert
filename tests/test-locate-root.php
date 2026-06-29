<?php
/**
 * ReleaseInstaller::locateAddonRoot — finds the add-on subtree inside a
 * downloaded release zip across the real-world layouts (asset zip, wrapper,
 * GitHub source zipball, monorepo) and refuses to guess when ambiguous.
 * Getting this wrong would extract the wrong subtree over a live add-on.
 *
 *   php tests/test-locate-root.php
 */

require __DIR__ . '/_bootstrap.php';

use Nivoli\AddonExpert\Service\ReleaseInstaller;

$installer = new ReleaseInstaller(SYSPATH . 'user/addons');
$ref = new ReflectionMethod(ReleaseInstaller::class, 'locateAddonRoot');
$ref->setAccessible(true);

function locate(ReflectionMethod $ref, $installer, array $entries, string $short)
{
    $zipPath = make_zip($entries);
    $zip = new ZipArchive();
    $zip->open($zipPath);
    try {
        return $ref->invoke($installer, $zip, $short);
    } finally {
        $zip->close();
        @unlink($zipPath);
    }
}

$short = 'edge_cache_tags';
$setup = '<?php return [];';

section('layouts');

check('standard asset zip', locate($ref, $installer, [
    'edge_cache_tags/' => null,
    'edge_cache_tags/addon.setup.php' => $setup,
], $short) === 'edge_cache_tags/');

check('wrapper folder', locate($ref, $installer, [
    'release-v2.4.13/edge_cache_tags/addon.setup.php' => $setup,
], $short) === 'release-v2.4.13/edge_cache_tags/');

check('github source zipball (single addon)', locate($ref, $installer, [
    'calimonk-ee-edge-cache-tags-abc1234/addon.setup.php' => $setup,
    'calimonk-ee-edge-cache-tags-abc1234/README.md' => '#',
], $short) === 'calimonk-ee-edge-cache-tags-abc1234/');

check('monorepo with explicit folder match', locate($ref, $installer, [
    'monorepo/other_addon/addon.setup.php' => $setup,
    'monorepo/edge_cache_tags/addon.setup.php' => $setup,
], $short) === 'monorepo/edge_cache_tags/');

$threw = false;
try {
    locate($ref, $installer, [
        'monorepo/other_addon/addon.setup.php' => $setup,
        'monorepo/yet_another/addon.setup.php' => $setup,
    ], $short);
} catch (\Throwable $e) {
    $threw = true;
}
check('ambiguous multi-addon, no match → refuses', $threw);

check('macOS noise ignored', locate($ref, $installer, [
    '__MACOSX/edge_cache_tags/._addon.setup.php' => 'noise',
    'edge_cache_tags/addon.setup.php' => $setup,
], $short) === 'edge_cache_tags/');

done();
