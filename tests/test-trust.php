<?php
/**
 * TrustStore::compare — the TOFU supply-chain gate. Verifies that a real
 * ownership transfer / RepoJacking trips the check, while a benign username
 * rename does not.
 *
 *   php tests/test-trust.php
 */

require __DIR__ . '/_bootstrap.php';

use Nivoli\AddonExpert\Service\TrustStore;

$repo = 'calimonk/ee-edge-cache-tags';
$pinned = [
    'owner_id'    => 12345,
    'owner_login' => 'calimonk',
    'repo_id'     => 99999,
    'created_at'  => '2024-01-01T00:00:00Z',
    'full_name'   => $repo,
];

function fresh_store(): TrustStore
{
    $f = SYSPATH . 'user/config/trust_' . bin2hex(random_bytes(4)) . '.json';
    return new TrustStore($f);
}

section('compare states');

$s = fresh_store();
$c = $s->compare($repo, null);
check('no pin + no observed → unverified', $c['state'] === TrustStore::STATE_UNVERIFIED);

$s = fresh_store();
$c = $s->compare($repo, $pinned);
check('no pin + observed → unverified (first sight)', $c['state'] === TrustStore::STATE_UNVERIFIED);

$s = fresh_store();
$s->pin($repo, $pinned, 'tester');
$c = $s->compare($repo, $pinned);
check('pinned + identical → trusted', $c['state'] === TrustStore::STATE_TRUSTED && empty($c['diff']));

$s = fresh_store();
$s->pin($repo, $pinned, 'tester');
$c = $s->compare($repo, array_merge($pinned, ['owner_id' => 67890, 'owner_login' => 'attacker']));
check('owner_id changed → CHANGED (ownership transfer)', $c['state'] === TrustStore::STATE_CHANGED && isset($c['diff']['owner_id']));

$s = fresh_store();
$s->pin($repo, $pinned, 'tester');
$c = $s->compare($repo, array_merge($pinned, ['repo_id' => 88888]));
check('repo_id changed → CHANGED (delete+recreate)', $c['state'] === TrustStore::STATE_CHANGED && isset($c['diff']['repo_id']));

$s = fresh_store();
$s->pin($repo, $pinned, 'tester');
$c = $s->compare($repo, array_merge($pinned, ['created_at' => '2026-06-01T00:00:00Z']));
check('created_at changed → CHANGED', $c['state'] === TrustStore::STATE_CHANGED && isset($c['diff']['created_at']));

$s = fresh_store();
$s->pin($repo, $pinned, 'tester');
$c = $s->compare($repo, array_merge($pinned, ['owner_id' => 1, 'repo_id' => 2]));
$df = array_keys($c['diff']);
sort($df);
check('multiple fields changed → all listed', $c['state'] === TrustStore::STATE_CHANGED && $df === ['owner_id', 'repo_id']);

$s = fresh_store();
$s->pin($repo, $pinned, 'tester');
$c = $s->compare($repo, array_merge($pinned, ['owner_login' => 'calimonk-renamed']));
check('owner_login rename only → still trusted (id is stable)', $c['state'] === TrustStore::STATE_TRUSTED && empty($c['diff']));

section('pin persistence');

$f = SYSPATH . 'user/config/trust_persist.json';
$a = new TrustStore($f);
$a->pin($repo, $pinned, 'tester');
$b = new TrustStore($f); // reload from disk
check('pinned fingerprint survives reload', $b->fingerprint($repo) !== null && (int) $b->fingerprint($repo)['owner_id'] === 12345);
check('first_seen preserved across re-pin', (function () use ($f, $repo, $pinned) {
    $t = new TrustStore($f);
    $first = $t->fingerprint($repo)['first_seen_at'];
    $t->pin($repo, array_merge($pinned, ['owner_id' => 777]), 'tester2');
    return $t->fingerprint($repo)['first_seen_at'] === $first;
})());

done();
