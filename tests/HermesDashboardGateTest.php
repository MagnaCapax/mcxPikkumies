<?php
/**
 * lib/HermesDashboardGate.php and HermesConfig::env() (ADR-0074): `pikkumies run dashboard` refuses to start Hermes'
 * dashboard where Hermes would leave it without a login (a loopback bind and no non-loopback public URL), since on
 * a shared server another account can read the page's session key; and Pikkumies' Hermes keeps its own rendezvous
 * directory. A fake hermes answers `config get` from PK_FAKE_PUBLIC (the env key) and PK_FAKE_CFG (the config key).
 */

/** A fake hermes for the two `config get` reads; an empty answer exits 1 as Hermes does for a key not set. */
function dashGateBin()
{
    $t = testTempDir('pk-dashgate');
    $bin = "$t/fake-hermes";
    file_put_contents($bin, '#!' . PHP_BINARY . "\n<?php\n"
        . 'file_put_contents(' . var_export("$t/fake.log", true) . ', implode(" ", array_slice($argv, 1)) . " | lockdir=" . getenv("HERMES_GATEWAY_LOCK_DIR") . "\n", FILE_APPEND);' . "\n"
        . '$v = getenv($argv[3] === "HERMES_DASHBOARD_PUBLIC_URL" ? "PK_FAKE_PUBLIC" : "PK_FAKE_CFG");' . "\n"
        . 'if ($v === false || $v === "") { fwrite(STDERR, "Config key not set\n"); exit(1); }' . "\n"
        . 'echo json_encode($v), "\n";' . "\n");
    chmod($bin, 0755);
    return ['t' => $t, 'bin' => $bin, 'log' => "$t/fake.log"];
}

/** The refusal for $args with these fake answers ('' = would start). */
function dashGateRefusal(array $w, array $args, $public = '', $cfg = '', array $env = [])
{
    return testWithEnv(array_merge(['PK_FAKE_PUBLIC' => $public, 'PK_FAKE_CFG' => $cfg, 'HERMES_DESKTOP' => null], $env), function () use ($w, $args) {
        return HermesDashboardGate::refusal($args, $w['bin']);
    });
}

return [
    'dashboard gate: a loopback dashboard without a non-loopback public URL is refused, naming the risk and the fix' => function () {
        $w = dashGateBin();
        try {
            foreach ([['dashboard'], ['dashboard', '--port', '45315'], ['dashboard', '--host', '127.0.0.1'], ['dashboard', '--host=localhost'], ['dashboard', '--hos', '::1'],
                ['dashboard', '--host', '0.0.0.0', '--host', '127.0.0.1'], ['dashboard', '--host', '0.0.0.0', '--hos', '127.0.0.1'], ['dashboard', '--host='], ['dashboard', '--skip-build']] as $args) {
                $r = dashGateRefusal($w, $args);
                check(strpos($r, 'other accounts') !== false && strpos($r, 'HERMES_DASHBOARD_PUBLIC_URL') !== false && strpos($r, '--host') !== false, json_encode($args) . ' must be refused: ' . $r);
            }
            foreach (['http://127.0.0.1:45315', 'https://localhost/x', 'http://[::1]:8080', 'https://user@example.com/', 'not a url', 'ftp://example.com/'] as $url) {
                check(dashGateRefusal($w, ['dashboard'], $url) !== '', "a public URL Hermes would not gate on (or this check cannot read) must not open it: $url");
            }
            check(dashGateRefusal($w, ['dashboard'], 'https://example.com/user-u/dash', '', ['HERMES_DESKTOP' => '1']) !== '', 'HERMES_DESKTOP=1 can exempt a loopback bind from the gate');
            check(dashGateRefusal($w, ['dashboard'], 'http://127.0.0.1:45315', 'https://dash.example.org') !== '', 'a well-formed env URL wins over the config even when it is loopback (resolve_public_url)');
            check(dashGateRefusal($w, ['dashboard'], 'http://u@127.0.0.1', 'https://dash.example.org') !== '', 'an env URL Hermes uses but this check cannot read never falls through to the config');
        } finally {
            testRmTree($w['t']);
        }
    },
    'dashboard gate: a non-loopback public URL (env or config, as Hermes resolves it) or a non-loopback bind starts; --stop and --status are never checked' => function () {
        $w = dashGateBin();
        try {
            check(dashGateRefusal($w, ['dashboard', '--host', '127.0.0.1', '--port', '45315'], 'https://example.com/user-u/pikkumies-dashboard') === '', 'public URL from the environment');
            check(dashGateRefusal($w, ['dashboard'], '', 'https://dash.example.org') === '', 'public URL from the effective config');
            check(dashGateRefusal($w, ['dashboard'], 'not a url', 'https://dash.example.org') === '', 'a malformed env URL falls through to the config, as resolve_public_url does');
            check(dashGateRefusal($w, ['dashboard'], 'https://example.net:8443/') === '', 'with a port');
            $log = (string) file_get_contents($w['log']);
            check(strpos($log, 'config get HERMES_DASHBOARD_PUBLIC_URL --json --raw') === 0 && strpos($log, 'config get dashboard.public_url --json --raw') !== false
                && strpos($log, 'lockdir=' . HermesConfig::home() . '/gateway-locks') !== false, 'read through Hermes with Pikkumies\' environment: ' . $log);
            unlink($w['log']);
            foreach ([['dashboard', '--host', '0.0.0.0'], ['dashboard', '--host=192.0.2.10'], ['dashboard', '--stop'], ['dashboard', '--status'], ['chat'], ['serve']] as $args) {
                check(dashGateRefusal($w, $args) === '', json_encode($args) . ' must not be refused');
            }
            check(!is_file($w['log']), 'Hermes gates a non-loopback bind itself, and a non-start needs no read: no Hermes process');
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes env: Pikkumies\' Hermes keeps its rendezvous record in its own directory, apart from a plain Hermes of the same account' => function () {
        $env = HermesConfig::env('/x/local/hermes');
        check(isset($env['HERMES_GATEWAY_LOCK_DIR']) && $env['HERMES_GATEWAY_LOCK_DIR'] === '/x/local/hermes/gateway-locks', json_encode($env));
    },
    'launcher: the dashboard gate runs before the web page build and before the shared Hermes lock' => function () {
        $src = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        $gate = strpos($src, 'HermesDashboardGate::refusal($passArgs');
        $build = strpos($src, '$dash = buildWebUi();');
        $lock = strpos($src, '$hermesLock = HermesInstall::lock($isUpdate);');
        check($gate !== false && $build !== false && $gate < $build && $build < $lock, 'order: gate, build, lock');
        $helper = strpos($src, 'function buildWebUi()');
        check($helper !== false && strpos($src, 'HermesWebUi::build(', $helper) < strpos($src, "\nfunction ", $helper + 1), 'buildWebUi() is the page build');
    },
];
