<?php
/**
 * Standalone test bootstrap for Addon Expert's service layer. No EE, no
 * PHPUnit — plain PHP, matching the ee-cf-image / wp-cf-image convention.
 *
 *   php tests/test-<name>.php     # one suite
 *   php tests/run.php             # the whole suite
 *
 * The services are written to run without a full EE bootstrap: `ee()` calls
 * are guarded by function_exists('ee'), and file paths come from the SYSPATH
 * constant or constructor injection. We define SYSPATH to a throwaway temp
 * tree so every run gets an isolated EE-like filesystem root, and we do NOT
 * define ee() — so the services take their no-EE fallback paths.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (! defined('APP_VER')) {
    define('APP_VER', '7.4.0'); // pretend EE 7.4 for requires/version checks
}

if (! defined('SYSPATH')) {
    $sys = sys_get_temp_dir() . '/ae_test_' . bin2hex(random_bytes(4)) . '/';
    @mkdir($sys . 'user/config', 0775, true);
    @mkdir($sys . 'user/cache', 0775, true);
    @mkdir($sys . 'user/addons', 0775, true);
    define('SYSPATH', $sys);
    register_shutdown_function(static function () {
        if (defined('SYSPATH') && is_dir(SYSPATH)) {
            @exec('rm -rf ' . escapeshellarg(SYSPATH));
        }
    });
}

// Load every service class.
foreach (glob(dirname(__DIR__) . '/Service/*.php') as $svc) {
    require_once $svc;
}

$GLOBALS['__ae_pass'] = 0;
$GLOBALS['__ae_fail'] = 0;

function section(string $name): void
{
    echo "\n" . $name . "\n";
}

function check(string $name, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['__ae_pass']++;
        echo "  ok    " . $name . "\n";
    } else {
        $GLOBALS['__ae_fail']++;
        echo "  FAIL  " . $name . ($detail !== '' ? " — " . $detail : '') . "\n";
    }
}

/** Print the tally and exit non-zero if anything failed. */
function done(): void
{
    $p = $GLOBALS['__ae_pass'];
    $f = $GLOBALS['__ae_fail'];
    echo "\n" . $p . " passed, " . $f . " failed\n";
    exit($f === 0 ? 0 : 1);
}

/** Build a zip on disk from [entryName => contents]; null contents = dir. */
function make_zip(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'ae_zip_');
    @unlink($path);
    $path .= '.zip';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('could not create test zip');
    }
    foreach ($entries as $name => $contents) {
        if ($contents === null || substr($name, -1) === '/') {
            $zip->addEmptyDir(rtrim($name, '/'));
        } else {
            $zip->addFromString($name, $contents);
        }
    }
    $zip->close();
    return $path;
}
