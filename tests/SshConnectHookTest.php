<?php
/**
 * hooks/guard/ssh-connect (ADR-0068): SSH connections say why, denied hosts are refused, unknown hosts and routes are asked,
 * the intent is remembered per session and host. The hook runs in a throwaway tree whose local/config.php holds the lists,
 * so this checkout's local/ is never read or written.
 */
if (!function_exists('sshHookTree')) {
    /** A pikkumies tree with lib/ and this hook; local/config.php returns $local. */
    function sshHookTree(array $local = [])
    {
        $tmp = realpath(testTempDir('pk-sshhook'));
        $src = dirname(__DIR__) . '/pikkumies';
        @mkdir("$tmp/pikkumies/hooks/guard", 0700, true);
        @mkdir("$tmp/pikkumies/local", 0700, true);
        foreach (['lib', 'hooks/guard/ssh-connect'] as $d) {
            exec('cp -R ' . escapeshellarg("$src/$d") . ' ' . escapeshellarg("$tmp/pikkumies/$d"));
        }
        file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export($local, true) . ";\n");
        return $tmp;
    }

    /** The hook's decoded answer for one call (null: no opinion). $php: extra php -d flags. */
    function sshHook($tree, $tool, array $input, $session = 's1', $depth = '1', $overlay = null, $php = '')
    {
        $payload = json_encode(['tool_name' => $tool, 'tool_input' => $input, 'session_id' => $session]);
        return testWithEnv(['PIKKUMIES_CONFIG' => $overlay, 'PIKKUMIES_DEPTH' => $depth], function () use ($tree, $payload, $php) {
            $p = proc_open(escapeshellarg(PHP_BINARY) . " $php " . escapeshellarg("$tree/pikkumies/hooks/guard/ssh-connect/ssh-connect.php"),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], $payload);
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
            return trim($out) === '' ? null : json_decode($out, true);
        });
    }

    function sshTerm($tree, $command, $session = 's1', $depth = '1', $overlay = null)
    {
        return sshHook($tree, 'terminal', ['command' => $command], $session, $depth, $overlay);
    }

    function sshIs($d, $action, $needle = '')
    {
        return is_array($d) && $d['action'] === $action && ($needle === '' || strpos($d['message'], $needle) !== false);
    }
}

return [
    'ssh hook: an allowed host with an intent passes; without one it is blocked with the format; other commands are silent' => function () {
        $tree = sshHookTree(['ssh' => ['allow' => ['nas', '*.home.example']]]);
        try {
            check(sshTerm($tree, 'ssh nas df -h # intent: check free space on the nas') === null, 'allowed with intent must pass');
            check(sshTerm($tree, 'rsync -a d/ box.home.example:d/ # intent: copy the reports over') === null, 'pattern-allowed with intent');
            $d = sshTerm($tree, 'ssh nas df -h', 'other');
            check(sshIs($d, 'block', '# intent: <why'), 'no intent: ' . json_encode($d));
            check(sshIs(sshTerm($tree, 'ssh nas df # intent: short', 'x2'), 'block', '# intent:'), 'an intent under 8 characters does not count');
            foreach (['ls -la', 'systemctl status ssh', 'git status', 'cat ~/.ssh/config'] as $c) {
                check(sshTerm($tree, $c) === null, "$c must get no opinion");
            }
        } finally {
            testRmTree($tree);
        }
    },
    'ssh hook: the intent is remembered per session and host (0700/0600), never across sessions or without a valid session id' => function () {
        $tree = sshHookTree(['ssh' => ['allow' => ['nas']]]);
        try {
            check(sshTerm($tree, 'ssh nas uptime # intent: check the load average', 's1') === null, 'first call');
            check(sshTerm($tree, 'ssh nas df -h', 's1') === null, 'the same session and host need not repeat it');
            check(sshIs(sshTerm($tree, 'ssh nas df -h', 's2'), 'block', '# intent:'), 'another session must say why again');
            check(sshIs(sshTerm($tree, 'ssh other df -h', 's1'), 'block', '# intent:'), 'another host must say why again');
            $dir = "$tree/pikkumies/local/state/ssh-intent";
            $files = glob("$dir/*");
            check(count($files) === 1 && (fileperms($dir) & 0777) === 0700 && (fileperms($files[0]) & 0777) === 0600, 'marker modes: ' . json_encode($files));
            foreach (['', '../x', str_repeat('a', 129)] as $bad) {
                check(sshTerm($tree, 'ssh nas uptime # intent: check the load average', $bad) === null, 'with an intent it still passes');
                check(sshIs(sshTerm($tree, 'ssh nas df', $bad), 'block', '# intent:'), 'no marker for a bad session id: ' . json_encode($bad));
            }
            check(count(glob("$dir/*")) === 1, 'a bad session id wrote a marker');
        } finally {
            testRmTree($tree);
        }
    },
    'ssh hook: an unknown host is asked, keyed to the command, host and reason before the command; denied hosts are refused through every name' => function () {
        $tree = sshHookTree(['ssh' => ['allow' => ['nas', 'bastion'], 'deny' => ['evil.example', '*.bad.example']]]);
        try {
            $cmd = 'scp report.pdf box.example.org:in/ # intent: hand the report to the printer box';
            $d = sshTerm($tree, $cmd);
            check(sshIs($d, 'approve') && $d['rule_key'] === 'pikkumies-ssh:' . substr(sha1($cmd), 0, 16), 'unknown: ' . json_encode($d));
            $at = strpos($d['message'], 'Command [');
            check(strpos($d['message'], 'box.example.org') < $at && strpos($d['message'], 'hand the report to the printer box') < $at, 'host and reason come first: ' . $d['message']);
            check(substr($d['message'], -strlen('scp report.pdf box.example.org:in/')) === 'scp report.pdf box.example.org:in/', 'the trailing intent is not shown twice');
            foreach (['ssh evil.example ls', 'ssh x.bad.example ls', 'ssh -o HostName=evil.example nas ls', 'ssh -J evil.example nas ls',
                'ssh -o "ProxyCommand ssh -W %h:%p evil.example" nas ls', 'ssh -W evil.example:22 bastion', 'ssh EVIL.example. ls', "ssh nas 'ssh evil.example'"] as $c) {
                $d = sshTerm($tree, $c);
                check(sshIs($d, 'block', 'evil.example is on the user\'s SSH deny list') || sshIs($d, 'block', 'x.bad.example is on'), "$c: " . json_encode($d));
            }
        } finally {
            testRmTree($tree);
        }
    },
    'ssh hook: strict mode refuses unknown hosts and routes; routes are asked by name; execute_code SSH is refused' => function () {
        $tree = sshHookTree(['ssh' => ['allow' => ['nas'], 'blockUnknown' => true]]);
        try {
            check(sshIs(sshTerm($tree, 'ssh other ls # intent: look at the logs'), 'block', 'ssh.blockUnknown'), 'strict: unknown host');
            check(sshIs(sshTerm($tree, 'ssh -F cfg nas ls # intent: look at the logs'), 'block', 'ssh.blockUnknown'), 'strict: a route');
            check(sshTerm($tree, 'ssh nas ls # intent: look at the logs') === null, 'strict: allowed passes');
            file_put_contents("$tree/pikkumies/local/config.php", '<?php return ' . var_export(['ssh' => ['allow' => ['nas']]], true) . ";\n");
            foreach (['ssh -F cfg nas ls' => '-F cfg', 'ssh -o "ProxyCommand connect-proxy %h %p" nas ls' => 'ProxyCommand connect-proxy',
                'rsync -e rsh a nas:b' => '--rsh rsh', "GIT_SSH_COMMAND='ssh -J x' git push" => 'GIT_SSH_COMMAND'] as $c => $route) {
                check(sshIs(sshTerm($tree, "$c # intent: look at the logs"), 'approve', $route), "$c must be asked naming $route");
            }
            foreach (['import paramiko', 'subprocess.run(["ssh", "nas"])', 'os.system("rsync -a a nas:b")'] as $code) {
                check(sshIs(sshHook($tree, 'execute_code', ['code' => $code]), 'block', 'from the terminal'), "execute_code: $code");
            }
            check(sshIs(sshHook($tree, 'process_manage', ['action' => 'submit', 'data' => 'ssh other ls']), 'block', '# intent:'), 'process input is read');
        } finally {
            testRmTree($tree);
        }
    },
    'ssh hook: writes to ~/.ssh/config are asked (file tools, V4A headers, shell); nested agents get a block; the overlay is ignored' => function () {
        $tree = sshHookTree(['ssh' => ['allow' => ['nas']]]);
        try {
            check(sshIs(sshHook($tree, 'write_file', ['path' => '~/.ssh/config', 'content' => "Host nas\n  HostName 10.0.0.9\n"]), 'approve', 'writes ~/.ssh/config'), 'write_file');
            check(sshIs(sshHook($tree, 'patch', ['patch' => "*** Begin Patch\n*** Update File: /home/u/.ssh//config\n@@\n-a\n+b\n*** End Patch"]), 'approve', '~/.ssh/config'), 'V4A');
            check(sshIs(sshTerm($tree, "echo 'Host nas' >> ~/.ssh/config"), 'approve', '~/.ssh/config'), 'shell append');
            check(sshHook($tree, 'write_file', ['path' => '~/.ssh/known_hosts.txt', 'content' => 'x']) === null, 'another file');
            $d = sshTerm($tree, 'ssh other ls # intent: look at the logs', 's1', '2');
            check(sshIs($d, 'block', 'started by another agent'), 'depth 2: ' . json_encode($d));
            $over = "$tree/overlay.php";
            file_put_contents($over, '<?php return ' . var_export(['ssh' => ['allow' => ['*'], 'deny' => []]], true) . ";\n");
            chmod($over, 0600);
            check(sshIs(sshTerm($tree, 'ssh other ls # intent: look at the logs', 's1', '1', $over), 'approve'), 'a PIKKUMIES_CONFIG overlay must not allow');
        } finally {
            testRmTree($tree);
        }
    },
];
