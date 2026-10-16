<?php
/**
 * The delete guard (hooks/guard/guard-destructive/) on its own: the block classes the shared guard
 * tests do not reach, the obfuscated shapes it must still catch, and an unreadable payload (ADR-0020).
 * A few commands are built from parts so the literal text never appears in this file.
 */
if (!function_exists('guardEdgeRun')) {
    /** Run the guard with raw stdin; returns [exit code, decoded answer or null, raw stdout]. */
    function guardEdgeRun($stdin)
    {
        list($code, $out) = runPhp(dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php', [], $stdin);
        return [$code, json_decode($out, true), $out];
    }

    /** The guard's action for a terminal command: 'block', 'approve' or 'none'; $why must be in the message. */
    function guardEdgeAction($cmd, $why = '')
    {
        list(, $d, $raw) = guardEdgeRun(json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
        if (!is_array($d)) {
            check(trim($raw) === '', "unreadable answer for $cmd: $raw");
            return 'none';
        }
        check($why === '' || strpos($d['message'], $why) !== false, "wrong reason for $cmd: " . $d['message']);
        return $d['action'];
    }

    function guardEdgeExpect(array $cmds, $action, $why = '')
    {
        foreach ($cmds as $cmd) {
            $got = guardEdgeAction($cmd, $why);
            check($got === $action, "$cmd: expected $action, got $got");
        }
    }
}

$forkBomb = ':(' . '){ :|:& };:';
return [
    'guard: fork bomb, raw device write, filesystem format and partition tools are blocked' => function () use ($forkBomb) {
        guardEdgeExpect([$forkBomb], 'block', 'fork bomb');
        guardEdgeExpect(['dd if=/dev/zero of=/dev/sdb bs=1M'], 'block', 'raw write to a device');
        guardEdgeExpect(['mkfs.ext4 /dev/sdb1', 'mkfs -t xfs /dev/sdc'], 'block', 'filesystem format');
        guardEdgeExpect(['fdisk /dev/sdb', 'parted /dev/sdb mklabel gpt', 'sgdisk --zap-all /dev/sdb', 'wipefs -a /dev/sdb'], 'block', 'partition or signature change');
        guardEdgeExpect(['dd if=/dev/sda of=~/disk.img', 'df -h /dev/sda1'], 'none');
    },
    'guard: world-writable chmod is blocked in plain, recursive and octal forms; ordinary modes are not' => function () {
        guardEdgeExpect(['chmod 777 x', 'chmod -R 777 ~/data', 'chmod 777 -R ~/data', 'chmod 0777 x', 'chmod -R 0777 ~/data', 'chmod -v --recursive 777 ~/data'], 'block', 'world-writable permissions');
        guardEdgeExpect(['chmod 755 x', 'chmod 0755 ~/bin/x', 'chmod -R 700 ~/private', 'chmod 7770 x'], 'none');
    },
    'guard: force pushes are blocked, long and short flag; an ordinary push is not' => function () {
        guardEdgeExpect(['git push --force', 'git push origin main --force', 'git push -f origin main', 'git push --force-with-lease'], 'block', 'force push');
        guardEdgeExpect(['git push origin main', 'git push -u origin feature-f'], 'none');
    },
    'guard: rm -rf behind env, sudo, command, a subshell or a backslash is still blocked' => function () {
        guardEdgeExpect(['FOO=1 rm -rf ~/a', 'env rm -rf ~/a', 'sudo rm -rf ~/a', 'command rm -rf ~/a', '(rm -rf ~/a)', '\\rm -rf ~/a', 'cd ~ && rm -fr a'], 'block', 'recursive force delete');
    },
    'guard: a quote-split verb is the same command once the shell joins it' => function () {
        guardEdgeExpect(['r"m" -rf /home/x', "'r'm -rf /home/x", 'r\\m -rf ~/a', "\"rm\" '-rf' ~/a"], 'block', 'recursive force delete');
        guardEdgeExpect(["'r'm ~/x", 'u"nlink" ~/x'], 'approve', 'deletes or empties files');
        guardEdgeExpect(['c"h"mod 0777 x'], 'block', 'world-writable permissions');
    },
    'guard: a destructive verb renamed through an alias or a variable is blocked' => function () {
        guardEdgeExpect(['alias x=rm; x -rf /home/a', 'alias zap=shred; zap -u ~/a', 'X=rm; $X -rf ~/a', 'D=dd; $D bs=1M', 'T="truncate"; $T -s 0 ~/x'], 'block', 'renamed');
        guardEdgeExpect(["alias ll='ls -la'", 'MODE=dry; echo $MODE', 'git log --format=rm-notes'], 'none');
    },
    'guard: base64-decoded text run by a shell is blocked; decoding to a file is not' => function () {
        $b64 = 'base' . '64';
        $enc = "echo cm0gLXJmIC9ob21lL3g= | $b64"; // the payload is rm -rf /home/x
        guardEdgeExpect(["$enc -d | sh", "$enc --decode | bash", 'ev' . "al \"\$($enc -d)\"", "bash -c \"\$($enc -d)\"", "sh <($enc -d)"], 'block', 'decoded');
        guardEdgeExpect(["$b64 -d in.b64 > out.bin", "printf 'x' | $b64"], 'none');
    },
    'guard: emptying a file through a redirect, /dev/null, or a trailing xargs rm asks the user' => function () {
        guardEdgeExpect([': > ~/data/x', 'cd ~/data; : > big.log', 'cp /dev/null ~/x', 'mv ~/x /dev/null', 'find . -name a | xargs rm', 'ls *.part | xargs -0 rm'], 'approve', 'deletes or empties files');
        guardEdgeExpect(['ls ~/data 2>/dev/null', 'cp a b 2> /dev/null', 'mv a b >/dev/null 2>&1', 'echo ok > out.txt', 'echo a: > notes.txt', 'which rm'], 'none');
    },
    'guard: a destructive phrase inside quoted prose is still blocked (accepted over-blocking)' => function () use ($forkBomb) {
        guardEdgeExpect(['echo "never run rm -rf / on a server"', "git commit -m 'stop the fork bomb $forkBomb'"], 'block');
    },
    'guard: rclone serve with --addr= on loopback gets no answer, on all interfaces asks the user' => function () {
        guardEdgeExpect(['rclone serve http ~/data --addr=127.0.0.1:8080', 'rclone serve webdav ~/d --addr=localhost:9000'], 'none');
        guardEdgeExpect(['rclone serve http ~/data --addr=0.0.0.0:8080', 'rclone serve http ~/data --addr=:8080'], 'approve', 'rclone serve without a loopback --addr');
    },
    'guard: --expect asks the user on any binary, and the message carries the whole command' => function () {
        $cmd = 'php ~/x/frobnicate.php wipe 12 --expect 3GiB';
        list(, $d) = guardEdgeRun(json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
        check(is_array($d) && $d['action'] === 'approve' && strpos($d['message'], $cmd) !== false
            && $d['rule_key'] === 'pikkumies-delete:' . substr(sha1($cmd), 0, 16), 'unrelated --expect not asked: ' . json_encode($d));
        guardEdgeExpect(['./anything --expect=1', 'node tool.js "--expect" 5'], 'approve', 'deletes data through a Pikkumies tool');
    },
    'guard: a payload too deep or broken to decode blocks; empty input and other tools get no answer' => function () {
        $deep = '{"tool_name":"terminal","tool_input":{"command":"rm -rf /home/x","x":' . str_repeat('[', 600) . str_repeat(']', 600) . '}}';
        list($code, $d, $raw) = guardEdgeRun($deep);
        check($code === 0 && is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'recursive force delete') !== false, 'a 600-deep payload hid rm -rf: ' . $raw);
        foreach (['{"tool_name":"terminal","tool_input":{"command":"rm -rf', 'not json', '"a string"', '42'] as $bad) {
            list($code, $d, $raw) = guardEdgeRun($bad);
            check($code === 0 && is_array($d) && $d['action'] === 'block' && $d['message'] !== '', "undecodable payload got no block: $bad -> $raw");
        }
        list($code, , $raw) = guardEdgeRun('');
        check($code === 0 && trim($raw) === '', 'empty input must get no answer: ' . $raw);
        list($code, , $raw) = guardEdgeRun(json_encode(['tool_name' => 'write_file', 'tool_input' => ['path' => 'rm -rf x']]));
        check($code === 0 && trim($raw) === '', 'a non-terminal tool must get no answer: ' . $raw);
    },
];
