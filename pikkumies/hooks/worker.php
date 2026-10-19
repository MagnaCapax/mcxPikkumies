<?php
/**
 * The interval hook units' worker (ADR-0067): `php worker.php <category>/<name> <sha1 of session_id>`, started
 * detached by the orchestrator (lib/HookAsync.php kick) and bounded by `timeout`. It takes the unit's run lock and one
 * of the account's worker slots, never waiting (busy: the unit is tried again in 30 seconds); clears the unit's wake
 * flag; gives the unit, on stdin, the session's records since its last run:
 *   {"unit", "session", "interval", "now", "first_run", "scheduled", "records": [...], "state": {...}[, "dropped": N]}
 * runs it with its own timeout (lib/HookSpawn.php), and keeps what it printed: a context or notice for the agent
 * (lib/HookPending.php), its state for the next run, and the cursor. {"skip": true} keeps the cursor, so the same
 * records come again with the next ones, and lets the next record of its wake kinds start it at once. One line per
 * run goes to the hook log (event "async"). Only a valid shipped interval unit that is on is run, and only for a
 * well-formed session hash.
 */
require_once dirname(__DIR__) . '/lib/Config.php';
require_once dirname(__DIR__) . '/lib/HookPending.php';
require_once dirname(__DIR__) . '/lib/HookRun.php';
require_once dirname(__DIR__) . '/lib/HookSpawn.php';

$id = isset($argv[1]) ? (string) $argv[1] : '';
$hash = isset($argv[2]) ? (string) $argv[2] : '';
$units = preg_match('/^[0-9a-f]{40}\z/', $hash) ? HookInterval::on(Config::load()) : [];
if (!isset($units[$id])) {
    exit(0);
}
$u = $units[$id];
$local = Config::localDir();
umask(0077);
$lock = HookRun::beginRun($local, $u, $hash);
if ($lock === false) {
    HookRun::again($local, $u, $hash); // another run (or a synchronous caller) has it
    HookLog::add('async', $id, 0, 'async-busy');
    exit(0);
}
$slot = HookRun::slot($local);
if ($slot === false) {
    flock($lock, LOCK_UN);
    fclose($lock);
    HookRun::again($local, $u, $hash);
    HookLog::add('async', $id, 0, 'async-slots-busy');
    exit(0);
}
// The flag goes only now, with the run and a slot held; a record arriving from here on sets it again.
@unlink(HookAsync::path($local, $u, $hash, '.wake'));
$cursor = HookRun::cursor($local, $u, $hash);
list($records, $last, $dropped) = HookSpool::read($local, $hash, $cursor);
$start = microtime(true);
$outcome = 'async-empty';
$state = null;
if ($records) {
    $in = ['unit' => $id, 'session' => $hash, 'interval' => $u['every'], 'now' => gmdate('Y-m-d\TH:i:s\Z'),
        'first_run' => HookRun::firstRun($local, $u, $hash), 'scheduled' => !empty($records[count($records) - 1]['scheduled']),
        'records' => $records, 'state' => (object) HookRun::state($local, $u, $hash)];
    if ($dropped > 0) {
        $in['dropped'] = $dropped;
    }
    $run = HookSpawn::run($u['script'], (string) json_encode($in, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $u['timeout']);
    $out = json_decode(trim($run['stdout']), true);
    $outcome = $run['timedOut'] ? 'async-timeout' : ($run['error'] !== '' || $run['code'] !== 0 ? 'async-error' : (is_array($out) || trim($run['stdout']) === '' ? 'async-ran' : 'async-bad-output'));
    if (is_array($out) && !empty($out['skip']) && $outcome === 'async-ran') {
        $outcome = 'async-skip'; // the cursor is not written: the same records come again
        HookRun::again($local, $u, $hash, 0);
    } elseif (is_array($out) && $outcome === 'async-ran') {
        $state = HookPending::store($local, $u, $hash, $out);
    }
}
if ($outcome === 'async-skip') {
    flock($lock, LOCK_UN);
    fclose($lock);
} else {
    HookRun::endRun($local, $u, $hash, $lock, $last, $state);
}
flock($slot, LOCK_UN);
fclose($slot);
HookLog::add('async', $id, (int) round((microtime(true) - $start) * 1000), $outcome);
