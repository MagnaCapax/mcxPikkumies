<?php
/** lib/HookAsync.php's detached start (ADR-0067): a background check outlives the hook that started it, as Hermes kills hooks. */
require_once __DIR__ . '/asyncSupport.php';

return [
    'async: the detached start survives the hook being killed the way Hermes kills a timed-out hook (its process group and its descendants); with --foreground or without & it does not' => function () {
        if (!function_exists('posix_kill') || HookAsync::bin('setsid') === '' || HookAsync::bin('timeout') === '') {
            skip('needs posix, setsid and timeout');
        }
        $tmp = testTempDir('pk-detach');
        try {
            $lib = dirname(__DIR__) . '/pikkumies/lib';
            foreach (['real' => '', 'foreground' => 'fg', 'blocking' => 'nobg'] as $name => $variant) {
                $marker = "$tmp/$name.marker";
                // The hook: starts the job as kick() builds the command (or a broken variant of it), then keeps running until killed.
                file_put_contents("$tmp/$name.php", '<?php require ' . var_export("$lib/HookAsync.php", true) . ';'
                    . ' $cmd = HookAsync::detached([HookAsync::bin("sh"), "-c", "sleep 2; touch ' . $marker . '"], 10);'
                    . ($variant === 'fg' ? ' $cmd = str_replace("\x27-k\x27", "\x27--foreground\x27 \x27-k\x27", $cmd);' : '') . ($variant === 'nobg' ? ' $cmd = substr($cmd, 0, -2);' : '')
                    . ' file_put_contents(' . var_export("$tmp/$name.started", true) . ', "1"); exec($cmd); sleep(30);');
                $p = proc_open('exec ' . escapeshellarg(HookAsync::bin('setsid')) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$tmp/$name.php"),
                    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
                $pid = proc_get_status($p)['pid'];
                asyncWait(function () use ($tmp, $name) { return is_file("$tmp/$name.started"); }, 5);
                usleep(500000);
                // agent/deadline.py kill_process_tree: every descendant (by parent pid), then the group.
                $kids = function ($root) use (&$kids) {
                    $out = [];
                    foreach ((array) glob('/proc/[0-9]*/stat') as $stat) {
                        $f = explode(' ', (string) @file_get_contents($stat));
                        if (isset($f[3]) && (int) $f[3] === $root) {
                            $out = array_merge($out, [(int) $f[0]], $kids((int) $f[0]));
                        }
                    }
                    return $out;
                };
                foreach ($kids($pid) as $k) {
                    posix_kill($k, 9);
                }
                posix_kill(-$pid, 9);
                proc_close($p);
                $survived = asyncWait(function () use ($marker) { return is_file($marker); }, 4);
                check($survived === ($name === 'real'), "$name: the job " . ($survived ? 'survived' : 'died') . ' the kill');
            }
        } finally {
            testRmTree($tmp);
        }
    },
];
