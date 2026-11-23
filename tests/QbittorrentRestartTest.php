<?php
/** qbittorrent restart on PMSS: which start command runs, under which lock, and what a refusal leaves behind. A fake PMSS launcher records how it was called; no real client starts. */
require_once __DIR__ . '/fixtures/qbittorrent/harness.php';

/** A PMSS account with the start helper and a fake launcher; ~/launcher_rc and ~/launcher_notice steer it. */
function qbitLauncherAccount(array $fx)
{
    $h = $fx['home'];
    mkdir("$h/www", 0700, true);
    touch("$h/www/qbittorrentStart.php");
    file_put_contents("$h/www/torrentClientsResolve.php", '<?php
$h = getenv("HOME");
$locks = 0;
foreach ((array) glob("/proc/self/fd/*") as $fd) {
    $t = @readlink($fd);
    if (is_string($t) && strpos($t, "qbittorrent.lock") !== false) {
        $locks++;
    }
}
file_put_contents("$h/launched", json_encode(["argv" => array_slice($argv, 1), "lockfds" => $locks]) . "\n", FILE_APPEND);
$rc = (int) @file_get_contents("$h/launcher_rc");
if ($rc !== 0 && is_file("$h/launcher_notice")) {
    file_put_contents("$h/.config/pmss/torrentClients/qbittorrent.notice", file_get_contents("$h/launcher_notice"));
}
exit($rc);
');
    file_put_contents($fx['dir'] . '/bin/qbittorrent-nox', "#!/bin/sh\necho bare >> \"\$HOME/bare\"\n");
    chmod($fx['dir'] . '/bin/qbittorrent-nox', 0755);
    touch("$h/.qbittorrentEnable");
}

/** A fake own qbittorrent-nox process (a sleeping PHP the fake /proc names so), as the restart test in QbittorrentHostTest does. */
function qbitOwnProcess(array $fx)
{
    $pid = (int) exec(escapeshellarg(PHP_BINARY) . " -r 'sleep(60);' > /dev/null 2>&1 & echo \$!");
    qbitFakeProc($fx, $pid, 'mnt:[4026531841]');
    return $pid;
}

/** True while the process runs (a zombie is gone). */
function qbitRunning($pid)
{
    $st = @file_get_contents("/proc/$pid/stat");
    return is_string($st) && strpos(substr($st, strrpos($st, ')')), ') Z') !== 0;
}

function qbitLaunches(array $fx)
{
    $f = $fx['home'] . '/launched';
    return is_file($f) ? array_map(function ($l) {
        return json_decode($l, true);
    }, array_filter(explode("\n", (string) file_get_contents($f)))) : [];
}

return [
    'qbittorrent restart: with the PMSS start helper the PMSS launcher starts the client, selected build or not, with no lock descriptor left to the daemon' => function () {
        foreach (['no selection' => false, 'a build selected' => true] as $label => $link) {
            qbitWith([], function ($fx) use ($label, $link) {
                qbitLauncherAccount($fx);
                if ($link) {
                    mkdir($fx['home'] . '/bin/qbittorrent', 0700, true);
                    symlink($fx['dir'], $fx['home'] . '/bin/qbittorrent/default');
                }
                $pid = qbitOwnProcess($fx);
                try {
                    list($code, $out) = qbitRun($fx, ['restart']);
                    $r = cliLines($out)[0];
                    $l = qbitLaunches($fx);
                    check($code === 0 && $r['stopped'] === 1 && $r['answered'] === true, "$label: restart: $out");
                    check(count($l) === 1 && $l[0]['argv'] === ['qbittorrent', '--launch', '--held', '--system'], "$label: launcher call: " . json_encode($l));
                    check($l[0]['lockfds'] === 0, "$label: the launcher inherited the account lock");
                    check(!file_exists($fx['home'] . '/bare'), "$label: the bare binary was started as well");
                    check(!qbitRunning($pid), "$label: the own process was not stopped");
                    $lock = fopen($fx['home'] . '/.config/pmss/torrentClients/qbittorrent.lock', 'c');
                    check(flock($lock, LOCK_EX | LOCK_NB), "$label: the lock was still held after the restart");
                } finally {
                    if (function_exists('posix_kill')) {
                        @posix_kill($pid, 9);
                    }
                }
            });
        }
    },
    'qbittorrent restart: a refusal marker or a busy account lock stops nothing and starts nothing; the marker is never cleared' => function () {
        qbitWith([], function ($fx) {
            qbitLauncherAccount($fx);
            $dir = $fx['home'] . '/.config/pmss/torrentClients';
            mkdir($dir, 0700, true);
            $pid = qbitOwnProcess($fx);
            try {
                touch("$dir/qbittorrent.refused");
                list($code, $out) = qbitRun($fx, ['restart']);
                check($code === 1 && strpos($out, 'refuses to start') !== false, 'refusal marker: ' . $out);
                check(qbitRunning($pid) && !qbitLaunches($fx) && is_file("$dir/qbittorrent.refused"), 'refusal marker: stopped, launched or cleared');
                unlink("$dir/qbittorrent.refused");
                $busy = fopen("$dir/qbittorrent.lock", 'c');
                check(flock($busy, LOCK_EX | LOCK_NB), 'the test could not take the lock');
                list($code, $out) = qbitRun($fx, ['restart']);
                check($code === 1 && strpos($out, 'another qBittorrent operation') !== false, 'busy lock: ' . $out);
                check(qbitRunning($pid) && !qbitLaunches($fx) && !file_exists($fx['home'] . '/bare'), 'busy lock: stopped or started');
            } finally {
                if (function_exists('posix_kill')) {
                    @posix_kill($pid, 9);
                }
            }
        });
    },
    'qbittorrent restart: a launcher refusal is reported with its new notice, never answered with a bare start' => function () {
        qbitWith([], function ($fx) {
            qbitLauncherAccount($fx);
            $dir = $fx['home'] . '/.config/pmss/torrentClients';
            mkdir($dir, 0700, true);
            file_put_contents("$dir/qbittorrent.notice", "old: an earlier refusal\n");
            file_put_contents($fx['home'] . '/launcher_rc', '1');
            list($code, $out) = qbitRun($fx, ['restart']);
            check($code === 1 && strpos($out, 'no reason recorded') !== false && strpos($out, 'earlier refusal') === false, 'unchanged notice: ' . $out);
            file_put_contents($fx['home'] . '/launcher_notice', "qBittorrent start refused: the selected binary is unavailable.\n");
            list($code, $out) = qbitRun($fx, ['restart']);
            check($code === 1 && strpos($out, 'selected binary is unavailable') !== false, 'new notice: ' . $out);
            check(count(qbitLaunches($fx)) === 2 && !file_exists($fx['home'] . '/bare'), 'a refusal fell back to the bare start');
        });
    },
];
