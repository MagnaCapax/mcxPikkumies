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
];
