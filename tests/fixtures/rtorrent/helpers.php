<?php
/**
 * Helpers for the tests/Rtorrent*Test.php files: a temporary $HOME, the fake rTorrent (server.php) on a unix
 * socket or a 127.0.0.1 port, and a wrapper that runs the unit. Nothing real is contacted.
 */
function rtorrentHome($rc = "network.scgi.open_local = ~/.rtorrent.socket\n")
{
    $h = sys_get_temp_dir() . '/pk-rt-' . getmypid() . '-' . mt_rand();
    foreach (['data/Multi/sub', 'data/Linked', 'outside', 'www'] as $d) {
        mkdir("$h/$d", 0700, true);
    }
    file_put_contents("$h/data/Multi/a.mkv", str_repeat('a', 2000));
    file_put_contents("$h/data/Multi/sub/b.nfo", str_repeat('b', 1000));
    file_put_contents("$h/data/single.mkv", str_repeat('s', 500));
    file_put_contents("$h/data/Linked/real.mkv", str_repeat('r', 1000));
    file_put_contents("$h/outside/victim.mkv", str_repeat('v', 300));
    symlink("$h/outside", "$h/data/Linked/lnk");
    if ($rc !== null) {
        file_put_contents("$h/.rtorrent.rc", $rc);
    }
    file_put_contents("$h/state.json", str_replace('{HOME}', $h, (string) file_get_contents(__DIR__ . '/torrents.json')));
    $lib = dirname(__DIR__, 3) . '/pikkumies/tools/torrent-clients';
    foreach (['pmss', 'generic', 'ultra'] as $family) { // the same unit with the host family fixed, for the provider-specific paths
        file_put_contents("$h/run-$family.php", '<?php require ' . var_export("$lib/lib/Torrents.php", true) . '; require ' . var_export("$lib/rtorrent/RtorrentClient.php", true) . ";\n"
            . 'Torrents::run($argv, function () { return new RtorrentClient(' . var_export($family, true) . ', getenv("HOME"), posix_geteuid()); }, "usage");');
    }
    return $h;
}

/** Starts the fake server; $addr defaults to the PMSS socket path. Returns the process. */
function rtorrentServe($h, $addr = null, $mode = '')
{
    $addr = $addr ?: "unix://$h/.rtorrent.socket";
    // exec: the shell is replaced by PHP, so proc_terminate() stops the server itself, not only a wrapper shell
    $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/server.php') . ' ' . escapeshellarg($addr) . ' ' . escapeshellarg("$h/state.json") . ($mode ? " $mode" : '');
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "$h/server.err", 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        $s = @stream_socket_client($addr, $errno, $errstr, 0.1);
        if ($s) {
            fclose($s);
            break;
        }
        usleep(100000);
    }
    return $proc;
}

function rtorrentRun($h, array $args, $script = null)
{
    $script = $script ?: dirname(__DIR__, 3) . '/pikkumies/tools/torrent-clients/rtorrent/rtorrent.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $env = getenv();
    $env['HOME'] = $h;
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($p);
    clearstatcache(); // the tool's process changed files: a stale stat cache here once saw a deleted folder (991bd61)
    return [$code, $out, cliLines($out)];
}

function rtorrentState($h)
{
    return json_decode((string) file_get_contents("$h/state.json"), true);
}

function rtorrentCalls($h, $method)
{
    return array_values(array_filter(rtorrentState($h)['calls'], function ($c) use ($method) {
        return $c[0] === $method;
    }));
}

function rtorrentDone($h, $proc)
{
    if ($proc) {
        proc_terminate($proc);
        proc_close($proc);
        clearstatcache();
    }
    exec('rm -rf ' . escapeshellarg($h));
}

/** A one-file .torrent with "info" as its last key (the fake server hashes it the same way). */
function rtorrentTorrentFile($path)
{
    $info = 'd6:lengthi5e4:name5:x.bin12:piece lengthi16384e6:pieces20:' . str_repeat('p', 20) . 'e';
    $announce = 'https://tracker.example/ann/x';
    file_put_contents($path, 'd8:announce' . strlen($announce) . ':' . $announce . '4:info' . $info . 'e');
    return strtoupper(sha1($info));
}

/**
 * Adds the three torrents the deleter refuses: Bad1 lists ../single.mkv, Bad2 an absolute path, Bad3 sits in "$h-away",
 * outside the home. $custom5 sets ruTorrent's delete-data flag on Multi (the first torrent) as a failed delete leaves it.
 */
function rtorrentAddRefused($h, $custom5 = '')
{
    mkdir("$h/data/Dots");
    mkdir("$h-away");
    file_put_contents("$h-away/f.bin", 'x');
    $s = rtorrentState($h);
    $s['torrents'][0]['custom5'] = $custom5;
    foreach ([['1', "$h/data/Dots", '../single.mkv'], ['2', "$h/data/Dots", "$h/outside/victim.mkv"], ['3', "$h-away", 'f.bin']] as $t) {
        $s['torrents'][] = ['hash' => str_repeat($t[0], 40), 'name' => 'Bad'
            . $t[0], 'size' => 1, 'done' => 1, 'complete' => 1, 'state' => 1, 'open' => 1,
            'active' => 1, 'dir' => $t[1], 'multi' => 1, 'files' => [[$t[2], 1]]];
    }
    file_put_contents("$h/state.json", json_encode($s));
}
