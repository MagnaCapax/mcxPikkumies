<?php
/**
 * tools/torrent-clients/session-rewrite: the `rewrite` CLI end to end (refusal while running, dry-run,
 * --apply with a backup, per-client directory resolution, the deluge refusal). Real files on disk, a fake
 * /proc (the tool's $sessionRewriteProcRoot seam) so the "client is running" refusal is tested without touching the real /proc,
 * and a fixture local/ dir (the tool's $sessionRewriteLocalDir seam) so a backup never lands in the real
 * pikkumies/local/ during this test run.
 */

/** A temp HOME with a fake /proc (no processes unless sreFakeProc() adds one) and a fixture local/ dir. */
function sreFixture()
{
    $dir = sys_get_temp_dir() . '/pk-sre-' . getmypid() . '-' . mt_rand();
    $home = "$dir/home";
    foreach (["$home", "$dir/proc/self/ns", "$dir/local"] as $d) {
        mkdir($d, 0700, true);
    }
    symlink('mnt:[1]', "$dir/proc/self/ns/mnt");
    return ['dir' => $dir, 'home' => $home, 'proc' => "$dir/proc", 'local' => "$dir/local"];
}

/** Adds a fake running process named $comm (e.g. "rtorrent") to the fixture's fake /proc. */
function sreFakeProc(array $fx, $comm)
{
    $pid = 4242;
    mkdir("{$fx['proc']}/$pid/ns", 0700, true);
    file_put_contents("{$fx['proc']}/$pid/comm", "$comm\n");
    symlink('mnt:[1]', "{$fx['proc']}/$pid/ns/mnt");
}

function sreScript(array $fx)
{
    $tool = dirname(__DIR__) . '/pikkumies/tools/torrent-clients/session-rewrite/session-rewrite.php';
    $run = "{$fx['dir']}/run.php";
    file_put_contents($run, '<?php
$sessionRewriteLocalDir = ' . var_export($fx['local'], true) . ';
$sessionRewriteProcRoot = ' . var_export($fx['proc'], true) . ';
require ' . var_export($tool, true) . ';
');
    return $run;
}

/** Runs the tool with HOME set (the fake /proc comes in through sreScript); returns [code, decoded lines, raw output]. */
function sreRun(array $fx, array $args)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(sreScript($fx));
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $env = getenv();
    $env['HOME'] = $fx['home'];
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($p);
    clearstatcache();
    return [$code, cliLines($out), $out];
}

return [
    'session-rewrite: deluge is refused outright, naming the pickle reason and the live repath alternative' => function () {
        $fx = sreFixture();
        try {
            list($code, , $out) = sreRun($fx, ['rewrite', '--client', 'deluge', '--from', '/home/fixtureuserold', '--to', '/home/fixtureusernew']);
            check($code === 1 && strpos($out, 'pickle') !== false && strpos($out, 'repath') !== false, "deluge refusal: $out");
        } finally {
            testRmTree($fx['dir']);
        }
    },
    'session-rewrite: refuses while the named client is running, and nothing is read or changed' => function () {
        $fx = sreFixture();
        try {
            mkdir($fx['home'] . '/session', 0700, true);
            file_put_contents($fx['home'] . '/session/' . str_repeat('a', 40) . '.rtorrent', 'd9:directory20:/home/fixtureuserolde');
            sreFakeProc($fx, 'rtorrent');
            list($code, , $out) = sreRun($fx, ['rewrite', '--client', 'rtorrent', '--from', '/home/fixtureuserold', '--to', '/home/fixtureusernew']);
            check($code === 1 && strpos($out, 'is running') !== false, "running refusal: $out");
        } finally {
            testRmTree($fx['dir']);
        }
    },
    'session-rewrite: rtorrent dry-run lists the change without writing, --apply backs up first then rewrites, raw-preserving' => function () {
        $fx = sreFixture();
        try {
            mkdir($fx['home'] . '/session', 0700, true);
            $hash = str_repeat('a', 40);
            $before = 'd9:directory' . strlen('/home/fixtureuserold/data/Multi') . ':/home/fixtureuserold/data/Multi8:priorityi2ee';
            file_put_contents("{$fx['home']}/session/$hash.rtorrent", $before);
            list($code, $l, $out) = sreRun($fx, ['rewrite', '--client', 'rtorrent', '--from', '/home/fixtureuserold', '--to', '/home/fixtureusernew']);
            check($code === 0, "dry-run failed: $out");
            check(file_get_contents("{$fx['home']}/session/$hash.rtorrent") === $before, 'a dry-run must never write: ' . $out);
            $rec = $l[0];
            check(strpos($rec['file'], "$hash.rtorrent") !== false && $rec['keys'][0]['key'] === 'directory'
                && $rec['keys'][0]['to'] === '/home/fixtureusernew/data/Multi', 'dry-run record: ' . $out);
            $status = $l[count($l) - 1];
            check($status['files_scanned'] === 1 && $status['files_changed'] === 1 && $status['applied'] === false && $status['backup'] === null, 'dry-run status: ' . $out);

            list($code2, $l2, $out2) = sreRun($fx, ['rewrite', '--client', 'rtorrent', '--from', '/home/fixtureuserold', '--to', '/home/fixtureusernew', '--apply']);
            check($code2 === 0, "apply failed: $out2");
            $status2 = $l2[count($l2) - 1];
            check($status2['applied'] === true && is_string($status2['backup']) && is_file($status2['backup']), 'apply status: ' . $out2);
            check(filesize($status2['backup']) > 0 && substr((string) file_get_contents($status2['backup']), 0, 2) === "\x1f\x8b", 'backup must be a real, non-empty gzip archive'); // strpos with an empty needle is false on PHP 7.3
            $after = (string) file_get_contents("{$fx['home']}/session/$hash.rtorrent");
            check(strpos($after, '/home/fixtureusernew/data/Multi') !== false && strpos($after, 'fixtureuserold') === false
                && strpos($after, '8:priorityi2e') !== false, "rewritten file: $after");

            // The backup holds the ORIGINAL bytes: extract it and compare.
            $extractDir = $fx['dir'] . '/extract';
            mkdir($extractDir, 0700, true);
            exec('tar -xzf ' . escapeshellarg($status2['backup']) . ' -C ' . escapeshellarg($extractDir));
            $restored = (string) @file_get_contents("$extractDir/session/$hash.rtorrent");
            check($restored === $before, 'the backup must hold the file as it was BEFORE the rewrite: ' . var_export($restored, true));
        } finally {
            testRmTree($fx['dir']);
        }
    },
    'session-rewrite: a file whose value does not start with --from is listed as unchanged (no key record for it), and an unreadable/non-bencode file is reported, not silently skipped' => function () {
        $fx = sreFixture();
        try {
            mkdir($fx['home'] . '/session', 0700, true);
            file_put_contents($fx['home'] . '/session/' . str_repeat('b', 40) . '.rtorrent', 'd9:directory17:/home/someoneelsee');
            file_put_contents($fx['home'] . '/session/' . str_repeat('c', 40) . '.rtorrent', 'not bencode');
            list($code, $l, $out) = sreRun($fx, ['rewrite', '--client', 'rtorrent', '--from', '/home/fixtureuserold', '--to', '/home/fixtureusernew']);
            check($code === 0, "run failed: $out");
            $status = $l[count($l) - 1];
            check($status['files_scanned'] === 2 && $status['files_changed'] === 0, 'neither file matches --from: ' . $out);
            $reasons = array_values(array_filter($l, function ($r) {
                return isset($r['reason']);
            }));
            check(count($reasons) === 1 && strpos($reasons[0]['file'], 'c', 0) !== false, 'the non-bencode file must be reported: ' . $out);
        } finally {
            testRmTree($fx['dir']);
        }
    },
    'session-rewrite: qbittorrent and transmission resolve their own directories, and an account with none of them installed gets a clear refusal' => function () {
        $fx = sreFixture();
        try {
            list($code, , $out) = sreRun($fx, ['rewrite', '--client', 'qbittorrent', '--from', '/home/fixtureuserold', '--to', '/home/fixtureusernew']);
            check($code === 1 && strpos($out, 'BT_backup') !== false, "qbittorrent not installed: $out");
            mkdir($fx['home'] . '/.local/share/qBittorrent/BT_backup', 0700, true);
            file_put_contents($fx['home'] . '/.local/share/qBittorrent/BT_backup/' . str_repeat('d', 40) . '.fastresume',
                'd9:save_path' . strlen('/home/fixtureuserold/data') . ':/home/fixtureuserold/datae');
            list($code2, $l2, $out2) = sreRun($fx, ['rewrite', '--client', 'qbittorrent', '--from', '/home/fixtureuserold', '--to', '/home/fixtureusernew']);
            check($code2 === 0 && $l2[count($l2) - 1]['files_changed'] === 1, "qbittorrent dry-run: $out2");

            list($code3, , $out3) = sreRun($fx, ['rewrite', '--client', 'transmission', '--from', '/home/fixtureuserold', '--to', '/home/fixtureusernew']);
            check($code3 === 1 && strpos($out3, 'resume directory') !== false, "transmission not installed: $out3");
            mkdir($fx['home'] . '/.config/transmission-daemon/resume', 0700, true);
            file_put_contents($fx['home'] . '/.config/transmission-daemon/resume/x.' . str_repeat('e', 16) . '.resume',
                'd11:destination' . strlen('/home/fixtureuserold/data') . ':/home/fixtureuserold/datae');
            list($code4, $l4, $out4) = sreRun($fx, ['rewrite', '--client', 'transmission', '--from', '/home/fixtureuserold', '--to', '/home/fixtureusernew']);
            check($code4 === 0 && $l4[count($l4) - 1]['files_changed'] === 1, "transmission dry-run: $out4");
        } finally {
            testRmTree($fx['dir']);
        }
    },
    'session-rewrite: a symlinked session directory is backed up and rewritten by its REAL target, not just the ~100-byte link (security review N1)' => function () {
        $fx = sreFixture();
        try {
            mkdir($fx['home'] . '/real-session', 0700, true);
            symlink($fx['home'] . '/real-session', $fx['home'] . '/session');
            $hash = str_repeat('a', 40);
            $before = 'd9:directory' . strlen('/home/fixtureuserold/data') . ':/home/fixtureuserold/data8:priorityi2ee';
            file_put_contents("{$fx['home']}/real-session/$hash.rtorrent", $before);
            list($code, $l, $out) = sreRun($fx, ['rewrite', '--client', 'rtorrent', '--from', '/home/fixtureuserold', '--to', '/home/fixtureusernew', '--apply']);
            check($code === 0, "apply through a symlinked session dir failed: $out");
            $status = $l[count($l) - 1];
            check($status['applied'] === true, "symlinked session dir must still apply: $out");
            check(filesize($status['backup']) > 200, 'the backup of a symlinked session dir must hold the real file, not a ~100-byte link to it: ' . filesize($status['backup']));
            $extractDir = $fx['dir'] . '/extract1';
            mkdir($extractDir, 0700, true);
            exec('tar -tzf ' . escapeshellarg($status['backup']), $listing);
            check((bool) array_filter($listing, function ($l) use ($hash) {
                return strpos($l, "$hash.rtorrent") !== false;
            }), 'the backup must list the real session file, not just a symlink entry: ' . implode('|', $listing));
            $after = (string) file_get_contents("{$fx['home']}/real-session/$hash.rtorrent");
            check(strpos($after, '/home/fixtureusernew/data') !== false, 'the REAL (target) file must have been rewritten: ' . $after);
        } finally {
            testRmTree($fx['dir']);
        }
    },
    'session-rewrite: the running-client check matches the kernel\'s 15-char comm truncation (security review N2), and --apply writes atomically (no stray .tmp file left)' => function () {
        $fx = sreFixture();
        try {
            mkdir($fx['home'] . '/.config/transmission-daemon/resume', 0700, true);
            $hash = str_repeat('a', 16);
            file_put_contents("{$fx['home']}/.config/transmission-daemon/resume/x.$hash.resume",
                'd11:destination' . strlen('/home/fixtureuserold/data') . ':/home/fixtureuserold/datae');
            sreFakeProc($fx, 'transmission-da'); // the kernel's own truncation of "transmission-daemon" (TASK_COMM_LEN-1 = 15)
            list($code, , $out) = sreRun($fx, ['rewrite', '--client', 'transmission', '--from', '/home/fixtureuserold', '--to', '/home/fixtureusernew']);
            check($code === 1 && strpos($out, 'is running') !== false, "a kernel-truncated comm must still be recognized as running: $out");
            // Not running: --apply must succeed and leave no .tmp<pid> file behind in the resume directory.
            exec('rm -rf ' . escapeshellarg($fx['proc']));
            mkdir("{$fx['proc']}/self/ns", 0700, true);
            symlink('mnt:[1]', "{$fx['proc']}/self/ns/mnt");
            list($code2, $l2, $out2) = sreRun($fx, ['rewrite', '--client', 'transmission', '--from', '/home/fixtureuserold', '--to', '/home/fixtureusernew', '--apply']);
            check($code2 === 0 && $l2[count($l2) - 1]['applied'] === true, "apply failed once not running: $out2");
            $leftover = glob("{$fx['home']}/.config/transmission-daemon/resume/*.tmp*");
            check($leftover === [], 'an atomic write must never leave a .tmp<pid> file behind: ' . json_encode($leftover));
        } finally {
            testRmTree($fx['dir']);
        }
    },
    'session-rewrite: refuses when the resolved session directory is the home folder itself (security review N6)' => function () {
        $fx = sreFixture();
        try {
            file_put_contents($fx['home'] . '/.rtorrent.rc', "session.path.set = .\n"); // a misconfiguration: "." resolves to $HOME itself
            list($code, , $out) = sreRun($fx, ['rewrite', '--client', 'rtorrent', '--from', '/home/fixtureuserold', '--to', '/home/fixtureusernew']);
            check($code === 1 && strpos($out, 'home folder itself') !== false, "session.path.set=. must be refused, not treated as the session dir: $out");
        } finally {
            testRmTree($fx['dir']);
        }
    },
    'session-rewrite: old backups beyond the keep limit are pruned, oldest first (security review N6)' => function () {
        $fx = sreFixture();
        try {
            mkdir($fx['home'] . '/session', 0700, true);
            $hash = str_repeat('a', 40);
            file_put_contents("{$fx['home']}/session/$hash.rtorrent",
                'd9:directory' . strlen('/home/fixtureuserold/data') . ':/home/fixtureuserold/datae');
            mkdir($fx['local'] . '/state/backups', 0700, true);
            for ($i = 0; $i < 12; $i++) {
                $ts = sprintf('202501%02d-000000', $i + 1);
                touch($fx['local'] . "/state/backups/session-rewrite-rtorrent-$ts-aaaaaaaa.tar.gz");
            }
            list($code, $l, $out) = sreRun($fx, ['rewrite', '--client', 'rtorrent', '--from', '/home/fixtureuserold', '--to', '/home/fixtureusernew', '--apply']);
            check($code === 0 && $l[count($l) - 1]['applied'] === true, "apply failed: $out");
            $left = glob($fx['local'] . '/state/backups/session-rewrite-rtorrent-*.tar.gz');
            check(count($left) === 10, 'only the newest 10 backups must remain: found ' . count($left));
            check(strpos(implode(' ', $left), '20250101-000000') === false && strpos(implode(' ', $left), '20250103-000000') === false,
                'the two oldest fakes (01, 03) must have been pruned first: ' . implode(' ', $left));
        } finally {
            testRmTree($fx['dir']);
        }
    },
];
