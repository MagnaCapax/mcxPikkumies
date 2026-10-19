<?php
/**
 * tools/backup/remote setup (ADR-0049) against a fake ssh that plays the backup machine and runs the real receiver:
 * argument injection, where the one-time login may and may not go, host-key pinning, the key restriction proof, and the
 * delete guard and command log rules that go with it.
 */
require_once __DIR__ . '/fixtures/backupremote/harness.php';

return [
    'backup remote setup: option-like hosts and bad account names are refused before any program runs' => function () {
        $fx = brFixture();
        try {
            foreach ([['-oProxyCommand=sh -c x', 'u'], ['--', '-oProxyCommand=x', 'u'], ['-p', 'u'], ['a b', 'u'], ['h;id', 'u'], ['', 'u'],
                [BR_HOST, '-oX'], [BR_HOST, 'Root'], [BR_HOST, 'a b'], [BR_HOST, '$(id)'], [BR_HOST]] as $args) {
                list($code, $l) = brRun($fx, array_merge(['setup'], $args), ['PIKKUMIES_REMOTE_PASSWORD' => BR_LOGIN]);
                check($code !== 0 && isset(end($l)['ok']) && end($l)['ok'] === false, 'accepted: ' . json_encode($args));
            }
            check(stLog($fx) === [], 'a program ran for a refused setup: ' . json_encode(stLog($fx)));
            list($code, $l) = brRun($fx, ['setup', BR_HOST, 'xyzjohn', '--port', '0']);
            check($code === 1 && stLog($fx) === [], 'port 0 accepted');
        } finally {
            stDone($fx);
        }
    },
    'backup remote setup: the login reaches ssh only through askpass, in the one login call; never an argv, the output or a file' => function () {
        $fx = brFixture();
        try {
            list($code, $l) = brRun($fx, ['setup', BR_HOST, 'xyzjohn']);
            check($code === 1 && strpos(end($l)['hint'], 'PIKKUMIES_REMOTE_PASSWORD') !== false && stLog($fx) === [], 'no login: ' . json_encode($l));
            list($code, $l, $out) = brSetup($fx);
            check($code === 0 && $l[0]['verified'] === true && $l[0]['restricted'] === true, 'setup: ' . $out);
            check(strpos($out, 'change the remote password') !== false && strpos($out, 'model provider') !== false, 'scrub scope not told: ' . $out);
            $calls = brSshCalls($fx);
            $withLogin = array_values(array_filter($calls, function ($c) {
                return $c['pw_set'];
            }));
            check(count($withLogin) === 1 && $withLogin[0]['askpass_force'] && strpos(implode(' ', $withLogin[0]['argv']), 'sh -s') !== false,
                'the login must be in exactly the one login call: ' . json_encode($calls));
            foreach (stLog($fx) as $c) {
                check($c['bin'] === 'ssh' || empty($c['pw_set']), "{$c['bin']} got the login in its environment");
            }
            $files = brAllText($fx['localdir']) . brAllText($fx['remote']);
            foreach ([BR_LOGIN, 'Fake\'Login'] as $needle) {
                check(strpos((string) file_get_contents($fx['dir'] . '/argv.log'), $needle) === false, 'the login reached an argv');
                check(strpos($out, $needle) === false, 'the login reached the output');
                check(strpos($files, $needle) === false, 'the login was written to a file');
            }
        } finally {
            stDone($fx);
        }
    },
    'backup remote askpass: prints the login from its own environment, only for a login prompt' => function () {
        $ask = function ($prompt, $env) {
            $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/pikkumies/tools/backup/remote/askpass.php') . ' '
                . escapeshellarg($prompt), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => (string) getenv('PATH')]);
            $out = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($p), $out];
        };
        $env = ['PIKKUMIES_REMOTE_PASSWORD' => BR_LOGIN];
        check($ask("xyzjohn@backup.example.com's password", $env) === [0, BR_LOGIN . "\n"], 'login prompt not answered');
        check($ask('Password for xyzjohn@backup.example.com', $env) === [0, BR_LOGIN . "\n"], 'keyboard-interactive prompt not answered');
        foreach (['Are you sure you want to continue connecting (yes/no/[fingerprint])?', "Enter passphrase for key '/k'", 'Verification code'] as $p) {
            check($ask($p, $env) === [1, ''], "answered a prompt that is not the login: $p");
        }
        check($ask("u@h's password", []) === [1, ''], 'answered without the variable');
    },
    'backup remote setup: the host key is pinned by a call with no login, every later call checks it; a wrong --fingerprint stops before the login' => function () {
        $fx = brFixture();
        try {
            list($code, $l, $out) = brSetup($fx, ['--fingerprint', 'SHA256:' . str_repeat('A', 43)]);
            $calls = brSshCalls($fx);
            check($code === 1 && strpos(end($l)['error'], 'host key') !== false && strpos(end($l)['hint'], 'ED25519, ECDSA') !== false, 'mismatch: ' . $out);
            check(count($calls) === 4 && !array_filter(array_column($calls, 'pw_set')), 'a mismatch must probe each key type once, never log in: ' . json_encode($calls));
            check(!is_file($fx['secrets'] . '/key') && !is_file($fx['secrets'] . '/dest.json') && !glob($fx['secrets'] . '/*.new'), 'files kept');
            stClearLog($fx);
            list($code, $l, $out) = brSetup($fx, ['--fingerprint', brFingerprint()]);
            check($code === 0 && $l[0]['host_key'] === brFingerprint(), 'matching fingerprint: ' . $out);
            $calls = brSshCalls($fx);
            check($calls[0]['opt']['StrictHostKeyChecking'] === 'accept-new' && $calls[0]['opt']['PubkeyAuthentication'] === 'no'
                && !$calls[0]['pw_set'] && count($calls) === 3, 'first call must pin the key without a login: ' . json_encode($calls));
            foreach (array_slice($calls, 1) as $c) {
                check($c['opt']['StrictHostKeyChecking'] === 'yes' && $c['argv'][0] === '-F' && $c['argv'][1] === '/dev/null'
                    && $c['opt']['HostKeyAlgorithms'] === 'ssh-ed25519', 'later call: ' . json_encode($c));
            }
            brSetState($fx, ['host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAISomeoneElseInBetween00000000000000']);
            foreach ([['list'], ['run', '--mode', 'meta'], ['restore', '20261018T030000Z.meta.tgz.gpg', '--target', $fx['home'] . '/r']] as $args) {
                list($code, $l, $out) = brRun($fx, $args);
                check($code === 1 && strpos(end($l)['error'], 'identity') !== false && strpos(end($l)['hint'], '--fingerprint') !== false
                    && strpos($out, 'ssh-keygen') === false && strpos($out, 'network') === false, 'changed host key on ' . $args[0] . ': ' . $out);
            }
        } finally {
            stDone($fx);
        }
    },
    'backup remote setup: a --fingerprint of another key type than the client negotiates is found and that type pinned' => function () {
        $ed = 'AAAAC3NzaC1lZDI1NTE5AAAAIEd25519KeyOfTheFakeBackupMachine00000';
        $ec = 'AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYEcdsaKeyOfFake0';
        $fx = brFixture(['host_keys' => ['ssh-ed25519' => $ed, 'ecdsa-sha2-nistp256' => $ec]]);
        try {
            list($code, $l, $out) = brSetup($fx, ['--fingerprint', brFingerprint($ec)]);
            check($code === 0 && $l[0]['host_key'] === brFingerprint($ec) && $l[0]['host_key_alg'] === 'ecdsa-sha2-nistp256', 'ECDSA pin: ' . $out);
            $calls = brSshCalls($fx);
            $login = array_values(array_filter($calls, function ($c) {
                return $c['pw_set'];
            }));
            check(count($login) === 1 && $login[0]['opt']['HostKeyAlgorithms'] === 'ecdsa-sha2-nistp256', 'the login must use the pinned type');
            check(strpos((string) file_get_contents($fx['secrets'] . '/known_hosts'), "ecdsa-sha2-nistp256 $ec") !== false, 'known_hosts');
            list($code, , $out) = brRun($fx, ['list']);
            check($code === 0, 'later connections must keep to the pinned type: ' . $out);
        } finally {
            stDone($fx);
        }
    },
    'backup remote setup: a key the server does not restrict, or a refused login, keeps nothing here' => function () {
        foreach ([['ignore_restrict' => true], ['login_sha1' => sha1('another')]] as $state) {
            $fx = brFixture($state);
            try {
                list($code, $l, $out) = brSetup($fx);
                check($code === 1 && end($l)['ok'] === false, 'setup passed: ' . $out);
                foreach (['key', 'key.pub', 'key.new', 'dest.json', 'pass'] as $f) {
                    check(!is_file($fx['secrets'] . "/$f"), "$f kept after a refused setup: " . $out);
                }
                if (!empty($state['ignore_restrict'])) {
                    check(strpos(end($l)['error'], 'not limited') !== false && strpos(end($l)['hint'], 'authorized_keys') !== false, $out);
                }
            } finally {
                stDone($fx);
            }
        }
    },
    'backup remote setup: installs the real receiver and one restrict,command= line; files are private; a second setup keeps the passphrase' => function () {
        $fx = brFixture();
        try {
            check(brSetup($fx)[0] === 0, 'setup failed');
            $recv = dirname(__DIR__) . '/pikkumies/tools/backup/remote/recv.sh';
            check(rtrim((string) @file_get_contents($fx['remote'] . '/.pikkumies-recv/recv.sh')) === rtrim(file_get_contents($recv)), 'receiver differs');
            $keys = file($fx['remote'] . '/.ssh/authorized_keys', FILE_IGNORE_NEW_LINES);
            check(count($keys) === 1 && preg_match('#^restrict,command="sh ~/\.pikkumies-recv/recv\.sh [A-Za-z0-9._-]+ 30" ssh-ed25519 \S+ pikkumies-backup-#',
                $keys[0]), 'key line: ' . json_encode($keys));
            check((fileperms($fx['secrets']) & 0777) === 0700, 'secrets directory mode');
            foreach (['key', 'pass', 'dest.json', 'known_hosts'] as $f) {
                check((fileperms($fx['secrets'] . "/$f") & 0777) === 0600, "$f mode");
            }
            $pass = file_get_contents($fx['secrets'] . '/pass');
            check(preg_match('/^[0-9a-f]{48}\n$/', $pass) === 1, 'passphrase format');
            check(brSetup($fx, ['--keep-days', '7'])[0] === 0 && file_get_contents($fx['secrets'] . '/pass') === $pass, 'a second setup replaced the passphrase');
            check(strpos(file_get_contents($fx['remote'] . '/.ssh/authorized_keys'), ' 7" ssh-ed25519') !== false, 'keep-days not in the forced command');
        } finally {
            stDone($fx);
        }
    },
    'backup remote guard: setup, schedule and the login prefix ask the user; passphrase is blocked; run, list and stats pass' => function () {
        $hook = dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php';
        $ask = function ($cmd) use ($hook) {
            list(, $out) = runPhp($hook, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
            return json_decode($out, true);
        };
        foreach (["PIKKUMIES_REMOTE_PASSWORD='a'\\''b' php ~/pikkumies/tools/backup/remote/remote.php setup h u",
            'PIKKUMIES_REMOTE_PASSWORD=\'a$b\'\\\'\'$(c)\' php ~/pikkumies/tools/backup/remote/remote.php setup h u',
            'cd ~ && PIKKUMIES_REMOTE_PASSWORD=x php $R setup h u', 'php ~/pikkumies/tools/backup/remote/remote.php schedule --daily 04:30 --mode meta',
            'php $R schedule --daily=04:30 --mode full'] as $cmd) {
            $d = $ask($cmd);
            check(is_array($d) && $d['action'] === 'approve' && strpos($d['rule_key'], 'pikkumies-send:') === 0 && strpos($d['message'], $cmd) !== false,
                "not asked: $cmd -> " . json_encode($d));
        }
        foreach (['php ~/pikkumies/tools/backup/remote/remote.php passphrase', 'php $R passphrase --confirm-saved', 'V=passphrase; php remote.php $V',
            'php ~/pikkumies/tools/backup/remote/remote.php $(echo pass)phrase', 'php remote.php `echo passphrase`', 'eval php remote.php pass""phrase',
            'script -qc "php ~/pikkumies/tools/backup/remote/remote.php passphrase" /dev/null',
            'PIKKUMIES_REMOTE_PASSWORD="$(cat ~/x)" php ~/pikkumies/tools/backup/remote/remote.php setup h u'] as $cmd) {
            $d = $ask($cmd);
            check(is_array($d) && $d['action'] === 'block', "passphrase not blocked: $cmd");
        }
        foreach (['php $R run --mode meta', 'php $R list', 'php $R stats', 'gpg --passphrase-file f -d x', 'echo $HOME'] as $cmd) {
            check($ask($cmd) === null, "flagged: $cmd");
        }
    },
    'backup remote command log: a login prefix with a quote, a space or an escape is removed as a whole word' => function () {
        if (!function_exists('cmdLogTree')) {
            require __DIR__ . '/CommandLogTest.php';
        }
        $tree = cmdLogTree();
        try {
            $unit = "$tree/pikkumies/hooks/trace/command-log/command-log.php";
            foreach (["PIKKUMIES_REMOTE_PASSWORD='Pw'\\''Goat9' php r.php setup h u", 'PIKKUMIES_REMOTE_PASSWORD="Pw Goat9" php r.php setup h u',
                'PIKKUMIES_REMOTE_PASSWORD=Pw\\ Goat9 php r.php setup h u', "PIKKUMIES_REMOTE_PASSWORD=\$'Pw\\'Goat9' php r.php setup h u"] as $cmd) {
                runPhp($unit, [], cmdLogPayload('terminal', ['command' => $cmd], json_encode(['output' => '', 'exit_code' => 0])));
            }
            $lines = cmdLogLines($tree);
            check(count($lines) === 4, 'expected 4 lines: ' . json_encode($lines));
            foreach ($lines as $l) {
                check(strpos($l['command'], 'Goat9') === false && strpos($l['command'], 'PIKKUMIES_REMOTE_PASSWORD=REDACTED php r.php setup h u') === 0,
                    'logged: ' . $l['command']);
            }
        } finally {
            testRmTree($tree);
        }
    },
    'backup remote setup: a host on the person\'s SSH deny list is refused before any program runs (ADR-0068)' => function () {
        $fx = brFixture();
        try {
            file_put_contents($fx['localdir'] . '/config.php', '<?php return ' . var_export(['ssh' => ['deny' => ['*.example.com']]], true) . ";\n");
            list($code, $l, $out) = brSetup($fx);
            check($code === 1 && strpos(end($l)['error'], 'SSH deny list') !== false, 'denied host: ' . $out);
            check(stLog($fx) === [] && !is_file($fx['secrets'] . '/dest.json'), 'a program ran or a file was kept: ' . json_encode(stLog($fx)));
            file_put_contents($fx['localdir'] . '/config.php', '<?php return ' . var_export(['ssh' => ['deny' => ['other.example.org']]], true) . ";\n");
            check(brSetup($fx)[0] === 0, 'a deny list without this host stops nothing');
        } finally {
            stDone($fx);
        }
    },
];
