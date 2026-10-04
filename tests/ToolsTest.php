<?php
function runPhp($script, array $args, $stdin = '')
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    return [$code, $out];
}

return [
    'guard hook escalates rm -rf to approval' => function () {
        $hook = dirname(__DIR__) . '/pikkumies/hooks/pre_tool_call/guard-destructive.php';
        list(, $out) = runPhp($hook, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => 'rm -rf /home/x']]));
        $d = json_decode($out, true);
        check(is_array($d) && $d['action'] === 'approve', 'no approval escalation: ' . $out);
    },
    'guard hook ignores harmless and non-terminal calls' => function () {
        $hook = dirname(__DIR__) . '/pikkumies/hooks/pre_tool_call/guard-destructive.php';
        list(, $out) = runPhp($hook, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => 'ls -la']]));
        check(trim($out) === '', 'harmless command flagged');
        list(, $out) = runPhp($hook, [], json_encode(['tool_name' => 'read_file', 'tool_input' => ['path' => 'x']]));
        check(trim($out) === '', 'non-terminal flagged');
    },
    'memory: save then search finds the lesson, no overwrite' => function () {
        $tmp = sys_get_temp_dir() . '/pk-mem-' . getmypid();
        @mkdir("$tmp/pikkumies/tools/memory", 0700, true);
        copy(dirname(__DIR__) . '/pikkumies/tools/memory/memory.php', "$tmp/pikkumies/tools/memory/memory.php");
        file_put_contents("$tmp/body.md", "rtorrent stopped because the disk was full; freed space and restarted it.");
        $tool = "$tmp/pikkumies/tools/memory/memory.php";
        list($code, $out) = runPhp($tool, ['save', 'rtorrent stops on full disk', "$tmp/body.md", '--tags', 'rtorrent,disk']);
        check($code === 0 && strpos($out, 'saved:') !== false, 'save failed: ' . $out);
        list($code) = runPhp($tool, ['save', 'rtorrent stops on full disk', "$tmp/body.md"]);
        check($code !== 0, 'overwrite allowed');
        list(, $out) = runPhp($tool, ['search', 'rtorrent disk full']);
        check(strpos($out, 'rtorrent-stops-on-full-disk') !== false, 'search missed lesson: ' . $out);
        list(, $out) = runPhp($tool, ['search', 'nginx certificate']);
        check(trim($out) === '', 'unrelated query matched');
        exec('rm -rf ' . escapeshellarg($tmp));
    },
    'update: fast-forwards a depth-1 clone, refuses local edits' => function () {
        $tmp = sys_get_temp_dir() . '/pk-upd-' . getmypid();
        $g = function ($dir, $args) {
            exec('git -C ' . escapeshellarg($dir) . ' -c user.name=t -c user.email=t@t ' . $args . ' 2>&1', $o, $c);
            check($c === 0, "git $args: " . implode(' ', $o));
            return trim(implode("\n", $o));
        };
        @mkdir("$tmp/src", 0700, true);
        $g("$tmp/src", 'init -q -b main');
        file_put_contents("$tmp/src/a.txt", "1\n");
        $g("$tmp/src", 'add a.txt');
        $g("$tmp/src", 'commit -q -m one');
        $g($tmp, 'clone -q --depth 1 file://' . "$tmp/src" . ' co');
        file_put_contents("$tmp/src/a.txt", "2\n");
        $g("$tmp/src", 'commit -q -am two');
        Update::$root = "$tmp/co";
        list($ok, $msg) = Update::run();
        check($ok && strpos($msg, 'updated') === 0, 'shallow update failed: ' . $msg);
        check(file_get_contents("$tmp/co/a.txt") === "2\n", 'content not updated');
        file_put_contents("$tmp/co/a.txt", "local\n");
        list($ok) = Update::run();
        check(!$ok && file_get_contents("$tmp/co/a.txt") === "local\n", 'local edit not protected');
        Update::$root = null;
        exec('rm -rf ' . escapeshellarg($tmp));
    },
];
