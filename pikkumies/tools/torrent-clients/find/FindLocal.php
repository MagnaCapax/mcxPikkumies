<?php
/**
 * FindLocal: this account's own torrent-client units (rtorrent, qbittorrent, deluge, transmission, ...),
 * each run concurrently so one slow client never holds up the others (tools/torrent-clients/find/README.md).
 * Presence is read from each unit's own `list` answer, never re-detected: a client this account does not
 * have answers "... is not installed ..." (RtorrentLocate, DelugeLocate::absent, QbitEndpoint::readConfig
 * all say exactly that) -- find.php's findAbsorb() reads that answer, FindLocal only runs the units and
 * hands back whatever each one said.
 * PHP 7.3 proc_open takes a command string only (no array form); `exec ` + ToolProc::line, so proc_terminate
 * really stops the child, the way lib/ToolProc.php and tools/backup/remote/RemotePipe.php already do. A
 * timeout here bounds only our own wait: `list` talks to a local socket or port and starts no children.
 * A client past its deadline gets SIGTERM, then up to GRACE_SEC to exit on its own (polled via
 * proc_get_status, never a blocking wait), then SIGKILL to its whole process group -- `setsid` (as
 * ToolProc/BrowseProc already use for a detached child) makes the client its own session AND process
 * group leader, so a client that ignores SIGTERM or shells out to something stuck cannot keep find.php
 * waiting past GRACE_SEC beyond the timeout (security review F8).
 */
class FindLocal
{
    const GRACE_SEC = 2; // SIGTERM to SIGKILL, same order of magnitude as the stream_select poll tick below

    /** Unit directories directly below $root with "<name>/<name>.php"; $exclude skips this unit and lib/. */
    public static function discover($root, array $exclude = ['lib'])
    {
        $out = [];
        foreach ((array) @scandir($root) as $name) {
            if ($name === '.' || $name === '..' || in_array($name, $exclude, true) || !is_dir("$root/$name")) {
                continue;
            }
            if (is_file("$root/$name/$name.php")) {
                $out[$name] = "$root/$name/$name.php";
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Runs `php <script> list` for every $units (name => script path), concurrently, each bounded by
     * $timeoutSec seconds. Returns ['rows' => [name => decoded JSONL lines], 'busy' => [names that were
     * still answering past their deadline]]; a name in neither could not even be started (no php on PATH).
     */
    public static function run(array $units, $timeoutSec)
    {
        $timeoutSec = max(1, (int) $timeoutSec);
        $deadline = microtime(true) + $timeoutSec;
        $open = [];
        foreach ($units as $name => $script) {
            $pipes = null;
            $p = @proc_open('exec setsid ' . ToolProc::line([PHP_BINARY, $script, 'list']),
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($p)) {
                continue;
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $status = proc_get_status($p); // setsid makes this pid its own new session and process group leader
            $open[$name] = ['p' => $p, 'pid' => $status['pid'], 'out' => $pipes[1], 'err' => $pipes[2], 'buf' => '',
                'live' => [1 => true, 2 => true], 'termAt' => null];
        }
        $rows = [];
        $busy = [];
        while ($open) {
            $read = [];
            foreach ($open as $name => $pr) {
                foreach ($pr['live'] as $fd => $on) {
                    if ($on) {
                        $read["$name:$fd"] = $fd === 1 ? $pr['out'] : $pr['err'];
                    }
                }
            }
            $w = null;
            $e = null;
            if ($read && @stream_select($read, $w, $e, 1) === false) {
                break;
            }
            foreach ($open as $name => &$pr) {
                foreach ([1 => 'out', 2 => 'err'] as $fd => $key) {
                    if (!$pr['live'][$fd]) {
                        continue;
                    }
                    $chunk = @fread($pr[$key], 65536);
                    if ($fd === 1 && $chunk !== false && $chunk !== '') {
                        $pr['buf'] .= $chunk;
                    }
                    if (feof($pr[$key])) {
                        $pr['live'][$fd] = false;
                    }
                }
            }
            unset($pr);
            $now = microtime(true);
            foreach ($open as $name => $pr) {
                $eof = !$pr['live'][1] && !$pr['live'][2];
                if ($pr['termAt'] !== null) {
                    // Once a client has been sent SIGTERM for missing its deadline, it stays "busy" no matter
                    // how quickly it then dies -- it never answered within the time budget. proc_close() below
                    // only runs once it is actually gone (eof), or the grace window forces a SIGKILL first.
                    if (!$eof && $now - $pr['termAt'] < self::GRACE_SEC) {
                        continue; // inside the SIGTERM-to-SIGKILL grace window; polled again next tick, never blocking
                    }
                    if (!$eof && proc_get_status($pr['p'])['running']) {
                        // SIGTERM alone did not stop it in time: SIGKILL (uncatchable) the whole process group,
                        // so a client stuck in a shelled-out child cannot keep find.php waiting either.
                        @posix_kill(-$pr['pid'], 9);
                    }
                    $busy[] = $name;
                } elseif ($eof) {
                    $rows[$name] = self::decode($pr['buf']);
                } elseif ($now >= $deadline) {
                    proc_terminate($pr['p']); // SIGTERM; proc_close() is never called before this has a chance to work
                    $open[$name]['termAt'] = $now;
                    continue;
                } else {
                    continue; // still within its own time budget: leave it running
                }
                @fclose($pr['out']);
                @fclose($pr['err']);
                proc_close($pr['p']);
                unset($open[$name]);
            }
        }
        return ['rows' => $rows, 'busy' => $busy];
    }

    /** One JSON object per non-empty line; a line that is not valid JSON is dropped, never fatal. */
    private static function decode($out)
    {
        $lines = [];
        foreach (explode("\n", $out) as $l) {
            $d = $l === '' ? null : json_decode($l, true);
            if (is_array($d)) {
                $lines[] = $d;
            }
        }
        return $lines;
    }
}
