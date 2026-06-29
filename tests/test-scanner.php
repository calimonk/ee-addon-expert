<?php
/**
 * CompatibilityScanner — the heuristic feature scan behind "is it actually
 * safe to force this onto an older PHP?". Must catch newer-than-target
 * syntax/functions and must NOT cry wolf on method calls / array keys.
 *
 *   php tests/test-scanner.php
 */

require __DIR__ . '/_bootstrap.php';

use Nivoli\AddonExpert\Service\CompatibilityScanner;

$s = new CompatibilityScanner();

$files = [
    'uses_json_validate.php'  => '<?php if (json_validate($x)) { echo "ok"; }',
    'uses_typed_const.php'    => '<?php class A { const int MAX = 5; }',
    'uses_dyn_const.php'      => '<?php $v = Foo::{$bar};',
    'uses_override.php'       => "<?php class B extends A { #[\\Override] public function f() {} }",
    'clean_match_82.php'      => '<?php $x = match($y) { 1 => "a", default => "b" };',   // 8.0, fine on 8.2
    'clean_readonly_81.php'   => '<?php class C { public readonly int $id; }',            // 8.1, fine on 8.2
    'bait.php'                => '<?php $r = $obj->match($a); $z = preg_match("/x/", $s); $cfg = ["readonly" => true];',
];

section('target 8.2');

$res = $s->scanFiles($files, '8.2');
$feats = array_column($res['above_target'], 'feature');
check('verdict is risk', $res['verdict'] === 'risk');
check('detects json_validate()', in_array('json_validate()', $feats, true));
check('detects typed class constant', in_array('typed class constant', $feats, true));
check('detects dynamic class constant fetch', in_array('dynamic class constant fetch', $feats, true));
check('detects #[Override]', in_array('#[Override] attribute', $feats, true));
check('max_required is 8.3', $res['max_required'] === '8.3');
check('match() NOT flagged above 8.2', ! in_array('match expression', $feats, true));
check('readonly NOT flagged above 8.2', ! in_array('readonly property', $feats, true));

// False-positive guard: nothing from the bait file should appear anywhere.
$baitFindings = array_filter($res['all'], fn($f) => strpos($f['file'], 'bait') !== false);
check('no false positives (->match / preg_match / array key)', count($baitFindings) === 0);

section('target 8.3');

$res83 = $s->scanFiles($files, '8.3');
check('on 8.3 target → verdict clear', $res83['verdict'] === 'clear');

section('summary text');

check('clear summary mentions "appears safe"', stripos($s->scanFiles(['x.php' => '<?php echo 1;'], '8.2')['summary'], 'appears safe') !== false);

done();
