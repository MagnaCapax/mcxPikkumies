<?php
/**
 * The backup safety layer (ADR-0099): the status verdicts, third-party detection, the status verb and its table, snap
 * (cap, permissions, refusals, package config files, uid 0 / sudo / none), the generated cron lines (never /dev/null)
 * and the key read-back. The delete guard's check and the pre-edit copies are in BackupSafetyHooksTest.php.
 */
$bsTools = dirname(__DIR__) . '/pikkumies/tools';
require_once "$bsTools/backup/lib/Backup.php";
foreach (['restic/ResticRepo', 'restic/ResticPrune', 'restic/ResticTool', 'borg/BorgRepo', 'borg/BorgPrune', 'borg/BorgTool', 'remote/RemotePipe',
    'remote/RemoteDest', 'remote/RemoteOps', 'backup-safety/BackupFacts', 'backup-safety/BackupSnap'] as $bsClass) {
    require_once "$bsTools/backup/$bsClass.php";
}

if (!function_exists('bsMethod')) {
    /** A method that works at $now, with fields replaced from $over. */
    function bsMethod($now, array $over = [])
    {
        return $over + ['kind' => 'restic', 'where' => 'sftp:u@box:repo', 'latest_at' => $now - 3600, 'latest_bytes' => 1000, 'prev_bytes' => 1100,
            'verified_at' => $now - 86400, 'verify_ok' => true, 'error' => null, 'backups' => []];
    }

    /** Runs BackupSnap::run with seams; returns [records or null, error or null]. Seams are reset after. */
    function bsSnap(array $args, array $opt, $home, $local, array $seams = [])
    {
        BackupSnap::$root = isset($seams['root']) ? $seams['root'] : ['available' => false, 'via' => 'none'];
        BackupSnap::$run = isset($seams['run']) ? $seams['run'] : null;
        BackupSnap::$systemDir = isset($seams['system']) ? $seams['system'] : "$home/../system-backups";
        BackupSnap::$free = isset($seams['free']) ? $seams['free'] : 1 << 40;
        try {
            return [BackupSnap::run($args, $opt, $home, $local, 1792483200), null];
        } catch (CliError $e) {
            return [null, $e->getMessage()];
        } finally {
            BackupSnap::$root = BackupSnap::$run = BackupSnap::$systemDir = BackupSnap::$free = null;
        }
    }

    /** A home and local dir under one temp dir. */
    function bsFixture()
    {
        $t = testTempDir('pk-bsafe');
        mkdir("$t/fixturehome/.config/app", 0700, true);
        mkdir("$t/local", 0700, true);
        file_put_contents("$t/fixturehome/.config/app/app.conf", "a=1\n");
        chmod("$t/fixturehome/.config/app/app.conf", 0644);
        return $t;
    }
}

return [
    'backup safety: the verdicts working, stale, broken, unknown and none, and a missing cache is unknown' => function () {
        $now = 1792483200;
        $j = BackupStatus::judge([bsMethod($now)], $now);
        check($j['state'] === 'working' && $j['methods'][0]['working'] === true, 'working: ' . json_encode($j));
        check(BackupStatus::judge([bsMethod($now, ['latest_at' => $now - 3 * 86400])], $now)['state'] === 'stale', 'three days old is stale');
        check(BackupStatus::judge([bsMethod($now, ['latest_at' => $now - 3 * 86400, 'interval' => 2 * 86400])], $now)['state'] === 'working',
            'within twice its schedule is fresh');
        check(BackupStatus::judge([bsMethod($now, ['verified_at' => $now - 8 * 86400])], $now)['state'] === 'unknown', 'unverified for 8 days is unknown');
        foreach (['error' => 'unreachable', 'verify_ok' => false, 'latest_bytes' => 0] as $k => $v) {
            check(BackupStatus::judge([bsMethod($now, [$k => $v])], $now)['state'] === 'broken', "$k makes it broken");
        }
        check(BackupStatus::judge([bsMethod($now, ['latest_at' => null])], $now)['state'] === 'broken', 'set up without a backup is broken');
        check(BackupStatus::judge([], $now)['state'] === 'none', 'no method is none');
        check(BackupStatus::judge([['kind' => 'thirdparty', 'where' => 'your crontab (rsync)']], $now)['state'] === 'unknown', 'a third-party tool is unknown');
        check(BackupStatus::judge([bsMethod($now, ['error' => 'x']), bsMethod($now)], $now)['state'] === 'working', 'one working method makes it working');
        check(BackupStatus::judge([bsMethod($now, ['error' => 'x']), bsMethod($now, ['latest_at' => $now - 9 * 86400])], $now)['state'] === 'broken',
            'broken outranks stale');
        $t = testTempDir('pk-bsafe-cache');
        try {
            check(BackupStatus::read($t) === null && BackupStatus::state(null) === 'unknown', 'a missing cache must read as unknown');
            check(BackupStatus::write($t, $j) && BackupStatus::read($t)['state'] === 'working', 'the cache did not round-trip');
            check((fileperms(BackupStatus::file($t)) & 0777) === 0600, 'the cache must be 0600');
            file_put_contents(BackupStatus::file($t), '{"state":"fine","checked_at":1}');
            check(BackupStatus::read($t) === null, 'a cache with an unknown state must not be trusted');
        } finally {
            testRmTree($t);
        }
    },
    'backup safety: the latest backup must be non-empty and within 50 percent of the one before' => function () {
        $now = 1792483200;
        check(BackupStatus::judge([bsMethod($now, ['latest_bytes' => 1500, 'prev_bytes' => 1000])], $now)['state'] === 'working', '+50 % exactly still works');
        $big = BackupStatus::judge([bsMethod($now, ['latest_bytes' => 1501, 'prev_bytes' => 1000])], $now);
        check($big['state'] === 'broken' && strpos($big['methods'][0]['why'], 'over 50 %') !== false, 'a jump over 50 % must be broken with its reason: ' . json_encode($big));
        check(BackupStatus::judge([bsMethod($now, ['latest_bytes' => 499, 'prev_bytes' => 1000])], $now)['state'] === 'broken', 'a drop over 50 % must be broken');
        check(BackupStatus::judge([bsMethod($now, ['latest_bytes' => null, 'prev_bytes' => 1000])], $now)['state'] === 'working', 'an unknown size is not a jump');
    },
    'backup safety: crontab lines that run a backup program are found; comments and Pikkumies\' own lines are not' => function () {
        $tab = "# 0 3 * * * restic backup ~\n0 4 * * * /usr/bin/rsync -a ~/data box:\n15 4 * * * php remote.php run --mode meta > log 2>&1 # pikkumies-backup-remote\n"
            . "0 5 * * * borgmatic --verbosity 1\n0 6 * * * echo rsyncing\n";
        $m = BackupStatus::thirdParty($tab, 'your crontab');
        check(array_column($m, 'program') === ['rsync', 'borgmatic'], 'found: ' . json_encode($m));
        $t = testTempDir('pk-bsafe-conf');
        try {
            mkdir("$t/.config/borgmatic", 0700, true);
            touch("$t/.config/borgmatic/config.yaml");
            check(count(BackupStatus::configFiles($t, ['~/.config/borgmatic/config.yaml', '~/.duplicacy/preferences'])) === 1, 'config file not found');
        } finally {
            testRmTree($t);
        }
    },
    'backup safety: gather reports a repository without a stored password, reads this account\'s crontab, and /etc only with root' => function () {
        $t = bsFixture();
        BackupFacts::$crontab = function () {
            return "0 3 * * * rsnapshot daily\n";
        };
        try {
            mkdir("$t/local/backup", 0700);
            file_put_contents("$t/local/backup/restic.json", json_encode(['repo' => 'sftp:u@box:r']));
            $m = BackupFacts::gather("$t/local", "$t/fixturehome", ['available' => false, 'via' => 'none'], null, 1792483200, false);
            check(count($m) === 2 && $m[0]['kind'] === 'restic' && strpos($m[0]['error'], 'no stored password') !== false
                && $m[1]['where'] === 'your crontab (rsnapshot)', 'methods: ' . json_encode($m));
            $j = BackupStatus::judge($m, 1792483200);
            check($j['state'] === 'broken', 'a repository that cannot be read is broken, whatever else is found: ' . $j['state']);
            unlink("$t/local/backup/restic.json");
            check(BackupStatus::judge(BackupFacts::gather("$t/local", "$t/fixturehome", ['available' => false, 'via' => 'none'], null, 1792483200), 1792483200)['state']
                === 'unknown', 'only a third-party cron line is unknown');
        } finally {
            BackupFacts::$crontab = null;
            testRmTree($t);
        }
    },
    'backup safety: status prints the methods, the 10 latest backups newest first as a table, and the storage box from the sources' => function () {
        $t = testTempDir('pk-bsafe-status');
        $now = 1792483200;
        try {
            $rows = [];
            for ($i = 0; $i < 12; $i++) {
                $rows[] = ['name' => "snap$i", 'time' => $now - ($i * 2 + 1) * 3600, 'bytes' => 1048576 * ($i + 1)];
            }
            $remote = bsMethod($now, ['kind' => 'remote', 'where' => 'u@box', 'backups' => [['name' => 'r0', 'time' => $now - 7200, 'bytes' => 5]],
                'passphrase_saved_by_user' => false]);
            BackupStatus::write($t, BackupStatus::judge([bsMethod($now, ['backups' => $rows]), $remote], $now));
            $script = testTempDir('pk-bsafe-run') . '/run.php';
            file_put_contents($script, '<?php $safetyLocalDir = ' . var_export($t, true) . '; $safetyNow = ' . $now . '; require '
                . var_export(dirname(__DIR__) . '/pikkumies/tools/backup/backup-safety/backup-safety.php', true) . ";\n");
            list($code, $out) = runPhp($script, ['status']);
            $l = cliLines($out);
            $status = end($l);
            $backups = array_values(array_filter($l, function ($r) {
                return isset($r['record']) && $r['record'] === 'backup';
            }));
            check($code === 0 && $status['state'] === 'working' && count($backups) === 10, 'status: ' . $out);
            check(array_column($backups, 'name') === ['snap0', 'r0', 'snap1', 'snap2', 'snap3', 'snap4', 'snap5', 'snap6', 'snap7', 'snap8'], 'order: ' . $out);
            check(substr_count($status['table'], "\n| ") === 10 && strpos($status['table'], '| 1 | ') !== false && strpos($status['table'], '1.0 MiB') !== false,
                'table: ' . $status['table']);
            check($status['storage_box'] === 'https://pulsedmedia.com/storage-boxes.php' && strpos($status['e2e_guide'], 'wiki.pulsedmedia.com') !== false,
                'links must come from config/documentationSources.md: ' . $out);
            check(strpos($status['custody'], 'backup passphrase not confirmed saved') === 0 && strpos($status['table'], 'not confirmed saved') !== false && !isset($status['suggestion']),
                'the custody line in the status and the table, and no suggestion while working: ' . $out);
            list(, $out) = runPhp($script, ['status', '--latest', '3']);
            check(count(array_filter(cliLines($out), function ($r) {
                return isset($r['record']) && $r['record'] === 'backup';
            })) === 3, '--latest 3: ' . $out);
            testRmTree(dirname($script));
        } finally {
            testRmTree($t);
        }
    },
    'backup safety: snap copies into ~/backups/<UTC>/ with private folders and owner-only files, logs it and records it for the guard' => function () {
        $t = bsFixture();
        try {
            list($r, $err) = bsSnap(["$t/fixturehome/.config/app/app.conf"], [], "$t/fixturehome", "$t/local");
            check($err === null && $r['status']['verified'] === true && $r['status']['total_bytes'] === 4, 'snap: ' . json_encode([$r, $err]));
            $copy = "$t/fixturehome/backups/20261020T080000Z/.config/app/app.conf";
            check(file_get_contents($copy) === "a=1\n", 'copy at the relative path: ' . json_encode($r));
            check((fileperms($copy) & 0777) === 0600, sprintf('file mode %o, want owner-only 0600', fileperms($copy) & 0777));
            foreach (["$t/fixturehome/backups", "$t/fixturehome/backups/20261020T080000Z", "$t/fixturehome/backups/20261020T080000Z/.config/app"] as $d) {
                check((fileperms($d) & 0077) === 0, sprintf('%s mode %o is open to others', $d, fileperms($d) & 0777));
            }
            check(strpos((string) file_get_contents("$t/local/logs/backup-snap/20261020.jsonl"), '"bytes":4') !== false, 'not logged');
            check(isset(BackupHold::recent("$t/local", 1792483200)[realpath("$t/fixturehome/.config/app/app.conf")]), 'not recorded for the guard');
        } finally {
            testRmTree($t);
        }
    },
    'backup safety: snap over the cap copies nothing; a missing path, the backups folder itself and a symlinked base are refused' => function () {
        $t = bsFixture();
        try {
            list(, $err) = bsSnap(["$t/fixturehome/.config"], [], "$t/fixturehome", "$t/local", ['free' => 6]);
            check($err !== null && strpos($err, 'over the cap') !== false && !is_dir("$t/fixturehome/backups"), 'cap: ' . $err);
            list(, $err) = bsSnap(["$t/fixturehome/nope"], [], "$t/fixturehome", "$t/local");
            check($err !== null && strpos($err, 'does not exist') !== false, 'missing: ' . $err);
            mkdir("$t/elsewhere", 0700);
            symlink("$t/elsewhere", "$t/fixturehome/backups");
            list(, $err) = bsSnap(["$t/fixturehome/.config/app/app.conf"], [], "$t/fixturehome", "$t/local");
            check($err !== null && strpos($err, 'symlink') !== false && count(scandir("$t/elsewhere")) === 2, 'symlinked base: ' . $err);
            unlink("$t/fixturehome/backups");
            mkdir("$t/fixturehome/backups/x", 0700, true);
            list(, $err) = bsSnap(["$t/fixturehome/backups/x"], [], "$t/fixturehome", "$t/local");
            check($err !== null && strpos($err, 'where the copies go') !== false, 'the backups folder itself: ' . $err);
        } finally {
            testRmTree($t);
        }
    },
    'backup safety: outside the home snap uses /backups as uid 0, sudo -n with sudo, ~/backups without root; --package needs root' => function () {
        $t = bsFixture();
        try {
            mkdir("$t/etc", 0700);
            file_put_contents("$t/etc/x.conf", "y\n");
            list($r, $err) = bsSnap(["$t/etc/x.conf"], [], "$t/fixturehome", "$t/local", ['root' => ['available' => true, 'via' => 'uid0'], 'system' => "$t/sysbk"]);
            check($err === null && is_file("$t/sysbk/20261020T080000Z" . ltrim("$t/etc/x.conf", '.')) && (fileperms("$t/sysbk") & 0777) === 0700,
                'uid0: ' . json_encode([$r, $err]));
            $argvs = [];
            $fake = function ($argv) use (&$argvs) {
                $argvs[] = $argv;
                return ['code' => 0, 'out' => $argv[3] === 'find' ? "2\n" : '', 'err' => ''];
            };
            list(, $err) = bsSnap(["$t/etc/x.conf"], [], "$t/fixturehome", "$t/local", ['root' => ['available' => true, 'via' => 'sudo'], 'system' => "$t/sudobk", 'run' => $fake]);
            $cp = array_values(array_filter($argvs, function ($a) {
                return in_array('cp', $a, true);
            }));
            check($err === null && $cp && array_slice($cp[0], 0, 3) === ['sudo', '-n', '--'] && strpos(end($cp[0]), "$t/sudobk/") === 0, 'sudo: ' . json_encode([$argvs, $err]));
            list($r, $err) = bsSnap(["$t/etc/x.conf"], [], "$t/fixturehome", "$t/local");
            check($err === null && is_file("$t/fixturehome/backups/20261020T080000Z" . "$t/etc/x.conf"), 'no root: into ~/backups under the full path: ' . json_encode([$r, $err]));
            list(, $err) = bsSnap([], ['package' => 'nginx'], "$t/fixturehome", "$t/local");
            check($err !== null && strpos($err, 'root or passwordless sudo') !== false, 'package without root: ' . $err);
        } finally {
            testRmTree($t);
        }
    },
    'backup safety: snap --package copies the conffiles dpkg-query lists and records the package for the guard' => function () {
        $t = bsFixture();
        try {
            mkdir("$t/etc/app", 0700, true);
            file_put_contents("$t/etc/app/app.conf", "z\n");
            $real = function ($argv) use ($t) {
                if ($argv[0] === 'dpkg-query') {
                    return ['code' => 0, 'out' => " $t/etc/app/app.conf 0123456789abcdef0123456789abcdef\n $t/etc/app/gone.conf 0123456789abcdef0123456789abcdef\n", 'err' => ''];
                }
                return ToolProc::run($argv);
            };
            list($r, $err) = bsSnap([], ['package' => 'app'], "$t/fixturehome", "$t/local", ['root' => ['available' => true, 'via' => 'uid0'], 'system' => "$t/sysbk", 'run' => $real]);
            check($err === null && count($r['records']) === 1 && file_get_contents($r['records'][0]['to']) === "z\n", 'package: ' . json_encode([$r, $err]));
            check(isset(BackupHold::recent("$t/local", 1792483200)['package:app']), 'the package must be recorded for the guard');
            list(, $err) = bsSnap([], ['package' => '../x'], "$t/fixturehome", "$t/local", ['root' => ['available' => true, 'via' => 'uid0']]);
            check($err !== null && strpos($err, 'not a package name') !== false, 'bad name: ' . $err);
        } finally {
            testRmTree($t);
        }
    },
    'cwe F1: HOME that is not this account\'s folder is never used, and as uid 0 snap copies into the system folder, never into a home' => function () {
        $uid = posix_geteuid();
        if ($uid === 0) {
            skip('runs as root: every folder is its own');
        }
        $got = testWithEnv(['HOME' => '/usr'], function () {
            try {
                return HomePath::home();
            } catch (CliError $e) {
                return 'refused';
            }
        });
        check($got !== '/usr', 'a HOME owned by another uid was used: ' . $got);
        $t = bsFixture();
        try {
            list($r, $err) = bsSnap(["$t/fixturehome/.config/app/app.conf"], [], "$t/fixturehome", "$t/local", ['root' => ['available' => true, 'via' => 'uid0'], 'system' => "$t/sysbk"]);
            check($err === null && !is_dir("$t/fixturehome/backups") && is_file("$t/sysbk/20261020T080000Z" . "$t/fixturehome/.config/app/app.conf"),
                'uid0 snap of a home file: ' . json_encode([$r, $err]));
        } finally {
            testRmTree($t);
        }
    },
    'cwe F10: a copy that did not read back whole does not cover its target for the guard, and a copy grown past the cap is reported' => function () {
        $t = bsFixture();
        try {
            $finds = 0;
            $fake = function ($argv) use (&$finds) {
                $finds += $argv[0] === 'find' ? 1 : 0;
                return ['code' => 0, 'out' => $argv[0] === 'find' ? ($finds === 1 ? "4\n" : "999\n") : '', 'err' => ''];
            };
            list($r, $err) = bsSnap(["$t/fixturehome/.config/app/app.conf"], [], "$t/fixturehome", "$t/local", ['run' => $fake, 'free' => 1000]);
            check($err === null && $r['records'][0]['verified'] === false && $r['status']['over_cap'] === true && $r['status']['total_bytes'] === 999,
                'snap: ' . json_encode([$r, $err]));
            check(BackupHold::recent("$t/local", 1792483200) === [], 'an unverified copy was recorded as covering its target');
        } finally {
            testRmTree($t);
        }
    },
    'cwe F4: temporary files go to a private local/tmp; a symlinked or foreign one is refused, never the shared /tmp' => function () {
        $t = testTempDir('pk-bsafe-tmp');
        try {
            $f = LocalTmp::file($t, 'pk-x');
            check(dirname($f) === "$t/tmp" && (fileperms("$t/tmp") & 0777) === 0700 && (fileperms($f) & 0777) === 0600, 'file: ' . $f);
            $u = testTempDir('pk-bsafe-tmp2');
            mkdir("$u/elsewhere", 0700);
            symlink("$u/elsewhere", "$u/tmp");
            try {
                LocalTmp::file($u, 'pk-x');
                check(false, 'a symlinked local/tmp was used');
            } catch (CliError $e) {
                check(count(scandir("$u/elsewhere")) === 2, 'something was written through the link');
            }
            testRmTree($u);
            if (posix_geteuid() !== 0) { // a folder this account cannot write: tempnam would fall back to the shared /tmp, which is refused
                chmod("$t/tmp", 0500);
                try {
                    $g = LocalTmp::file($t, 'pk-y');
                    @unlink($g);
                    check(false, 'tempnam fell back outside local/tmp: ' . $g);
                } catch (CliError $e) {
                    check(strpos($e->getMessage(), 'cannot make a temporary file') === 0, $e->getMessage());
                } finally {
                    chmod("$t/tmp", 0700);
                }
            }
        } finally {
            testRmTree($t);
        }
    },
    'cwe F4: a receiver answer is read through a pipe and stopped after 1 MiB' => function () {
        $t = testTempDir('pk-bsafe-call');
        try {
            mkdir("$t/bin", 0700);
            file_put_contents("$t/bin/ssh", "#!/bin/sh\nyes 0123456789abcdef | head -c 3000000\n");
            chmod("$t/bin/ssh", 0755);
            foreach (['yes', 'head'] as $b) {
                $p = trim((string) shell_exec('command -v ' . $b));
                symlink($p, "$t/bin/$b");
            }
            $dest = new RemoteDest("$t/local");
            list($code, $out) = testWithEnv(['PATH' => "$t/bin"], function () use ($dest) {
                return $dest->call(['host' => 'backup.example.com', 'user' => 'xyzjohn', 'port' => 22, 'label' => 'l'], ['list']);
            });
            check($code !== 0 && strlen($out) <= RemoteDest::CALL_MAX && strlen($out) > 1000000, 'call: code ' . $code . ', ' . strlen($out) . ' bytes');
            check(!is_dir("$t/local/tmp") || count(scandir("$t/local/tmp")) === 2, 'the answer must not land on disk');
        } finally {
            RemotePipe::$localDir = null; // set by the RemoteDest above; the folder goes now
            testRmTree($t);
        }
    },
    'backup safety: no cron line Pikkumies generates sends output to /dev/null, and each keeps a log' => function () {
        $spawned = [];
        BackupStatus::$spawn = function ($line) use (&$spawned) {
            $spawned[] = $line;
        };
        $t = testTempDir('pk-bsafe-cron');
        try {
            BackupStatus::refreshInBackground($t, '/x/backup-safety.php');
        } finally {
            BackupStatus::$spawn = null;
        }
        $lines = ['update' => Update::cronLine(1061), 'cron-tick' => Update::cronTickLine(),
            'backup remote' => RemoteOps::cronLine(4, 30, '/x/remote.php', 'meta', false, $t), 'status refresh' => $spawned[0],
            'interval worker' => str_replace('</dev/null', '', HookAsync::detached(['/x/worker.php'], 10, "$t/logs/hook-async.last"))];
        // cwe F8: the tick's log is in local/ itself, which always exists; a missing redirect folder would stop every tick.
        check(dirname(Update::cronTickLog()) === Config::localDir(), 'tick log folder: ' . Update::cronTickLog());
        foreach ($lines as $what => $line) {
            check($line !== '' && strpos($line, '/dev/null') === false, "$what line discards output: $line");
            check(preg_match("/ > '[^']+' 2>&1( |$)/", $line) === 1, "$what line keeps no log: $line");
        }
        check(is_dir("$t/logs/backup-status"), 'the refresh log folder must exist before the redirect');
        testRmTree($t);
    },
    'backup safety: passphrase --confirm-saved records only a typed copy that matches (spaces ignored), and a regenerated key is unconfirmed again' => function () {
        $t = testTempDir('pk-bsafe-key');
        $dest = new RemoteDest($t);
        mkdir($dest->dir, 0700, true);
        file_put_contents($dest->path('pass'), "abc123\n");
        chmod($dest->path('pass'), 0600);
        RemoteOps::$tty = function () {
            return true;
        };
        try {
            RemoteOps::$readHidden = function () {
                return 'abc12';
            };
            try {
                RemoteOps::passphrase($dest, ['confirm-saved' => true]);
                check(false, 'a wrong copy was accepted');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'not the passphrase') !== false && empty($dest->state()['passphrase_saved_by_user']), $e->getMessage());
            }
            check(!$dest->passphraseConfirmed(), 'nothing confirmed yet');
            RemoteOps::$readHidden = function () {
                return " abc1 23\n"; // typed in its groups of 4
            };
            RemoteOps::passphrase($dest, ['confirm-saved' => true]);
            check($dest->state()['passphrase_saved_by_user'] === true && $dest->passphraseConfirmed(), 'the right copy, in groups, was not recorded');
            check($dest->state()['passphrase_sha256'] === hash_file('sha256', $dest->path('pass')), 'the confirmed file\'s sha256 is kept');
            file_put_contents($dest->path('pass'), "def456\n"); // regenerated: a confirmation of the old key says nothing about this one
            check(!$dest->passphraseConfirmed(), 'a regenerated passphrase must count as not confirmed');
            $st = $dest->state();
            unset($st['passphrase_sha256']);
            $dest->save('state.json', $st);
            file_put_contents($dest->path('pass'), "abc123\n");
            check(!$dest->passphraseConfirmed(), 'an older confirmation without a sha256 was only a promise');
            RemoteOps::$tty = function () {
                return false;
            };
            try {
                RemoteOps::passphrase($dest, ['confirm-saved' => true]);
                check(false, 'confirm-saved ran without a terminal');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'own terminal') !== false, $e->getMessage());
            }
        } finally {
            RemoteOps::$tty = RemoteOps::$readHidden = null;
            testRmTree($t);
        }
    },
    'backup-safety: the background refresh never runs the remote archive check (it streams up to 10 GiB past the 120 s limit)' => function () {
        $src = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/tools/backup/backup-safety/BackupFacts.php');
        check(strpos($src, 'RemoteOps::check(') === false, 'BackupFacts must only read the record of `backup remote check`, never run it');
        check(strpos($src, 'run `backup remote check`') !== false, 'an unchecked remote must name the command that checks it');
    },
];
