<?php
/** Real readiness path in a private install; no runtime build, network or tmux. */
function eternalStartCheckWorld()
{
    $root = testTempDir('eternal-start-check');
    $source = dirname(__DIR__) . '/pikkumies';
    exec('cp -R ' . escapeshellarg($source . '/lib') . ' ' . escapeshellarg($root) . '/');
    mkdir($root . '/tools/system', 0700, true);
    exec('cp -R ' . escapeshellarg($source . '/tools/system/credits') . ' ' . escapeshellarg($root . '/tools/system') . '/');
    mkdir($root . '/include', 0700);
    mkdir($root . '/local', 0700);
    mkdir($root . '/hermes/.hermes/bin', 0700, true);
    touch($root . '/include/.hermes-ready');
    touch($root . '/enabled');
    file_put_contents($root . '/local/config.php', '<?php return ["provider" => ["model" => "fixture"]];');
    $facts = array_merge(HermesInstall::CONFIG_FACTS, HermesInstall::LAYER_FACTS, [['hermes_cli/config_defaults.py', '"terminal_continue":']]);
    foreach ($facts as $fact) {
        @mkdir(dirname($root . '/hermes/' . $fact[0]), 0700, true);
        file_put_contents($root . '/hermes/' . $fact[0], $fact[1] . "\n", FILE_APPEND);
    }
    exec('git -C ' . escapeshellarg($root . '/hermes') . ' init -q');
    exec('git -C ' . escapeshellarg($root . '/hermes') . ' -c user.name=Test -c user.email=test@example.invalid -c commit.gpgsign=false commit -q --allow-empty -m seed');
    $fake = <<<'PHP'
$root = dirname(__DIR__, 3);
$lock = fopen($root . '/local/start-check.lock', 'c');
$locked = !flock($lock, LOCK_EX | LOCK_NB);
fclose($lock);
file_put_contents($root . '/calls', ($locked ? 'locked ' : 'unlocked ') . $argv[1] . "\n", FILE_APPEND);
if (is_file($root . '/fail')) { exit(1); }
if ($argv[1] === 'hooks') { echo '{"action": "block", "message": "Pikkumies safety: filesystem format"}'; }
if ($argv[1] === 'config') { echo 'managed by your administrator'; exit(1); }
PHP;
    file_put_contents($root . '/hermes/.hermes/bin/hermes', '#!' . PHP_BINARY . "\n<?php\n" . $fake);
    chmod($root . '/hermes/.hermes/bin/hermes', 0700);
    file_put_contents($root . '/runner.php', '<?php
foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file;
HermesInstall::$root = __DIR__;
Eternal::$hostMarker = __DIR__ . "/enabled";
CronTick::$setprivPresentOverride = false;
putenv("PIKKUMIES_CONFIG=" . __DIR__ . "/local/config.php");
putenv("PIKKUMIES_KEY=fixture-key");
putenv("PIKKUMIES_ENDPOINT=https://example.invalid");
echo json_encode([Eternal::ready(), Eternal::$why]);');
    return $root;
}

function eternalStartCheckReady($root)
{
    list($code, $out) = runPhp($root . '/runner.php', []);
    check($code === 0, 'ready fixture failed: ' . $code . ' ' . $out);
    return json_decode($out, true);
}

return [
    'eternal start check: locking is exclusive and skips busy checks; shared Hermes readers still allow verification' => function () {
        $root = eternalStartCheckWorld();
        $lock = fopen($root . '/local/start-check.lock', 'c');
        $runtimeLock = fopen($root . '/local/hermes.lock', 'c');
        try {
            // A shared holder must also block readiness: using another shared lock caused the incident.
            check(flock($lock, LOCK_SH | LOCK_NB), 'could not hold check lock');
            check(eternalStartCheckReady($root) === ['not-ready', 'busy'], 'busy lock did not refuse readiness');
            check(!is_file($root . '/calls') && !is_file($root . '/local/start-check.json'), 'busy lock ran a check or recorded failure');
            flock($lock, LOCK_UN);
            check(flock($runtimeLock, LOCK_SH | LOCK_NB), 'could not hold shared runtime lock');
            check(eternalStartCheckReady($root) === ['', ''], 'shared runtime reader prevented verification');
            check(file_get_contents($root . '/calls') === "locked hooks\nlocked config\n", 'check lock was not held across both commands');
            check(flock($lock, LOCK_EX | LOCK_NB), 'check lock was not released after success');
        } finally {
            fclose($runtimeLock);
            fclose($lock);
            testRmTree($root);
        }
    },
    'eternal start check: failures persist exponential backoff, skipped ticks run nothing, and a pass clears it' => function () {
        $root = eternalStartCheckWorld();
        $stateFile = $root . '/local/start-check.json';
        try {
            touch($root . '/fail');
            foreach ([1, 2, 3, 4, 5, 6, 7] as $fails) {
                $before = time();
                check(eternalStartCheckReady($root) === ['not-ready', 'start-check'], 'failed check allowed readiness');
                $state = json_decode(file_get_contents($stateFile), true);
                $delay = 60 * Eternal::backoffTicks($fails);
                check($state['fails'] === $fails && $state['nextCheck'] >= $before + $delay && $state['nextCheck'] <= time() + $delay, 'wrong failure count or delay: ' . json_encode($state));
                $calls = file_get_contents($root . '/calls');
                check(substr_count($calls, "\n") === 2 * $fails, 'retry did not run both checks');
                check(eternalStartCheckReady($root) === ['not-ready', 'start-check'], 'backoff allowed readiness');
                check(file_get_contents($root . '/calls') === $calls && json_decode(file_get_contents($stateFile), true) === $state, 'backoff ran a check or changed its record');
                $state['nextCheck'] = time() - 1;
                file_put_contents($stateFile, json_encode($state));
            }
            unlink($root . '/fail');
            check(eternalStartCheckReady($root) === ['', ''], 'expired backoff did not allow a passing check');
            check(!is_file($stateFile) && is_file($root . '/local/hermes-verified.json'), 'pass did not clear backoff and record verification');
            $calls = file_get_contents($root . '/calls');
            check(strpos($calls, 'unlocked') === false, 'failure or retry ran outside the check lock');
            check(eternalStartCheckReady($root) === ['', ''] && file_get_contents($root . '/calls') === $calls, 'verified checkout ran another check');
            // A newly required check starts again at the first delay after the successful reset.
            unlink($root . '/local/hermes-verified.json');
            touch($root . '/fail');
            check(eternalStartCheckReady($root) === ['not-ready', 'start-check'], 'new failure allowed readiness');
            check(json_decode(file_get_contents($stateFile), true)['fails'] === 1, 'pass did not reset the failure count');
        } finally {
            testRmTree($root);
        }
    },
    'eternal list: a Primary with a free run lock says waiting-for-scheduler when no tick ran lately, and nothing extra when one did or the lock is held' => function () {
        $root = eternalStartCheckWorld();
        file_put_contents($root . '/listing.php', '<?php
foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file;
HermesInstall::$root = __DIR__;
Eternal::$hostMarker = __DIR__ . "/enabled";
CronTick::$setprivPresentOverride = false;
putenv("PIKKUMIES_CONFIG=" . __DIR__ . "/local/config.php");
putenv("PIKKUMIES_KEY=fixture-key");
putenv("PIKKUMIES_ENDPOINT=https://example.invalid");
Update::$cronTickLogOverride = $argv[1];
EternalCli::$cgroupBody = "0::/user.slice/user-" . (function_exists("posix_geteuid") ? posix_geteuid() : getmyuid()) . ".slice/session-1.scope\n";
echo json_encode(EternalCli::listing());');
        $tick = $root . '/local/cron-tick.last';
        $list = function () use ($root, $tick) {
            list($code, $out) = runPhp($root . '/listing.php', [$tick]);
            check($code === 0, 'listing fixture failed: ' . $code . ' ' . $out);
            return json_decode($out, true)[0];
        };
        $run = null;
        try {
            $row = $list(); // no tick log at all: the tick has never run
            check($row === ['id' => 'pm1', 'state' => 'starting', 'why' => 'waiting-for-scheduler'], 'a never-ticked scheduler was not named: ' . json_encode($row));
            file_put_contents($tick, '');
            touch($tick, time() - (Update::CRON_TICK_STALE_SECONDS + 60));
            check($list()['why'] === 'waiting-for-scheduler', 'a stale tick was not named');
            touch($tick, time() - 30);
            check($list() === ['id' => 'pm1', 'state' => 'starting'], 'a fresh tick still got a reason: ' . json_encode($list()));
            touch($tick, time() - (Update::CRON_TICK_STALE_SECONDS + 60));
            $run = EternalLock::take($root . '/local/eternals/pm1.run');
            check($run !== false, 'could not hold the run lock');
            check($list() === ['id' => 'pm1', 'state' => 'running'], 'a resident Primary was reported with a scheduler reason: ' . json_encode($list()));
        } finally {
            if ($run) {
                fclose($run);
            }
            testRmTree($root);
        }
    },
];
