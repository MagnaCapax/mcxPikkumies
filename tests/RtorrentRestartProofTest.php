<?php
/**
 * rtorrent `restart` reports a restart only when the rTorrent from before is gone (ADR-0064), and lib/OwnProcs.php,
 * the process finder it uses. A fake /proc under the test home; the stand-in for PMSS's ~/.rtorrentRestart.php
 * changes that fake /proc the way a real restart (or a no-op one, PMSS issue #717) would. Nothing is signalled.
 */
require_once __DIR__ . '/fixtures/rtorrent/helpers.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/lib/Torrents.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/rtorrent/RtorrentClient.php';

if (!function_exists('fakeProc')) {
    /** One process in a fake /proc: comm, a stat line with $start as field 22, and the mount namespace link. */
    function fakeProc($proc, $pid, $comm, $start, $ns = 'mnt:[1]', $state = 'S')
    {
        @mkdir("$proc/$pid/ns", 0700, true);
        file_put_contents("$proc/$pid/comm", "$comm\n");
        file_put_contents("$proc/$pid/stat", "$pid ($comm) $state " . str_repeat('0 ', 18) . "$start 0 0\n");
        @unlink("$proc/$pid/ns/mnt");
        symlink($ns, "$proc/$pid/ns/mnt");
    }

    /** Runs `restart` in this process against the fake rTorrent, with $script as the PMSS restart script; [result|null, error]. */
    function restartWith($h, $script)
    {
        mkdir("$h/proc/self/ns", 0700, true);
        symlink('mnt:[1]', "$h/proc/self/ns/mnt");
        fakeProc("$h/proc", 100, 'rtorrent main', 5000);
        file_put_contents("$h/.rtorrentRestart.php", '<?php if (file_exists("www/.rtorrentRestart")) { unlink("www/.rtorrentRestart"); ' . $script . ' }');
        RtorrentRestart::$proc = "$h/proc";
        try {
            return [RtorrentRestart::run(new RtorrentClient('pmss', $h, posix_geteuid())), ''];
        } catch (CliError $e) {
            return [null, $e->getMessage() . ' | ' . $e->hint];
        } finally {
            RtorrentRestart::$proc = '/proc';
        }
    }
}

$stub = 'function fakeProc($proc, $pid, $comm, $start) { @mkdir("$proc/$pid/ns", 0700, true); file_put_contents("$proc/$pid/comm", "$comm\n");'
    . ' file_put_contents("$proc/$pid/stat", "$pid ($comm) S " . str_repeat("0 ", 18) . "$start 0 0\n"); @symlink("mnt:[1]", "$proc/$pid/ns/mnt"); }';

return [
    'rtorrent restart: the old rTorrent still running after the platform restart is "not restarted", never answered:true' => function () use ($stub) {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($r, $err) = restartWith($h, ''); // the no-op of PMSS #717: the script found no "rtorrent", so nothing changed
            check($r === null && strpos($err, 'still running') !== false && strpos($err, '#717') !== false && strpos($err, 'ticket') !== false, 'a no-op restart was reported: ' . json_encode($r) . $err);
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'rtorrent restart: the old process gone and a new one answering is a restart; a reused pid with a new start time counts as new' => function () use ($stub) {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($r, $err) = restartWith($h, $stub . ' exec("rm -rf proc/100"); fakeProc("proc", 200, "rtorrent main", 9000);');
            check(is_array($r) && $r['answered'] === true && $r['processes_before'] === 1 && $r['processes_after'] === 1, 'a real restart: ' . json_encode($r) . $err);
            check(!isset($r['pids']) && strpos(json_encode($r), '200') === false, 'raw pids in the output: ' . json_encode($r));
        } finally {
            rtorrentDone($h, $p);
        }
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($r, $err) = restartWith($h, $stub . ' fakeProc("proc", 100, "rtorrent", 9000);');
            check(is_array($r) && $r['processes_after'] === 1, 'same pid, new start time is a new process: ' . json_encode($r) . $err);
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'rtorrent restart: the old process plus a new one is "two processes share one session", and the hint names the watchdog' => function () use ($stub) {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($r, $err) = restartWith($h, $stub . ' fakeProc("proc", 200, "rtorrent", 9000);');
            check($r === null && strpos($err, '2 rTorrent processes') !== false && strpos($err, 'every 2 minutes') !== false, 'a duplicate spawn was reported as a restart: ' . json_encode($r) . $err);
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'OwnProcs: exact names only, own uid only, own mount namespace only; a zombie or a missing process has no start time' => function () {
        $proc = testTempDir('pk-ownprocs');
        try {
            mkdir("$proc/self/ns", 0700, true);
            symlink('mnt:[1]', "$proc/self/ns/mnt");
            fakeProc($proc, 10, 'rtorrent main', 111);
            fakeProc($proc, 11, 'rtorrentx', 112);
            fakeProc($proc, 12, 'rtorrent', 113, 'mnt:[2]');
            fakeProc($proc, 13, 'rtorrent', 114, 'mnt:[1]', 'Z');
            $uid = posix_geteuid();
            $names = Detect::KNOWN_APPS['rtorrent'];
            check(OwnProcs::pids($names, $uid, $proc) === [10, 13], 'pids: ' . json_encode(OwnProcs::pids($names, $uid, $proc)));
            check(OwnProcs::pids($names, $uid + 1, $proc) === [], 'another uid\'s processes were found');
            check(OwnProcs::identities($names, $uid, $proc) === [10 => '111'], 'identities: ' . json_encode(OwnProcs::identities($names, $uid, $proc)));
            check(OwnProcs::started(99, $proc) === null && OwnProcs::started(13, $proc) === null, 'a gone or zombie process has a start time');
            file_put_contents("$proc/10/stat", "10 (a) b) c) S " . str_repeat('0 ', 18) . "777 0\n");
            check(OwnProcs::started(10, $proc) === '777', 'a name with ") " broke the stat parse: ' . var_export(OwnProcs::started(10, $proc), true));
        } finally {
            testRmTree($proc);
        }
    },
];
