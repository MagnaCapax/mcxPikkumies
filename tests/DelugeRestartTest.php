<?php
/** deluge restart on PMSS: which start command runs and under which lock. A fake PMSS launcher and fake deluged and deluge-web record how they were called; the fake deluge-web answers afterwards. */
require_once __DIR__ . '/fixtures/deluge/helpers.php';

/** An enabled PMSS account whose fake launcher records its argv and which of its descriptors point at the account lock; ~/launcher_rc steers its exit code. */
function delugeLauncherAccount(array $fx, $selected)
{
    $h = $fx['home'];
    mkdir("$h/www", 0700, true);
    mkdir($fx['dir'] . '/pmss', 0700, true);
    touch("$h/.delugeEnable");
    foreach (['deluged', 'deluge-web'] as $bin) { // the bare start, if anything runs it
        file_put_contents($fx['dir'] . "/bin/$bin", "#!/bin/sh\necho $bin >> \"\$HOME/bare\"\n");
        chmod($fx['dir'] . "/bin/$bin", 0755);
    }
    file_put_contents("$h/www/torrentClientsResolve.php", '<?php
$h = getenv("HOME");
$locks = 0;
foreach ((array) glob("/proc/self/fd/*") as $fd) {
    $t = @readlink($fd);
    if (is_string($t) && strpos($t, "deluge.lock") !== false) {
        $locks++;
    }
}
file_put_contents("$h/launched", json_encode(["argv" => array_slice($argv, 1), "lockfds" => $locks]) . "\n", FILE_APPEND);
exit((int) @file_get_contents("$h/launcher_rc"));
');
    if ($selected) {
        mkdir("$h/bin/deluge", 0700, true);
        symlink($fx['dir'], "$h/bin/deluge/default");
    }
}

/** Runs `restart` as a PMSS account; system tools stay on PATH so the bare start's nohup works. */
function delugeRestart(array $fx)
{
    return delugeRun($fx, ['restart'], ['PIKKUMIES_PMSS_DIR' => $fx['dir'] . '/pmss', 'PATH' => $fx['dir'] . '/bin:/usr/bin:/bin']);
}

function delugeLaunches(array $fx)
{
    $f = $fx['home'] . '/launched';
    return is_file($f) ? array_map(function ($l) {
        return json_decode($l, true);
    }, array_filter(explode("\n", (string) file_get_contents($f)))) : [];
}

return [
    'deluge restart: with a selected build the PMSS launcher starts both daemons, with no lock descriptor left to them and the lock free afterwards' => function () {
        $fx = delugeFixture();
        try {
            delugeLauncherAccount($fx, true);
            list($code, $l, $out) = delugeRestart($fx);
            $calls = delugeLaunches($fx);
            check($code === 0 && $l[0]['answered'] === true && $l[0]['version'] === '2.0.3', 'restart: ' . $out);
            check(count($calls) === 1 && $calls[0]['argv'] === ['deluge', '--launch', 'both'], 'launcher call: ' . json_encode($calls));
            check($calls[0]['lockfds'] === 0, 'the launcher inherited the account lock');
            check(!file_exists($fx['home'] . '/bare'), 'the bare binaries were started as well');
            $lock = fopen($fx['home'] . '/.config/pmss/torrentClients/deluge.lock', 'c');
            check(flock($lock, LOCK_EX | LOCK_NB), 'the lock was still held after the restart');
        } finally {
            delugeDone($fx);
        }
    },
    'deluge restart: with no selected build the bare deluged and deluge-web start as before and the launcher is not called' => function () {
        $fx = delugeFixture();
        try {
            delugeLauncherAccount($fx, false);
            list($code, $l, $out) = delugeRestart($fx);
            check($code === 0 && $l[0]['answered'] === true && !delugeLaunches($fx), 'restart: ' . $out);
            $bare = is_file($fx['home'] . '/bare') ? explode("\n", trim((string) file_get_contents($fx['home'] . '/bare'))) : [];
            sort($bare);
            check($bare === ['deluge-web', 'deluged'], 'bare start: ' . json_encode($bare));
        } finally {
            delugeDone($fx);
        }
    },
    'deluge restart: a busy account lock, a missing launcher or a launcher failure is reported and never answered with a bare start' => function () {
        $fx = delugeFixture();
        try {
            delugeLauncherAccount($fx, true);
            $dir = $fx['home'] . '/.config/pmss/torrentClients';
            mkdir($dir, 0700, true);
            $busy = fopen("$dir/deluge.lock", 'c');
            check(flock($busy, LOCK_EX | LOCK_NB), 'the test could not take the lock');
            list($code, $l, $out) = delugeRestart($fx);
            check($code === 1 && strpos($out, 'another Deluge operation') !== false && !delugeLaunches($fx), 'busy lock: ' . $out);
            flock($busy, LOCK_UN);
            file_put_contents($fx['home'] . '/launcher_rc', '1');
            list($code, $l, $out) = delugeRestart($fx);
            check($code === 1 && strpos($out, 'refused to start it (exit 1)') !== false && count(delugeLaunches($fx)) === 1, 'launcher failure: ' . $out);
            rename($fx['home'] . '/www/torrentClientsResolve.php', $fx['home'] . '/www/elsewhere.php');
            list($code, $l, $out) = delugeRestart($fx);
            check($code === 1 && strpos($out, 'launcher script is missing') !== false && count(delugeLaunches($fx)) === 1, 'missing launcher: ' . $out);
            check(!file_exists($fx['home'] . '/bare'), 'a refusal fell back to the bare start');
        } finally {
            delugeDone($fx);
        }
    },
];
