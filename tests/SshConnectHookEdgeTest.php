<?php
/**
 * hooks/guard/ssh-connect (ADR-0068): what it refuses rather than show or guess: control and direction characters, a question
 * too long for the approval panel, an unreadable host or payload, a regex engine error; and a remembered reason is cleaned.
 * sshHookTree() and sshHook() are defined in SshConnectHookTest.php; this file loads them when run alone.
 */
if (!function_exists('sshHookTree')) {
    require __DIR__ . '/SshConnectHookTest.php';
}

return [
    'ssh hook: control and direction characters, a question too long to show, an unreadable host, a bad payload and a regex engine error all block' => function () {
        $tree = sshHookTree(['ssh' => ['allow' => ['nas']]]);
        try {
            check(sshIs(sshTerm($tree, "ssh other 'echo \u{202e}cba' # intent: look at the logs"), 'block', 'control or direction'), 'bidi in an asked command');
            check(sshIs(sshTerm($tree, "ssh other ls # intent: \u{202e}look at the logs"), 'approve', 'why: look at the logs. Command [') , 'a trailing intent is shown cleaned, not raw');
            check(sshTerm($tree, "ssh nas ls # intent: \u{202e}look at the logs") === null, 'an allowed command shows nothing, so it is not refused for them');
            check(sshIs(sshTerm($tree, "ssh other ls\x1b[2K # intent: look at the logs"), 'block', 'control or direction'), 'escape in the command');
            $long = 'ssh other ls ' . str_repeat('x', 300) . ' # intent: look at the logs';
            check(sshIs(sshTerm($tree, $long), 'block', 'too long to show'), 'a question over ' . HookUnits::APPROVE_SHOWN . ' characters');
            check(sshIs(sshTerm($tree, 'ssh $HOST ls # intent: look at the logs'), 'block', 'Write each host literally'), 'variable host');
            check(sshIs(sshHook($tree, 'terminal', ['command' => str_repeat('a', 100001) . ' ssh nas']), 'block', 'over 100000'), 'huge text');
            $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$tree/pikkumies/hooks/guard/ssh-connect/ssh-connect.php"), [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], '{not json');
            fclose($pipes[0]);
            check(sshIs(json_decode(stream_get_contents($pipes[1]), true), 'block', 'could not be read'), 'bad payload');
            fclose($pipes[1]);
            proc_close($p);
            $d = sshHook($tree, 'terminal', ['command' => 'ssh nas uptime # intent: check the load average'], 's9', '1', null, '-d pcre.jit=0 -d pcre.backtrack_limit=1');
            check(sshIs($d, 'block'), 'a regex engine failure must block: ' . json_encode($d));
        } finally {
            testRmTree($tree);
        }
    },
    'ssh hook: a remembered reason is cleaned of control and direction characters before the question shows it' => function () {
        $tree = sshHookTree(['ssh' => ['allow' => []]]);
        try {
            $dir = "$tree/pikkumies/local/state/ssh-intent";
            mkdir($dir, 0700, true);
            file_put_contents("$dir/" . sha1("s1\0box.example.org"), "\u{202e}fix\x1b[31m the   printer\u{200b} queue");
            $d = sshTerm($tree, 'ssh box.example.org lpq');
            check(sshIs($d, 'approve', 'The agent says why: fix[31m the printer queue'), 'cleaned reason: ' . json_encode($d));
        } finally {
            testRmTree($tree);
        }
    },
];
