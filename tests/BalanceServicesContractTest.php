<?php
/**
 * Proves BalanceGather's remote argv against the REAL Services/ServeGate contract, not a fake. Per
 * the coordinator's correction (2026-10-20): a fake Services class can pass while the real
 * sshArgv()/ServeGate whitelist would refuse the same words. Services.php and ServeGate.php are
 * owner A's unit (SPEC.md), now landed in this tree; this test copies THIS tree's own real copies
 * into a throwaway fixture for the duration of one subprocess only -- it reads them, it never writes
 * or edits them, and neither file is in this patch (tests/BalanceGatherTest.php's own fake-Services
 * tests are untouched and still the ones that run day to day).
 *
 * The fixture runs as a SEPARATE PHP process (runPhp()), never require()'d into this test runner's
 * own process: Services.php pulls in lib/Cli.php, Config.php, SshLists.php, ToolProc.php by their OWN
 * relative paths, and tests/run.php's own startup now requires every pikkumies/lib/*.php once,
 * Services.php included (it is a lib/ file, now that owner A's unit has landed) -- so the real
 * Services class is ALREADY loaded in this test runner's process. A second require of the same class
 * name from a different path is a fatal "cannot redeclare class" either way; subprocess isolation is
 * the only safe way to load a second Services/ServeGate pair without touching the rest of the suite.
 */

/** Writes the fixture tree (fake service + a stand-in "ssh" that runs the REAL ServeGate) and the driver script. Returns its path. */
function bsctFixture()
{
    $dir = testTempDir('pk-balance-contract');
    $lib = $dir . '/pikkumies/lib';
    $serveDir = $dir . '/pikkumies/tools/seedbox/services';
    $bin = $dir . '/bin';
    $local = $dir . '/local';
    foreach ([$lib, $serveDir, $bin, $local] as $d) {
        mkdir($d, 0700, true);
    }
    // All seven files are THIS repo's own real, currently-checked-out copies -- read-only, into a
    // throwaway temp fixture; none of them is written back, and none is part of this patch.
    foreach (['Cli.php', 'Config.php', 'SshLists.php', 'ToolProc.php', 'Services.php', 'PathName.php', 'Secrets.php'] as $f) {
        copy(dirname(__DIR__) . "/pikkumies/lib/$f", "$lib/$f");
    }
    copy(dirname(__DIR__) . '/pikkumies/tools/seedbox/services/ServeGate.php', "$serveDir/ServeGate.php");

    // A fixture service, reachable only through the stand-in "ssh" below -- no real network, no real key.
    $home = "$dir/home/fixtureuser";
    mkdir($home, 0700, true);
    $svcDir = "$local/secrets/services/box1";
    mkdir($svcDir, 0700, true);
    file_put_contents("$svcDir/key", "not a real key, never read by the stand-in\n");
    file_put_contents("$svcDir/known_hosts", "not real, never read by the stand-in\n");
    mkdir("$local/state", 0700, true);
    file_put_contents("$local/state/services.jsonl", json_encode(['nick' => 'box1', 'tags' => ['seedbox'], 'provider' => 'generic',
        'host' => '127.0.0.1', 'port' => 22, 'user' => 'fixtureuser', 'home' => $home, 'transport' => 'pikkumies',
        'clients' => ['rtorrent' => []], 'allow_delete' => false, 'notes' => '', 'added_at' => gmdate('c')]) . "\n");

    // The stand-in "ssh": ignores every connection option, reads the remote words after "--" (exactly
    // what sshd hands a forced command as SSH_ORIGINAL_COMMAND), and runs them through the REAL,
    // unmodified ServeGate -- the same decision a real box's serve.php would make (serve.php itself
    // has since landed and calls ServeGate::decide() the same way: tokenize, then decide(..., $home,
    // rootsFor($home, $configFile)) -- rootsFor()'s 4th argument was added after this test was first
    // written, which is exactly what made the original 3-argument call here fatal with an
    // ArgumentCountError once the real ServeGate replaced the test's own copy of it).
    $ssh = "#!/usr/bin/env php\n<?php\nrequire " . var_export("$lib/Cli.php", true) . ";\nrequire " . var_export("$lib/Config.php", true) . ";\nrequire " . var_export("$serveDir/ServeGate.php", true) . ";\n"
        . '$i = array_search("--", $argv, true);' . "\n"
        . 'if ($i === false || !isset($argv[$i + 1])) { fwrite(STDERR, "stand-in ssh: no -- host in argv\n"); exit(255); }' . "\n"
        . '$cmd = implode(" ", array_slice($argv, $i + 2));' . "\n"
        . 'try {' . "\n"
        . '    $home = ' . var_export($home, true) . ';' . "\n"
        . '    $roots = ServeGate::rootsFor($home, null);' . "\n"
        . '    $d = ServeGate::decide(ServeGate::tokenize($cmd), false, $home, $roots);' . "\n"
        . '    echo json_encode(["probe" => true, "kind" => $d["kind"], "unit" => isset($d["unit"]) ? $d["unit"] : null,' . "\n"
        . '        "verb" => isset($d["verb"]) ? $d["verb"] : null, "args" => isset($d["args"]) ? $d["args"] : null]), "\n";' . "\n"
        . '    echo json_encode(["ok" => true]), "\n";' . "\n"
        . '} catch (CliError $e) {' . "\n"
        . '    echo json_encode(["ok" => false, "error" => $e->getMessage()]), "\n";' . "\n"
        . '    exit(1);' . "\n"
        . "}\n";
    file_put_contents("$bin/ssh", $ssh);
    chmod("$bin/ssh", 0755);
    symlink(PHP_BINARY, "$bin/php");

    // The driver: runs in its own process (never this test runner's), builds the REAL argv for each
    // remote call BalanceGather::remoteRunner() actually makes, and prints one JSON object per call.
    $driver = "<?php\n"
        . 'putenv("HOME=' . $home . '");' . "\n"
        . 'putenv("PATH=' . $bin . '");' . "\n"
        . 'require ' . var_export("$lib/Services.php", true) . ";\n"
        . 'Services::$localDir = ' . var_export($local, true) . ";\n"
        . 'Services::$configFile = ' . var_export("$local/no-such-config.php", true) . ";\n" // missing file -> empty config, blockUnknown off
        . '$calls = [["system/system-status", ["account"]], ["torrent-clients/rtorrent", ["list", "--limit", "0", "--offset", "0"]]];' . "\n"
        . 'foreach ($calls as $c) {' . "\n"
        . '    list($unit, $args) = $c;' . "\n"
        . '    $out = ["call" => $unit . " " . implode(" ", $args)];' . "\n"
        . '    try {' . "\n"
        . '        $argv = Services::sshArgv("box1", array_merge(["tool", $unit], $args));' . "\n"
        . '        $out["sshargv_ok"] = true;' . "\n"
        . '        $out["jsonl"] = Services::jsonl("box1", array_merge(["tool", $unit], $args));' . "\n"
        . '    } catch (CliError $e) {' . "\n"
        . '        $out["sshargv_ok"] = false;' . "\n"
        . '        $out["error"] = $e->getMessage();' . "\n"
        . '    }' . "\n"
        . '    echo json_encode($out), "\n";' . "\n"
        . "}\n"
        // And one call that MUST be refused by the real whitelist, proving the gate is not a rubber stamp.
        . '$bad = ["call" => "torrent-clients/rtorrent frobnicate"];' . "\n"
        . 'try {' . "\n"
        . '    Services::sshArgv("box1", ["tool", "torrent-clients/rtorrent", "frobnicate"]);' . "\n"
        . '    $bad["sshargv_ok"] = true;' . "\n" // sshArgv only checks TOKEN_RE, not the whitelist -- it is expected to succeed
        . '    $bad["jsonl"] = Services::jsonl("box1", ["tool", "torrent-clients/rtorrent", "frobnicate"]);' . "\n"
        . '} catch (CliError $e) {' . "\n"
        . '    $bad["sshargv_ok"] = false;' . "\n"
        . '    $bad["error"] = $e->getMessage();' . "\n"
        . "}\n"
        . 'echo json_encode($bad), "\n";' . "\n";
    $driverPath = "$dir/driver.php";
    file_put_contents($driverPath, $driver);
    return $driverPath;
}

return [
    'BalanceGather remote calls (system-status account; torrent-clients/<client> list --limit --offset) pass the REAL Services::sshArgv TOKEN_RE and the REAL ServeGate whitelist' => function () {
        list($code, $out) = runPhp(bsctFixture(), []);
        $lines = array_values(array_filter(explode("\n", trim($out)), 'strlen'));
        check($code === 0, "the driver script itself must exit cleanly: $out");
        check(count($lines) === 3, 'expected exactly 3 result lines (account, list, and the deliberately-bad call): ' . $out);

        $account = json_decode($lines[0], true);
        check($account['sshargv_ok'] === true, 'Services::sshArgv() refused the real account call: ' . json_encode($account));
        $last = end($account['jsonl']);
        check($last['ok'] === true, 'the stand-in ssh (running the REAL ServeGate) refused the account call: ' . json_encode($account));
        check($last === $account['jsonl'][count($account['jsonl']) - 1], 'jsonl() must keep the status line last: ' . json_encode($account['jsonl']));
        foreach ($account['jsonl'] as $rec) {
            check($rec['service'] === 'box1', 'jsonl() must tag every record, including the status line, with "service": ' . json_encode($rec));
        }
        $probe = $account['jsonl'][0];
        check($probe['unit'] === 'system/system-status' && $probe['verb'] === 'account', 'ServeGate decoded the wrong unit/verb: ' . json_encode($probe));

        $list = json_decode($lines[1], true);
        check($list['sshargv_ok'] === true, 'Services::sshArgv() refused the real list call: ' . json_encode($list));
        $listLast = end($list['jsonl']);
        check($listLast['ok'] === true, 'ServeGate refused the real "list --limit 0 --offset 0" call -- the exact one BalanceGather::paged() sends: ' . json_encode($list));
        $listProbe = $list['jsonl'][0];
        check($listProbe['unit'] === 'torrent-clients/rtorrent' && $listProbe['verb'] === 'list' && $listProbe['args'] === ['--limit', '0', '--offset', '0'],
            'ServeGate decoded the paging options wrong: ' . json_encode($listProbe));

        $bad = json_decode($lines[2], true);
        check($bad['sshargv_ok'] === true, 'sshArgv() only checks TOKEN_RE, not the whitelist, so a well-formed-but-unlisted verb must still build an argv: ' . json_encode($bad));
        $badLast = end($bad['jsonl']);
        check($badLast['ok'] === false, 'ServeGate must refuse a verb it does not whitelist (frobnicate) -- the gate is not a rubber stamp: ' . json_encode($bad));
    },
];
