<?php
/**
 * Run every tests/test-*.php in its own PHP process, aggregate, and exit
 * non-zero if any suite failed. This is the "don't break anything" gate —
 * run it before tagging a release (and CI runs it on every push).
 *
 *   php tests/run.php
 */

$dir = __DIR__;
$tests = glob($dir . '/test-*.php');
sort($tests);

$failed = [];
$phpBin = PHP_BINARY ?: 'php';

foreach ($tests as $test) {
    $name = basename($test);
    echo "\n=== " . $name . " ===\n";
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($test);
    passthru($cmd, $code);
    if ($code !== 0) {
        $failed[] = $name;
    }
}

echo "\n========================================\n";
if (empty($failed)) {
    echo "ALL SUITES PASSED (" . count($tests) . ")\n";
    exit(0);
}
echo count($failed) . " of " . count($tests) . " suites FAILED: " . implode(', ', $failed) . "\n";
exit(1);
