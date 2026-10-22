<?php
/**
 * lib/Services.php and the services unit (ADR-0084) against backup remote's fake ssh, which plays the other machine and,
 * like sshd, runs a key's command= (here the real serve.php through a wrapper with test seams): the list, the ssh argv,
 * setup's login and key proof, accept, remote and the memory convention.
 */
require_once __DIR__ . '/fixtures/services/harness.php';

return [
    'services list: put/get/all/remove round-trip, private file, missing service names the fix, null on request' => function () {
        $tmp = testTempDir('pk-services');
        Services::$localDir = $tmp;
        try {
            $s = Services::put(['nick' => 'hdd1', 'host' => 'box.example.com', 'user' => 'xyzjohn', 'tags' => ['seedbox', 'backup', 'seedbox'], 'notes' => "big \x07box"]);
            check($s['tags'] === ['seedbox', 'backup'] && $s['transport'] === 'pikkumies' && $s['port'] === 22 && $s['notes'] === 'big box', json_encode($s));
            check(Services::get('hdd1') === $s && array_keys(Services::all()) === ['hdd1'], 'get/all');
            check((fileperms(Services::path()) & 0777) === 0600, sprintf('mode %o', fileperms(Services::path()) & 0777));
            check(Services::get('nope', false) === null, 'null when not required');
            $threw = false;
            try {
                Services::get('nope');
            } catch (CliError $e) {
                $threw = strpos($e->hint, 'services list') !== false;
            }
            check($threw, 'missing service must throw with a hint');
            Services::remove('hdd1');
            check(Services::all() === [], 'removed');
        } finally {
            Services::$localDir = null;
            testRmTree($tmp);
        }
    },
    'services list: an all-digit tag (e.g. "1080") is normalized and stored back as the string "1080", not the number 1080 — PHP turns an all-digit array key into an integer, so without the strval fix the line would be written as the JSON number 1080, and re-normalizing it (as a later `edit` does) would then throw "not a tag: 1080" (normalize()\'s own is_string check)' => function () {
        $tmp = testTempDir('pk-services-digit-tag');
        Services::$localDir = $tmp;
        try {
            $s = Services::put(['nick' => 'hdd1', 'host' => 'box.example.com', 'user' => 'xyzjohn', 'tags' => ['seedbox', '1080']]);
            check($s['tags'] === ['seedbox', '1080'] && is_string($s['tags'][1]), 'tags must stay strings: ' . json_encode($s['tags']));
            check(strpos((string) file_get_contents(Services::path()), '"1080"') !== false, 'the stored line must hold the JSON string "1080", not the number 1080: ' . file_get_contents(Services::path()));
            // What `edit` does under the hood (get the stored record, re-normalize, put it back): must not throw
            // "not a tag: 1080" and must still keep the tag as a string, not silently revert to a number.
            $s2 = Services::put(Services::get('hdd1'));
            check($s2['tags'] === ['seedbox', '1080'] && is_string($s2['tags'][1]), 're-normalizing an already-stored record must keep the tag a string: ' . json_encode($s2['tags']));
            // A line written by the code before this fix holds the JSON number 1080: `edit` on it must still work.
            file_put_contents(Services::path(), str_replace('"1080"', '1080', (string) file_get_contents(Services::path())));
            $old = Services::get('hdd1');
            check($old['tags'][1] === 1080, 'fixture: the stored tag must be the JSON number 1080: ' . json_encode($old['tags']));
            $s3 = Services::put($old);
            check($s3['tags'] === ['seedbox', '1080'], 'a numeric tag stored by older code must be read back as the string "1080": ' . json_encode($s3['tags']));
        } finally {
            Services::$localDir = null;
            testRmTree($tmp);
        }
    },
    'services list: option-like hosts, bad names, tags, transports and providers are refused' => function () {
        $base = ['nick' => 'hdd1', 'host' => 'box.example.com', 'user' => 'xyzjohn'];
        foreach ([['host' => '-oProxyCommand=sh'], ['host' => 'a b'], ['host' => 'h;id'], ['host' => '127.1'], ['user' => '-l'], ['user' => 'Root'],
            ['user' => '$(id)'], ['nick' => '../x'], ['nick' => 'A'], ['tags' => ['ok', 'Bad Tag']], ['transport' => 'telnet'], ['provider' => 'x'],
            ['port' => '0'], ['port' => '22;id'], ['home' => 'relative'], ['clients' => ['mldonkey' => []]],
            ['nick' => "hdd1\n"], ['host' => "box.example.com\n"], ['user' => "xyzjohn\n"], ['port' => "22\n"]] as $bad) {
            $threw = false;
            try {
                Services::normalize($bad + $base);
            } catch (CliError $e) {
                $threw = true;
            }
            check($threw, 'accepted: ' . json_encode($bad));
        }
    },
    'services sshArgv: an argv with -F /dev/null, its own key and known_hosts, strict checking, -- before the host; hostile words refused' => function () {
        $tmp = testTempDir('pk-services');
        Services::$localDir = $tmp;
        Services::$configFile = "$tmp/config.php";
        try {
            Services::put(['nick' => 'hdd1', 'host' => 'box.example.com', 'user' => 'xyzjohn', 'port' => 2222]);
            $dir = Services::secretDir('hdd1');
            mkdir($dir, 0700, true);
            touch("$dir/key");
            touch("$dir/known_hosts");
            $a = Services::sshArgv('hdd1', ['tool', 'system/system-status', 'account']);
            $i = array_search('--', $a, true);
            check(basename($a[0]) === 'ssh' && $a[1] === '-F' && $a[2] === '/dev/null' && $a[$i + 1] === 'box.example.com'
                && array_slice($a, $i + 2) === ['tool', 'system/system-status', 'account'], json_encode($a));
            foreach (['IdentitiesOnly=yes', 'BatchMode=yes', 'StrictHostKeyChecking=yes', "UserKnownHostsFile=$dir/known_hosts"] as $o) {
                check(in_array($o, $a, true), "missing -o $o");
            }
            // Review F5: every word plain (move hands this argv to rsync -e, which refuses quoted words).
            check(!array_filter($a, function ($w) {
                return strpbrk($w, "\"' ") !== false;
            }), 'a quoted or spaced word in the ssh argv: ' . json_encode($a));
            foreach (['/tmp/a b', '/tmp/a%d', '/tmp/a"b', '~/x'] as $bad) {
                $threw = false;
                try {
                    Services::baseArgv(Services::get('hdd1'), "$bad/known_hosts", 'yes');
                } catch (CliError $e) {
                    $threw = true;
                }
                check($threw, 'known_hosts path accepted: ' . $bad);
            }
            check(in_array("$dir/key", $a, true) && in_array('2222', $a, true), 'key/port');
            foreach (['a b', "a'b", 'a"b', '$(id)', '`id`', 'a;b', 'a|b', 'a&b', 'a>b', "a\nb", "a\0b", 'a\\b', 'film é', ''] as $w) {
                $threw = false;
                try {
                    Services::sshArgv('hdd1', ['tool', 'torrent-clients/find', 'find', $w]);
                } catch (CliError $e) {
                    $threw = true;
                }
                check($threw, 'pikkumies transport accepted ' . json_encode($w));
            }
            Services::put(['nick' => 'gpu', 'host' => 'gpu.example.com', 'user' => 'xyzjohn', 'transport' => 'shell']);
            mkdir(Services::secretDir('gpu'), 0700, true);
            touch(Services::secretDir('gpu') . '/key');
            touch(Services::secretDir('gpu') . '/known_hosts');
            $a = Services::sshArgv('gpu', ['echo', "it's \$(id)"]);
            check(end($a) === "'it'\\''s \$(id)'", 'shell transport must single-quote each word: ' . end($a));
            Services::put(['nick' => 'tun', 'host' => 'tun.example.com', 'user' => 'xyzjohn', 'transport' => 'tunnel']);
            mkdir(Services::secretDir('tun'), 0700, true);
            touch(Services::secretDir('tun') . '/key');
            touch(Services::secretDir('tun') . '/known_hosts');
            $a = Services::sshArgv('tun', [], ['tunnel' => ['local' => '15000', 'remote' => '/home/fixtureuser/.rtorrent.socket']]);
            check(in_array('-N', $a, true) && in_array('127.0.0.1:15000:/home/fixtureuser/.rtorrent.socket', $a, true) && end($a) === 'tun.example.com', json_encode($a));
            file_put_contents("$tmp/config.php", "<?php\nreturn ['ssh' => ['deny' => ['*.example.com']]];\n");
            $threw = false;
            try {
                Services::sshArgv('hdd1', ['ping']);
            } catch (CliError $e) {
                $threw = strpos($e->getMessage(), 'deny') !== false;
            }
            check($threw, 'a denied host must be refused before any connection');
            file_put_contents("$tmp/config.php", "<?php\nreturn ['ssh' => ['allow' => ['other.example.org'], 'blockUnknown' => true]];\n");
            $threw = false;
            try {
                Services::sshArgv('hdd1', ['ping']);
            } catch (CliError $e) {
                $threw = strpos($e->getMessage(), 'blockUnknown') !== false;
            }
            check($threw, 'ssh.blockUnknown must refuse a host not on the allow list');
        } finally {
            Services::$localDir = Services::$configFile = null;
            testRmTree($tmp);
        }
    },
    'services fanout: results keyed by nick in order, a failing or slow service gives its own error record, the others still answer' => function () {
        $tmp = testTempDir('pk-services');
        Services::$localDir = $tmp;
        $parent = getmypid();
        $marker = "$tmp/child-ran-shutdown";
        register_shutdown_function(function () use ($parent, $marker) {
            if (getmypid() !== $parent) {
                @touch($marker); // a forked worker must never run the parent's shutdown functions
            }
        });
        try {
            $r = Services::fanout(['a', 'b', 'c', 'd', 'e'], function ($n) {
                if ($n === 'b') {
                    throw new CliError('b is down', 'later');
                }
                if ($n === 'd' && function_exists('pcntl_fork')) {
                    sleep(5); // without pcntl the calls run one after another, with no per-call timeout
                }
                return [['service' => $n, 'ok' => true, 'pid' => getmypid()]];
            }, 3, 2);
            check(array_keys($r) === ['a', 'b', 'c', 'd', 'e'], 'order: ' . json_encode(array_keys($r)));
            check($r['b'][0]['ok'] === false && $r['b'][0]['error'] === 'b is down', 'error record: ' . json_encode($r['b']));
            check($r['a'][0]['ok'] === true && $r['e'][0]['ok'] === true, 'others must answer');
            if (function_exists('pcntl_fork')) {
                check($r['a'][0]['pid'] !== getmypid(), 'with pcntl the calls run in child processes');
                check($r['d'][0]['ok'] === false && strpos($r['d'][0]['error'], 'within') !== false, 'timeout record: ' . json_encode($r['d']));
            }
            check(glob("$tmp/state/tmp/fanout-*") === [], 'result files left behind');
            check(!file_exists($marker), 'a forked worker ran the parent shutdown functions');
        } finally {
            Services::$localDir = null;
            testRmTree($tmp);
        }
    },
    'services setup: the login only via askpass in the one login call, a restrict,command=serve.php key line, ping proves it, private files' => function () {
        $fx = svFixture();
        try {
            list($code, $l, $out) = svAddAndSetup($fx, 'hdd1', ['--allow-delete']);
            check($code === 0 && $l[0]['allow_delete'] === true && end($l)['verified'] === true, 'setup: ' . $out);
            $calls = brSshCalls($fx);
            $login = array_values(array_filter($calls, function ($c) {
                return $c['pw_set'];
            }));
            check(count($login) === 1 && $login[0]['askpass_force'] && in_array('sh', $login[0]['argv'], true), 'login call: ' . json_encode($calls));
            check($calls[0]['opt']['StrictHostKeyChecking'] === 'accept-new' && $calls[0]['opt']['PubkeyAuthentication'] === 'no' && !$calls[0]['pw_set'], 'pin first');
            foreach (array_slice($calls, 1) as $c) {
                check($c['opt']['StrictHostKeyChecking'] === 'yes' && $c['argv'][0] === '-F' && $c['argv'][1] === '/dev/null', 'later call: ' . json_encode($c));
            }
            $keys = file($fx['remote'] . '/.ssh/authorized_keys', FILE_IGNORE_NEW_LINES);
            check(count($keys) === 1 && preg_match('#^restrict,command="php ~/pikkumies/tools/seedbox/services/serve\.php [A-Za-z0-9._-]+ --allow-delete" ssh-ed25519 \S+ pikkumies-#', $keys[0]), json_encode($keys));
            $dir = $fx['localdir'] . '/secrets/services/hdd1';
            foreach (['key', 'key.pub', 'known_hosts'] as $f) {
                check(is_file("$dir/$f") && (fileperms("$dir/$f") & 077) === 0, "$f missing or readable by others");
            }
            check(!glob("$dir/*.new"), 'setup left .new files');
            $files = brAllText($fx['localdir']) . (string) file_get_contents($fx['dir'] . '/argv.log') . $out;
            check(strpos($files, BR_LOGIN) === false, 'the login reached a file, an argv or the output');
            check(svServeLog($fx)[0]['request'] === 'ping' && svServeLog($fx)[0]['decision'] === 'allow', 'serve logged the proof');
        } finally {
            stDone($fx);
        }
    },
    'services setup: a box that ignores restrict, a box without Pikkumies, a missing login or a denied host keep nothing here' => function () {
        foreach ([[['ignore_restrict' => true], true, 'does not do'], [[], false, 'not installed']] as $case) {
            list($state, $serve, $needle) = $case;
            $fx = svFixture($state, $serve);
            try {
                list($code, $l, $out) = svAddAndSetup($fx);
                check($code === 1 && strpos(end($l)['error'], $needle) !== false, "expected '$needle': $out");
                $dir = $fx['localdir'] . '/secrets/services/hdd1';
                check(!is_file("$dir/key") && !is_file("$dir/known_hosts") && !glob("$dir/*.new"), 'files kept after a refused setup');
            } finally {
                stDone($fx);
            }
        }
        $fx = svFixture();
        try {
            svRun($fx, ['add', 'hdd1', '--host', BR_HOST, '--user', 'xyzjohn']);
            stClearLog($fx);
            list($code, $l) = svRun($fx, ['setup', 'hdd1']);
            check($code === 1 && strpos(end($l)['hint'], 'PIKKUMIES_REMOTE_PASSWORD') !== false && stLog($fx) === [], 'no login');
            file_put_contents($fx['localdir'] . '/config.php', "<?php\nreturn ['ssh' => ['deny' => ['" . BR_HOST . "']]];\n");
            list($code, $l) = svRun($fx, ['setup', 'hdd1'], ['PIKKUMIES_REMOTE_PASSWORD' => BR_LOGIN]);
            check($code === 1 && strpos(end($l)['error'], 'deny') !== false && stLog($fx) === [], 'denied host must be refused before ssh');
        } finally {
            stDone($fx);
        }
    },
    'services remote: statistics come back as the tool\'s own records with service; refused verbs never connect; changes need one service and an intent' => function () {
        $fx = svFixture();
        try {
            check(svAddAndSetup($fx)[0] === 0, 'setup');
            list($code, $l, $out) = svRun($fx, ['remote', '--tag', 'seedbox', 'system/system-status', 'account']);
            check($code === 0 && $l[0]['service'] === 'hdd1' && $l[0]['unit'] === 'system-status' && $l[0]['argv'] === ['account']
                && $l[1] === ['service' => 'hdd1', 'name' => 'Some Film', 'size' => 123] && end($l)['failed'] === [], 'remote: ' . $out);
            check($l[0]['cwd'] === $fx['remote'], 'serve runs the tool in the home');
            stClearLog($fx);
            foreach ([['remote', 'hdd1', 'system/system-status', 'logs'], ['remote', 'hdd1', 'torrent-clients/rtorrent', 'restart'],
                ['remote', 'hdd1', 'storage/quota', 'show']] as $args) {
                list($code, $l) = svRun($fx, $args);
                check($code === 1 && strpos(end($l)['error'], 'serve allows') !== false, 'not refused: ' . json_encode($args));
            }
            list($code, $l) = svRun($fx, ['remote', 'hdd1', 'torrent-clients/rtorrent', 'pause', 'ABC']);
            check($code === 1 && strpos(end($l)['error'], '--intent') !== false, 'pause without intent');
            list($code, $l) = svRun($fx, ['remote', '--all', 'torrent-clients/rtorrent', 'pause', 'ABC', '--intent', 'pause everything now']);
            check($code === 1 && strpos(end($l)['error'], 'one service') !== false, 'changing verb on --all');
            list($code, $l) = svRun($fx, ['remote', '--all', 'torrent-clients/rtorrent', 'export', 'ABC']);
            check($code === 1 && strpos(end($l)['error'], 'passkey') !== false, 'review F4: export must not run through remote');
            list($code, $l) = svRun($fx, ['remote', 'hdd1', 'torrent-clients/find', 'find', 'a b']);
            check($code === 1, 'a word with a space must be refused here');
            check(stCalls($fx, 'ssh') === [], 'a refused remote call reached ssh');
            list($code, $l, $out) = svRun($fx, ['remote', 'hdd1', 'torrent-clients/rtorrent', 'pause', 'ABC', '--intent', 'pause ABC on hdd1 please']);
            check($code === 0 && $l[0]['argv'] === ['pause', 'ABC'], 'pause: ' . $out);
            $intents = file($fx['localdir'] . '/state/intents.jsonl', FILE_IGNORE_NEW_LINES);
            check(count($intents) === 1 && json_decode($intents[0], true)['intent'] === 'pause ABC on hdd1 please', 'intent not recorded');
            list($code, $l, $out) = svRun($fx, ['remote', 'hdd1', 'torrent-clients/rtorrent', 'remove', 'ABC', '--intent', 'remove ABC and its data', '--', '--with-data', '--expect', '5']);
            check($code === 0 && end($l)['failed'] === ['hdd1'] && strpos($l[0]['error'], '--with-data is refused') === 0, 'the key without --allow-delete must not delete: ' . $out);
        } finally {
            stDone($fx);
        }
    },
    'services remote add: a local .torrent goes on stdin, lands in serve\'s temp file for the call and is removed; never a link' => function () {
        $fx = svFixture();
        try {
            check(svAddAndSetup($fx)[0] === 0, 'setup');
            $torrent = svTorrent();
            file_put_contents($fx['home'] . '/x.torrent', $torrent);
            list($code, $l, $out) = svRun($fx, ['remote', 'hdd1', 'torrent-clients/rtorrent', 'add', $fx['home'] . '/x.torrent', '--intent', 'add x to hdd1 paused',
                '--', '--paused', '--dir', '~/data/films']);
            check($code === 0 && $l[0]['file'] === $torrent && $l[0]['argv'][0] === 'add' && $l[0]['argv'][2] === '--paused'
                && $l[0]['argv'][4] === $fx['remote'] . '/data/films', 'add: ' . $out);
            check(glob($fx['rlocal'] . '/state/tmp/serve-*') === [], 'the temp .torrent was left');
            $log = (string) file_get_contents($fx['rlocal'] . '/state/services-serve.jsonl');
            check(strpos($log, 'announce') === false && strpos($log, 'films') === false, 'the log holds the torrent or an argument: ' . $log);
            // Review F2: a torrent that would write a dot-file or into a dot-folder is refused by serve.
            foreach ([['name' => '.ssh', 'files' => [['length' => 5, 'path' => ['authorized_keys2']]]],
                ['name' => 'film', 'files' => [['length' => 5, 'path' => ['.ssh', 'authorized_keys2']]]],
                ['name' => '.bashrc', 'length' => 5], ['name' => 'a/b', 'length' => 5],
                ['name' => 'film', 'file tree' => ['.ssh' => ['authorized_keys' => ['' => ['length' => 5]]]]]] as $info) {
                file_put_contents($fx['home'] . '/bad.torrent', svTorrent($info + ['piece length' => 16384, 'pieces' => '01234567890123456789']));
                list($code, $l, $out) = svRun($fx, ['remote', 'hdd1', 'torrent-clients/rtorrent', 'add', $fx['home'] . '/bad.torrent', '--intent', 'add bad torrent test',
                    '--', '--dir', '~/data/films']);
                check($code === 0 && end($l)['failed'] === ['hdd1'] && !isset($l[0]['unit']), 'a dot-named torrent was added: ' . $out);
            }
            foreach (['~/.ssh', 'pikkumies/lib', '.', '~/', $fx['remote']] as $dir) {
                @mkdir($fx['remote'] . '/.ssh', 0700);
                @mkdir($fx['remote'] . '/pikkumies/lib', 0700, true);
                list($code, $l, $out) = svRun($fx, ['remote', 'hdd1', 'torrent-clients/rtorrent', 'add', $fx['home'] . '/x.torrent', '--intent', 'add x outside data',
                    '--', '--dir', $dir]);
                check($code === 0 && end($l)['failed'] === ['hdd1'] && strpos($l[0]['error'], 'data root') !== false, "--dir $dir was accepted: $out");
            }
            list($code, $l) = svRun($fx, ['remote', 'hdd1', 'torrent-clients/rtorrent', 'add', 'magnet:?xt=urn:btih:abc', '--intent', 'add a magnet link']);
            check($code === 1, 'a magnet must not be sent');
        } finally {
            stDone($fx);
        }
    },
    'services accept: one public key line only, a forced-command line, --install appends once and refuses a second line for the same key' => function () {
        $fx = svFixture();
        try {
            $pub = svPubKey();
            foreach (["-----BEGIN OPENSSH PRIVATE KEY-----\nb3Blbn==\n-----END OPENSSH PRIVATE KEY-----", "$pub\n$pub", 'ssh-ed25519 AAAA', 'ssh-dss AAAAB3NzaC1kc3M x',
                'ssh-ed25519 ' . base64_encode(pack('N', 7) . 'ssh-rsa' . random_bytes(40)), 'command="sh" ' . $pub] as $bad) {
                list($code, $l) = svRun($fx, ['accept', $bad, '--label', 'peer']);
                check($code !== 0 && end($l)['ok'] === false, 'accepted: ' . $bad);
            }
            list($code, $l) = svRun($fx, ['accept', $pub, '--label', 'bad label']);
            check($code === 1, 'bad label accepted');
            list($code, $l, $out) = svRun($fx, ['accept', $pub, '--label', 'peer-box']);
            check($code === 0 && $l[0]['installed'] === false && strpos($l[0]['authorized_keys_line'], 'restrict,command="php ~/pikkumies/tools/seedbox/services/serve.php peer-box" ') === 0, $out);
            check(!is_file($fx['home'] . '/.ssh/authorized_keys'), 'written without --install');
            for ($i = 0; $i < 2; $i++) {
                list($code, $l, $out) = svRun($fx, ['accept', $pub, '--label', 'peer-box', '--install']);
                check($code === 0 && end($l)['verified'] === true, 'install: ' . $out);
            }
            $keys = file($fx['home'] . '/.ssh/authorized_keys', FILE_IGNORE_NEW_LINES);
            check(count($keys) === 1 && (fileperms($fx['home'] . '/.ssh/authorized_keys') & 077) === 0, 'once, private: ' . json_encode($keys));
            list($code, $l) = svRun($fx, ['accept', $pub, '--label', 'peer-box', '--allow-delete', '--install']);
            check($code === 1 && strpos(end($l)['error'], 'another line') !== false, 'a second line for the same key was allowed');
        } finally {
            stDone($fx);
        }
    },
    'services add/edit/list/show/remove: presets from cited sources, tags, memories with service: <nick>, key files gone after remove' => function () {
        $fx = svFixture();
        try {
            list($code, $l, $out) = svRun($fx, ['add', 'feral1', '--host', 'kepler.feralhosting.com', '--user', 'xyzjohn', '--tags', 'seedbox']);
            check($code === 0 && $l[0]['provider'] === 'feral' && $l[0]['clients']['rtorrent']['socket'] === '~/private/rtorrent/.socket'
                && $l[0]['clients']['rtorrent']['data'] === null, 'feral preset: ' . $out);
            list($code, $l) = svRun($fx, ['add', 'pm1', '--host', 'box.example.com', '--user', 'xyzjohn', '--provider', 'pmss', '--tags', 'seedbox,backup']);
            check($code === 0 && $l[0]['clients']['rtorrent'] === ['socket' => '~/.rtorrent.socket', 'data' => '~/data'], 'pmss preset');
            list($code, $l) = svRun($fx, ['add', 'pm1', '--host', 'box.example.com', '--user', 'xyzjohn']);
            check($code === 1, 'duplicate nick accepted');
            list($code, $l) = svRun($fx, ['edit', 'pm1', '--add-tag', 'media', '--remove-tag', 'backup', '--notes', 'the big one']);
            check($code === 0 && $l[0]['tags'] === ['seedbox', 'media'] && $l[0]['notes'] === 'the big one', 'edit');
            list($code, $l) = svRun($fx, ['list', '--tag', 'media']);
            check($code === 0 && count($l) === 2 && $l[0]['nick'] === 'pm1' && $l[0]['set_up'] === false, 'list --tag');
            @mkdir($fx['memory'] . '/lessons/services', 0700, true);
            @mkdir($fx['memory'] . '/reference', 0700, true);
            file_put_contents($fx['memory'] . '/lessons/services/20261020-pm1-disk.md', "---\ntopic: services\n---\n# pm1 fills up on Sundays\n\nservice: pm1\ntags: seedbox\n");
            file_put_contents($fx['memory'] . '/reference/pm1-facts.md', "# pm1 facts\nservice: pm1\n");
            file_put_contents($fx['memory'] . '/reference/other.md', "# other\nservice: pm10\n");
            list($code, $l) = svRun($fx, ['show', 'pm1']);
            check($code === 0 && array_column($l[0]['memories'], 'title') === ['pm1 fills up on Sundays', 'pm1 facts']
                && $l[0]['memories'][0]['path'] === 'memory/lessons/services/20261020-pm1-disk.md', 'memories: ' . json_encode($l[0]['memories']));
            check(svAddAndSetup($fx, 'hdd1')[0] === 0, 'setup');
            list($code, $l) = svRun($fx, ['edit', 'hdd1', '--host', 'other.example.com']);
            check($code === 1, 'host changed on a set-up service');
            list($code, $l, $out) = svRun($fx, ['remove', 'hdd1']);
            check($code === 0 && end($l)['verified'] === true && strpos($l[0]['next'], 'authorized_keys') !== false, 'remove: ' . $out);
            check(!is_dir($fx['localdir'] . '/secrets/services/hdd1'), 'key files kept');
        } finally {
            stDone($fx);
        }
    },
    'services pubkey + accept + test: the password-less path pins the host key without a login and proves the forced command; a changed host key stops it' => function () {
        $fx = svFixture();
        try {
            svRun($fx, ['add', 'hdd1', '--host', BR_HOST, '--user', 'xyzjohn']);
            list($code, $l, $out) = svRun($fx, ['pubkey', 'hdd1']);
            check($code === 0 && $l[0]['made'] === true && strpos($l[0]['next'], "accept '" . $l[0]['public_key'] . "'") !== false, 'pubkey: ' . $out);
            $b64 = explode(' ', $l[0]['public_key'])[1];
            @mkdir($fx['remote'] . '/.ssh', 0700, true);
            file_put_contents($fx['remote'] . '/.ssh/authorized_keys', svKeyLine($b64) . "\n");
            stClearLog($fx);
            list($code, $l, $out) = svRun($fx, ['test', 'hdd1']);
            check($code === 0 && $l[0]['pinned_now'] === true && $l[0]['works'] === true && end($l)['verified'] === true, 'test: ' . $out);
            $calls = brSshCalls($fx);
            check($calls[0]['opt']['StrictHostKeyChecking'] === 'accept-new' && $calls[0]['opt']['PubkeyAuthentication'] === 'no' && !$calls[0]['pw_set'], 'pin without login');
            list($code, $l) = svRun($fx, ['test', 'hdd1']);
            check($code === 0 && $l[0]['pinned_now'] === false, 'a second test must not pin again');
            brSetState($fx, ['host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAISomeoneElseInBetween00000000000000']);
            list($code, $l, $out) = svRun($fx, ['test', 'hdd1']);
            check($code === 0 && end($l)['verified'] === false && $l[0]['works'] === false && strpos($l[0]['why'], 'not the one pinned') !== false && strpos($out, 'ssh-keygen -f') === false, 'changed key: ' . $out);
            list($code, $l, $out) = svRun($fx, ['remote', 'hdd1', 'system/system-status', 'account']);
            check($code === 0 && $l[0]['ok'] === false && end($l)['failed'] === ['hdd1'], 'remote to a box with a changed key: ' . $out);
        } finally {
            stDone($fx);
        }
    },
'services guard: setup built with $, backticks or eval is blocked; accept --install asks as an exposure; plain reads stay silent' => function () {
        $t = '~/pikkumies/tools/seedbox/services/services.php';
        foreach (["PIKKUMIES_REMOTE_PASSWORD=\"\$PW\" php $t setup hdd1", "php $t setup \$(cat nick)", "N=hdd1; php $t setup \$N", "php $t setup `echo hdd1`"] as $cmd) {
            $a = svGuard($cmd);
            check($a !== null && $a['action'] === 'block' && strpos($a['message'], 'services setup') !== false, "not blocked: $cmd -> " . json_encode($a));
        }
        $a = svGuard("PIKKUMIES_REMOTE_PASSWORD='pa\$\$word' php $t setup hdd1");
        check($a !== null && $a['action'] === 'approve' && $a['kind'] === 'send', 'a single-quoted login with $ must stay usable (asked as send): ' . json_encode($a));
        $a = svGuard("php $t accept 'ssh-ed25519 AAAAC3Nza pikkumies-box' --label box --install");
        check($a !== null && $a['action'] === 'approve' && $a['kind'] === 'exposure' && strpos($a['message'], 'authorized_keys') !== false, 'accept --install: ' . json_encode($a));
        foreach (["php $t accept 'ssh-ed25519 AAAAC3Nza pikkumies-box' --label box", "php $t list --tag seedbox", "php $t remote --tag seedbox system/system-status account"] as $cmd) {
            check(svGuard($cmd) === null, "asked or blocked: $cmd");
        }
    },
    'services list: clients auth is only secret:<handle>; a key shaped like a secret, a url with user:...@ or a query, and a bad user are refused without echoing the value (ADR-0107)' => function () {
        $base = ['nick' => 'feral1', 'host' => 'kepler.feralhosting.com', 'user' => 'xyzjohnny', 'transport' => 'tunnel'];
        $ok = Services::normalize($base + ['clients' => ['rtorrent' => ['url' => 'https://kepler.feralhosting.com/xyzjohnny/rtorrent/rpc', 'user' => 'rutorrent',
            'auth' => 'secret:feral1-rpc']], 'account_auth' => 'secret:bz1-api']);
        $plain = Services::normalize($base + ['clients' => ['rtorrent' => ['socket' => '~/private/rtorrent/.socket', 'data' => null, 'endpoint' => 'tcp://127.0.0.1:5000'],
            'qbittorrent' => ['port' => 12345, 'user' => 'xyzjohnny']]]);
        check($plain['clients']['qbittorrent']['port'] === 12345 && $plain['clients']['rtorrent']['endpoint'] === 'tcp://127.0.0.1:5000', 'ordinary endpoint keys must pass: ' . json_encode($plain));
        check($ok['clients']['rtorrent']['auth'] === 'secret:feral1-rpc' && $ok['clients']['rtorrent']['user'] === 'rutorrent' && $ok['account_auth'] === 'secret:bz1-api', json_encode($ok));
        check(!array_key_exists('account_auth', Services::normalize($base + ['account_auth' => ''])), 'an empty account_auth must clear it');
        $value = 'Inline-Login-0racle';
        foreach ([['rtorrent' => ['password' => $value]], ['rtorrent' => ['Pass' => $value]], ['rtorrent' => ['passwd' => $value]], ['qbittorrent' => ['token' => $value]],
            ['deluge' => ['api_key' => $value]], ['deluge' => ['APIKEY' => $value]], ['rtorrent' => ['secret' => $value]], ['rtorrent' => ['auth' => $value]],
            ['rtorrent' => ['auth' => "secret:bad handle $value"]], ['rtorrent' => ['auth' => 7]], ['rtorrent' => ['url' => "https://rutorrent:$value@kepler.feralhosting.com/rpc"]],
            ['rtorrent' => ['user' => 'ru:torrent']], ['rtorrent' => ['user' => "ru torrent"]], ['rtorrent' => ['rpc_password' => $value]], ['rtorrent' => ['Cookie' => $value]],
            ['rtorrent' => ['passkey' => $value]], ['rtorrent' => ['basic_auth' => $value]], ['qbittorrent' => ['Session-Token' => $value]], ['deluge' => ['web_key' => $value]],
            ['rtorrent' => ['url' => "https://kepler.feralhosting.com/rpc?token=$value"]], ['rtorrent' => ['url' => "https://kepler.feralhosting.com/rpc#$value"]]] as $clients) {
            $threw = false;
            try {
                Services::normalize($base + ['clients' => $clients]);
            } catch (CliError $e) {
                $threw = strpos($e->getMessage() . $e->hint, $value) === false;
            }
            check($threw, 'accepted, or the refusal echoed the value: ' . json_encode($clients));
        }
        $threw = false;
        try {
            Services::normalize($base + ['account_auth' => $value]);
        } catch (CliError $e) {
            $threw = strpos($e->getMessage(), 'account_auth must be secret:<handle>') !== false && strpos($e->getMessage() . $e->hint, $value) === false;
        }
        check($threw, 'an inline account_auth was accepted or echoed');
    },
    'services setup: a tunnel service with only TCP client endpoints installs a key line with permitopen; one that also has a socket gets none' => function () {
        $fx = svFixture();
        try {
            foreach (['tun1' => '{"qbittorrent":{"port":12345}}', 'tun2' => '{"rtorrent":{"socket":"~/.rtorrent.socket"},"qbittorrent":{"port":12346}}'] as $nick => $clients) {
                list($code, , $out) = svRun($fx, ['add', $nick, '--host', BR_HOST, '--user', 'xyzjohn', '--transport', 'tunnel', '--clients', $clients]);
                check($code === 0, "add $nick: $out");
                svRun($fx, ['setup', $nick], ['PIKKUMIES_REMOTE_PASSWORD' => BR_LOGIN]); // the fake ssh runs no port forwards, so its proof fails; the line is the oracle
            }
            $keys = (array) @file($fx['remote'] . '/.ssh/authorized_keys', FILE_IGNORE_NEW_LINES);
            check(count(preg_grep('#^restrict,port-forwarding,permitopen="127\.0\.0\.1:12345",command="/bin/false" ssh-ed25519 #', $keys)) === 1, 'tun1 line: ' . json_encode($keys));
            check(count(preg_grep('#^restrict,port-forwarding,command="/bin/false" ssh-ed25519 #', $keys)) === 1 && count(preg_grep('#12346#', $keys)) === 0,
                'tun2 (a socket too) must keep the plain line: ' . json_encode($keys));
        } finally {
            stDone($fx);
        }
    },
    'services keys: a tunnel key gets permitopen for each TCP client endpoint; a socket, an https url or none leaves the line as it was; a bad endpoint refuses' => function () {
        require_once dirname(__DIR__) . '/pikkumies/tools/seedbox/services/ServicesKeys.php';
        $plain = 'restrict,port-forwarding,command="/bin/false" ssh-ed25519 AAAA pikkumies-box';
        $line = function (array $clients) {
            return ServicesKeys::keyLine('tunnel', 'box', false, 'ssh-ed25519', 'AAAA', ServicesKeys::permitOpen(['clients' => $clients]));
        };
        check($line(['rtorrent' => ['endpoint' => 'tcp://127.0.0.1:5000']]) === 'restrict,port-forwarding,permitopen="127.0.0.1:5000",command="/bin/false" ssh-ed25519 AAAA pikkumies-box',
            $line(['rtorrent' => ['endpoint' => 'tcp://127.0.0.1:5000']]));
        check($line(['qbittorrent' => ['port' => 12345], 'deluge' => ['endpoint' => '[::1]:58846']])
            === 'restrict,port-forwarding,permitopen="127.0.0.1:12345",permitopen="[::1]:58846",command="/bin/false" ssh-ed25519 AAAA pikkumies-box', 'port and IPv6');
        foreach ([[], ['rtorrent' => ['socket' => '~/private/rtorrent/.socket', 'data' => null]], ['rtorrent' => ['endpoint' => '/srv/fixtureuserx/.rtorrent.socket']],
            ['rtorrent' => ['endpoint' => 'unix:///srv/fixtureuserx/.rtorrent.socket']], ['rtorrent' => ['url' => 'https://kepler.feralhosting.com/xyzjohnny/rtorrent/rpc']],
            ['rtorrent' => ['socket' => '~/private/rtorrent/.socket'], 'qbittorrent' => ['port' => 12345]],
            ['rtorrent' => ['endpoint' => '/srv/fixtureuserx/.rtorrent.socket'], 'deluge' => ['endpoint' => 'tcp://127.0.0.1:58846']]] as $c) {
            check($line($c) === $plain, 'changed for ' . json_encode($c) . ': ' . $line($c));
        }
        foreach ([['rtorrent' => ['endpoint' => 'tcp://127.0.0.1:99999']], ['rtorrent' => ['endpoint' => 'tcp://127.1:5000']], ['rtorrent' => ['port' => 'abc']],
            ['rtorrent' => ['endpoint' => 'tcp://a"b:5000']]] as $c) {
            $threw = false;
            try {
                $line($c);
            } catch (CliError $e) {
                $threw = strpos($e->getMessage(), 'nothing was set up') !== false;
            }
            check($threw, 'a bad endpoint did not refuse: ' . json_encode($c));
        }
        check(ServicesKeys::keyLine('pikkumies', 'box', false, 'ssh-ed25519', 'AAAA', ['127.0.0.1:5000'])
            === 'restrict,command="' . ServicesKeys::SERVE . ' box" ssh-ed25519 AAAA pikkumies-box', 'a pikkumies key line must not change');
        check(ServicesKeys::keyLine('shell', 'box', false, 'ssh-ed25519', 'AAAA', ['127.0.0.1:5000']) === 'no-agent-forwarding,no-X11-forwarding ssh-ed25519 AAAA pikkumies-box', 'shell line');
    },
];
