<?php
/** Shared by the interval hook unit tests (ADR-0067): a throwaway tree with lib/, the orchestrator, the worker and fixture units. */
if (!function_exists('asyncTree')) {
    function asyncCopy($src, $dst)
    {
        @mkdir($dst, 0700, true);
        foreach ((array) scandir($src) as $f) {
            if ($f !== '.' && $f !== '..') {
                is_dir("$src/$f") ? asyncCopy("$src/$f", "$dst/$f") : copy("$src/$f", "$dst/$f");
            }
        }
    }

    /** The recorder unit: appends its stdin to runs.jsonl, sleeps <dir>/sleep seconds, prints <dir>/answer.json. */
    function asyncRecorder()
    {
        return '$in = (string) stream_get_contents(STDIN);'
            . ' file_put_contents(__DIR__ . "/runs.jsonl", $in . "\n", FILE_APPEND | LOCK_EX);'
            . ' if (is_file(__DIR__ . "/sleep")) { sleep((int) file_get_contents(__DIR__ . "/sleep")); }'
            . ' file_put_contents(__DIR__ . "/done", "1", FILE_APPEND);'
            . ' echo is_file(__DIR__ . "/answer.json") ? file_get_contents(__DIR__ . "/answer.json") : "";';
    }

    /**
     * A tree: [category/name => ['event' => 'transcript', 'every' => 60, 'timeout' => 5, 'wake' => null|list, 'failClosed' => false,
     * 'body' => php]]; units other than interval ones may be given with 'event'. $config goes to local/config.php.
     */
    function asyncTree(array $units, array $config = [])
    {
        $tmp = sys_get_temp_dir() . '/pk-async-' . getmypid() . '-' . mt_rand();
        $src = dirname(__DIR__) . '/pikkumies';
        asyncCopy("$src/lib", "$tmp/pikkumies/lib");
        @mkdir("$tmp/pikkumies/hooks", 0700, true);
        @mkdir("$tmp/pikkumies/local", 0700, true);
        copy("$src/hooks/orchestrator.php", "$tmp/pikkumies/hooks/orchestrator.php");
        copy("$src/hooks/worker.php", "$tmp/pikkumies/hooks/worker.php");
        foreach ($units as $id => $u) {
            $u += ['event' => 'transcript', 'every' => 60, 'timeout' => 5, 'wake' => null, 'failClosed' => false, 'body' => asyncRecorder()];
            $dir = "$tmp/pikkumies/hooks/$id";
            $name = basename($id);
            @mkdir($dir, 0700, true);
            $md = "---\nname: $name\nevent: {$u['event']}\n" . ($u['every'] !== null ? "every: {$u['every']}\n" : '') . ($u['wake'] !== null ? "wake: {$u['wake']}\n" : '')
                . "description: fixture\nfailClosed: " . ($u['failClosed'] ? 'true' : 'false') . "\ntimeout: {$u['timeout']}\n---\nfixture\n";
            file_put_contents("$dir/hook.md", $md);
            file_put_contents("$dir/README.md", "# $name\n");
            file_put_contents("$dir/$name.php", "<?php\n" . $u['body'] . "\n");
        }
        if ($config) {
            file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export($config, true) . ";\n");
        }
        return $tmp;
    }

    /** Run the orchestrator for $event, reading stdout and stderr to their end as Hermes does: [stdout, seconds until both closed, exit code]. */
    function asyncRun($tree, $event, array $payload, array $env = [])
    {
        // Bounded like a Hermes entry: an orchestrator that hangs is killed after 15 s and shows as slow, never as a stuck suite.
        $cmd = (HookAsync::bin('timeout') !== '' ? escapeshellarg(HookAsync::bin('timeout')) . ' 15 ' : '') . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg("$tree/pikkumies/hooks/orchestrator.php") . ' ' . escapeshellarg($event);
        $start = microtime(true);
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env ? array_merge(getenv(), $env) : null);
        fwrite($pipes[0], json_encode($payload));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $took = microtime(true) - $start;
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        clearstatcache();
        return [$out, $took, $code];
    }

    /** Poll $cond every 50 ms for up to $seconds; true as soon as it holds. */
    function asyncWait(callable $cond, $seconds)
    {
        $end = microtime(true) + $seconds;
        do {
            clearstatcache();
            if ($cond()) {
                return true;
            }
            usleep(50000);
        } while (microtime(true) < $end);
        return false;
    }

    /** The decoded stdin of each run the recorder unit $id saw. */
    function asyncRuns($tree, $id)
    {
        $f = "$tree/pikkumies/hooks/$id/runs.jsonl";
        return is_file($f) ? array_map(function ($l) {
            return json_decode($l, true);
        }, array_values(array_filter(file($f, FILE_IGNORE_NEW_LINES), 'strlen'))) : [];
    }

    /** A unit's state file for the session (suffix like .stamp, .cursor, .notice.json). */
    function asyncFile($tree, $id, $session, $suffix)
    {
        return "$tree/pikkumies/local/state/async/" . str_replace('/', '-', $id) . '/' . sha1($session) . $suffix;
    }

    /** Run PHP $code with the tree's lib/ loaded (so Config::localDir() is the tree's); returns its stdout. */
    function asyncPhp($tree, $code)
    {
        file_put_contents("$tree/snippet.php", '<?php foreach (glob(' . var_export("$tree/pikkumies/lib/*.php", true) . ') as $l) { require_once $l; } ' . $code);
        list(, $out) = runPhp("$tree/snippet.php", []);
        return $out;
    }

    function asyncTool($session, $tool, array $input, $status = 'ok', $turn = 't1')
    {
        return ['hook_event_name' => 'post_tool_call', 'tool_name' => $tool, 'tool_input' => $input, 'session_id' => $session,
            'extra' => ['status' => $status, 'turn_id' => $turn, 'result' => json_encode(['output' => 'SECRET OUTPUT', 'exit_code' => 0])]];
    }
}
