<?php
/**
 * tools/seedbox/services/serve.php, the forced command of a peer's key (ADR-0084): the shell-free tokenizer against
 * hostile torrent-name-like words, the verb and option whitelist, deletes only with --allow-delete, .torrent on stdin
 * only, read-only rsync pulls (own check and rrsync), ping, and a log that never holds arguments.
 */
require_once __DIR__ . '/fixtures/services/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/seedbox/services/ServeGate.php';

/** Hostile words shaped like torrent names, paths and options; each must be refused or reach the tool as inert data. */
function svHostileWords()
{
    return ['Film Name (2020) [1080p]', "it's", '"quoted"', '$(touch PWNED)', '`touch PWNED`', '${HOME}', 'a;touch PWNED', 'a|touch PWNED',
        'a&&touch PWNED', 'a>PWNED', '<x', "a\ntouch PWNED", "a\rb", "a\tb", "a\0b", 'a\\b', 'Amélie', '映画', "\u{202E}gnp.exe", '../../etc/passwd',
        '-oProxyCommand=touch PWNED', '--with-data', '--all', '--service=x', '/etc/passwd', '~root', 'name*?', '!x', '#c', 'x=$(y)', '{a,b}'];
}

return [
    'services serve fuzz: every hostile word is refused before any tool runs, or passes as one inert argument' => function () {
        $home = sys_get_temp_dir();
        foreach (svHostileWords() as $w) {
            try {
                $req = ServeGate::decide(ServeGate::tokenize("tool torrent-clients/find find $w"), false, $home, []);
                check($req['args'] === [$w], 'passed but not as one inert argument: ' . json_encode($w) . ' -> ' . json_encode($req['args']));
            } catch (CliError $e) {
                continue; // refused: the wanted outcome
            }
        }
        foreach (['Film.Name.2020.1080p', 'a=b,c', '0123456789abcdef0123456789abcdef01234567'] as $w) {
            $req = ServeGate::decide(ServeGate::tokenize("tool torrent-clients/find find $w"), false, $home, []);
            check($req['args'] === [$w], 'a plain word must pass unchanged: ' . $w);
        }
        // Review F7: a trailing line feed is not swallowed by $ in the patterns.
        foreach (["tool system/system-status show\n", "tool torrent-clients/find find abc\n", "ping\n"] as $c) {
            $threw = false;
            try {
                ServeGate::tokenize($c);
            } catch (CliError $e) {
                $threw = true;
            }
            check($threw, 'a trailing line feed passed: ' . json_encode($c));
        }
        $fx = svFixture();
        try {
            foreach (svHostileWords() as $w) {
                if (strpos($w, "\0") !== false) {
                    continue; // no environment variable (so no SSH_ORIGINAL_COMMAND) can hold NUL; refused in-process above
                }
                list($code, $out) = svServe($fx, "tool torrent-clients/find find $w");
                $first = json_decode((string) strtok($out, "\n"), true);
                check($code === 1 && is_array($first) && $first['ok'] === false, 'not refused end to end: ' . json_encode($w) . ' -> ' . $out);
            }
            check(!glob($fx['remote'] . '/PWNED') && !glob($fx['remote'] . '/*/PWNED'), 'an injected command ran');
            $log = svServeLog($fx);
            check(count($log) === count(svHostileWords()) - 1 && !array_filter($log, function ($r) {
                return $r['decision'] !== 'refuse';
            }), 'every refusal must be logged as refuse');
        } finally {
            stDone($fx);
        }
    },
    'services serve ping: proves the forced command; a key line without a valid label is refused' => function () {
        $fx = svFixture();
        try {
            list($code, $out) = svServe($fx, 'ping');
            $l = stLines($out);
            check($code === 0 && $l[0] === ['serve' => 'pikkumies', 'version' => 1, 'peer' => 'peer-box', 'allow_delete' => false] && $l[1]['ok'] === true, $out);
            list($code, $out) = svServe($fx, 'ping', '', ['peer-box', '--allow-delete']);
            check($code === 0 && stLines($out)[0]['allow_delete'] === true, 'allow_delete: ' . $out);
            foreach ([[], ['bad label'], ['peer', '--allow-everything'], ['peer', '--allow-delete', 'x']] as $args) {
                list($code, $out) = svServe($fx, 'ping', '', $args);
                check($code === 1 && stLines($out)[0]['ok'] === false, 'accepted serve args ' . json_encode($args));
            }
            foreach (['', 'sh', 'bash -c id', 'tool', 'scp -t .', 'ping now'] as $c) {
                list($code, $out) = svServe($fx, $c);
                check($code === 1, 'accepted request ' . json_encode($c));
            }
        } finally {
            stDone($fx);
        }
    },
    'services serve tools: only whitelisted verbs and options; --with-data only on remove with --allow-delete; folders only inside the home' => function () {
        $fx = svFixture();
        try {
            list($code, $out) = svServe($fx, 'tool torrent-clients/rtorrent list --limit 5 --sort size');
            check($code === 0 && stLines($out)[0]['argv'] === ['list', '--limit', '5', '--sort', 'size'], 'list: ' . $out);
            symlink('/etc', $fx['remote'] . '/etc-link');
            @mkdir($fx['remote'] . '/.ssh', 0700);
            @mkdir($fx['remote'] . '/pikkumies/lib', 0700, true);
            @mkdir($fx['remote'] . '/data/.cache', 0700);
            symlink($fx['remote'] . '/.ssh', $fx['remote'] . '/data/sshlink');
            // Review F2: folders only inside a data root: never ~/.ssh, the Pikkumies tree, the home, a dot-part or a link out.
            foreach (['tool torrent-clients/rtorrent add - --dir ~/.ssh', 'tool torrent-clients/rtorrent add - --dir pikkumies/lib', 'tool torrent-clients/rtorrent add - --dir .',
                'tool torrent-clients/rtorrent add - --dir ~/', 'tool torrent-clients/rtorrent add - --dir ' . $fx['remote'], 'tool torrent-clients/rtorrent add - --dir data/.cache',
                'tool torrent-clients/rtorrent add - --dir data/sshlink', 'tool torrent-clients/rtorrent repath ABC --to ~/.ssh', 'tool torrent-clients/qbittorrent repath ABC --to=pikkumies'] as $c) {
                list($code, $out) = svServe($fx, $c, svTorrent());
                check($code === 1 && strpos($out, 'data root') !== false, "F2 not refused: $c -> $out");
            }
            foreach (['tool torrent-clients/rtorrent restart', 'tool torrent-clients/rtorrent limit --up 0', 'tool storage/quota show', 'tool system/system-status logs',
                'tool torrent-clients/find find x --all', 'tool torrent-clients/find find x --service hdd2', 'tool torrent-clients/rtorrent remove ABC --with-data --expect 5',
                'tool torrent-clients/rtorrent repath ABC --to /etc', 'tool torrent-clients/rtorrent repath ABC --to ../x', 'tool torrent-clients/rtorrent repath ABC --to etc-link',
                'tool torrent-clients/rtorrent add http://tracker.example/x.torrent', 'tool torrent-clients/rtorrent add /home/fixtureuser/x.torrent',
                'tool torrent-clients/rtorrent list --json x', 'tool ../../bin/sh x', 'tool torrent-clients/rtorrent'] as $c) {
                list($code, $out) = svServe($fx, $c);
                check($code === 1 && stLines($out)[0]['ok'] === false && !isset(stLines($out)[0]['unit']), "not refused: $c -> $out");
            }
            list($code, $out) = svServe($fx, 'tool torrent-clients/rtorrent pause ABC --with-data', '', ['peer-box', '--allow-delete']);
            check($code === 1, '--with-data on pause must be refused even with --allow-delete');
            list($code, $out) = svServe($fx, 'tool torrent-clients/rtorrent remove ABC --with-data --expect 5', '', ['peer-box', '--allow-delete']);
            check($code === 0 && stLines($out)[0]['argv'] === ['remove', 'ABC', '--with-data', '--expect', '5'], 'remove with data: ' . $out);
            list($code, $out) = svServe($fx, 'tool torrent-clients/rtorrent repath ABC --to ~/data/films');
            check($code === 0 && stLines($out)[0]['argv'] === ['repath', 'ABC', '--to', $fx['remote'] . '/data/films'], 'repath: ' . $out);
            list($code, $out) = svServe($fx, 'tool torrent-clients/rtorrent add - --paused', 'not a torrent');
            check($code === 1 && strpos($out, '.torrent') !== false, 'non-torrent stdin accepted');
            $log = svServeLog($fx);
            check(count($log) === 29 && $log[0]['tool'] === 'torrent-clients/rtorrent' && $log[0]['argc'] === 4 && !isset($log[0]['args']), json_encode($log[0]));
            check(strpos((string) file_get_contents($fx['rlocal'] . '/state/services-serve.jsonl'), 'films') === false, 'an argument reached the log');
        } finally {
            stDone($fx);
        }
    },
    'services serve rsync: a read-only pull only; pushes, deletes, -s, link-following and paths outside the home are refused' => function () {
        $fx = svFixture();
        try {
            $rsync = svFakeProgram($fx, 'rsync');
            $path = dirname($rsync) . ':' . (string) getenv('PATH');
            list($code, $out) = svServe($fx, 'rsync --server --sender -vlogDtpre.iLsfxCIvu --partial . data/films', '', ['peer-box'], null, $path);
            $r = stLines($out)[0];
            check($code === 0 && $r['argv'] === ['--server', '--sender', '-vlogDtpre.iLsfxCIvu', '--partial', '--', '.', 'films'] && $r['cwd'] === $fx['remote'] . '/data', 'pull: ' . $out);
            list($code, $out) = svServe($fx, 'rsync --server --sender -logDtpre.iLsfxCIvu --files-from=- --from0 . data', '', ['peer-box'], null, $path);
            check($code === 0 && in_array('--files-from=-', stLines($out)[0]['argv'], true), 'files-from - : ' . $out);
            symlink('/etc', $fx['remote'] . '/etc-link');
            // Review F1: keys, ~/.ssh, client settings, the home itself and dot-parts are never served, also through a link.
            @mkdir($fx['remote'] . '/.ssh', 0700);
            @mkdir($fx['remote'] . '/data/.hidden', 0700);
            @mkdir($fx['rlocal'] . '/secrets/services/boxc', 0700, true);
            file_put_contents($fx['rlocal'] . '/secrets/services/boxc/key', 'FAKE-PRIVATE-KEY x');
            symlink($fx['remote'] . '/.ssh', $fx['remote'] . '/data/sshlink');
            symlink($fx['rlocal'] . '/secrets', $fx['remote'] . '/data/secretlink');
            foreach (['rsync --server --sender . pikkumies/local/secrets', 'rsync --server --sender . pikkumies', 'rsync --server --sender . .ssh',
                'rsync --server --sender . ' . $fx['rlocal'] . '/secrets/services/boxc/key', 'rsync --server --sender . .', 'rsync --server --sender .',
                'rsync --server --sender . ./', 'rsync --server --sender . data/.hidden', 'rsync --server --sender . data/sshlink/', 'rsync --server --sender . data/secretlink',
                'rsync --server --sender . .config', 'rsync --server --sender . data/films pikkumies/local'] as $c) {
                list($code, $out, $err) = svServe($fx, $c, '', ['peer-box'], null, $path);
                check($code === 1 && $out === '' && strpos($err, '"ok":false') !== false, "F1 not refused: $c -> $out $err");
            }
            foreach (['rsync --server -vlogDtpre.iLsfxCIvu . data', 'rsync --server --sender --delete . data', 'rsync --server --sender --remove-source-files . data',
                'rsync --server --sender -vs . data', 'rsync --server --sender -vLe.x . data', 'rsync --server --sender -vk . data', 'rsync --server --sender --copy-links . data',
                'rsync --server --sender --log-file=x . data', 'rsync --server --sender --files-from=/etc/passwd . data', 'rsync --server --sender . /etc',
                'rsync --server --sender . ../x', 'rsync --server --sender . data/../../x', 'rsync --server --sender . nothing-here', 'rsync --server --sender . etc-link/',
                'rsync --server --sender -v data', 'rsync --sender --server . data', 'rsync --server --sender --temp-dir=/tmp . data'] as $c) {
                list($code, $out, $err) = svServe($fx, $c, '', ['peer-box'], null, $path);
                check($code === 1 && $out === '' && strpos($err, '"ok":false') !== false, "not refused: $c -> $out $err");
            }
            $rr = svFakeProgram($fx, 'rrsync');
            $wrapper = $fx['dir'] . '/serve-rr.php';
            svServeWrapper($fx, $wrapper, $rr);
            list($code, $out) = svServe($fx, 'rsync --server --sender -vlogDtpre.iLsfxCIvu . data', '', ['peer-box'], $wrapper, $path);
            $r = stLines($out)[0];
            check($code === 0 && $r['argv'] === ['-ro', '-munge', $fx['remote'] . '/data'] && $r['cmd'] === 'rsync --server --sender -vlogDtpre.iLsfxCIvu . .'
                && $r['cwd'] === $fx['remote'] . '/data', 'rrsync must get the data root, never the home: ' . $out);
            list($code, , $err) = svServe($fx, 'rsync --server --sender -vlogDtpre.iLsfxCIvu . pikkumies/local/secrets', '', ['peer-box'], $wrapper, $path);
            check($code === 1 && strpos($err, 'data root') !== false, 'F1 through rrsync: ' . $err);
            list($code, , $err) = svServe($fx, 'rsync --server -vlogDtpre.iLsfxCIvu . data', '', ['peer-box'], $wrapper, $path);
            check($code === 1 && strpos($err, 'INTO this box') !== false, 'a push must be refused before rrsync');
            $vias = array_column(array_filter(svServeLog($fx), function ($l) {
                return $l['decision'] === 'allow';
            }), 'via');
            check($vias === ['own-check', 'own-check', 'rrsync'], 'log via: ' . json_encode($vias));
            // services.serveRoots and rTorrent's directory.default.set add roots; a root inside pikkumies/ or a dot-folder is dropped.
            mkdir($fx['remote'] . '/torrents/done', 0700, true);
            mkdir($fx['remote'] . '/media', 0700, true);
            file_put_contents($fx['remote'] . '/.rtorrent.rc', "directory.default.set = ~/torrents\n");
            @mkdir($fx['rlocal'], 0700, true);
            file_put_contents($fx['rlocal'] . '/config.php', "<?php\nreturn ['services' => ['serveRoots' => ['media', 'pikkumies/local', '.ssh', '~']]];\n");
            $roots = ServeGate::rootsFor($fx['remote'], $fx['rlocal'] . '/config.php');
            check($roots === [$fx['remote'] . '/data', $fx['remote'] . '/torrents', $fx['remote'] . '/media'], 'roots: ' . json_encode($roots));
            list($code, $out) = svServe($fx, 'rsync --server --sender -r . torrents/done', '', ['peer-box'], null, $path);
            check($code === 0 && stLines($out)[0]['cwd'] === $fx['remote'] . '/torrents', 'rc root: ' . $out);
            list($code, , $err) = svServe($fx, 'rsync --server --sender -r . pikkumies/local', '', ['peer-box'], null, $path);
            check($code === 1, 'a configured root inside pikkumies/ was served');
        } finally {
            stDone($fx);
        }
    },
'services serve roots: each client\'s configured save folder from its own config is a data root, under the same rules' => function () {
        $home = realpath(testTempDir('pk-roots'));
        try {
            foreach (['qbit/done', 'qbit-old', 'deluge/done', 'trans/done', 'trans-snake', 'pikkumies/local', '.hidden-dl'] as $d) {
                mkdir("$home/$d", 0700, true);
            }
            mkdir("$home/.config/qBittorrent", 0700, true);
            mkdir("$home/.config/deluge", 0700, true);
            mkdir("$home/.config/transmission-daemon", 0700, true);
            $roots = function () use ($home) {
                return array_map(function ($r) use ($home) {
                    return substr($r, strlen($home) + 1);
                }, ServeGate::rootsFor($home, "$home/no-config.php"));
            };
            check($roots() === [], 'no client config: no roots (no ~/data here): ' . json_encode($roots()));
            // qBittorrent: [BitTorrent] Session\DefaultSavePath (qBittorrent 4.x+), [Preferences] Downloads\SavePath (older, PMSS template).
            file_put_contents("$home/.config/qBittorrent/qBittorrent.conf", "[BitTorrent]\nSession\\DefaultSavePath=$home/qbit\n\n[Preferences]\nDownloads\\SavePath=$home/qbit-old/\nWebUI\\Port=1\n");
            check($roots() === ['qbit', 'qbit-old'], 'qBittorrent: ' . json_encode($roots()));
            // Deluge: two JSON objects; download_location may be a dot-folder (PMSS ~/.delugeUnfinished), dropped; move_completed_path kept.
            file_put_contents("$home/.config/deluge/core.conf", "{\n\"file\": 1,\n\"format\": 1\n}{\n\"download_location\": \"$home/.hidden-dl\",\n\"move_completed_path\": \"$home/deluge\"\n}");
            check($roots() === ['qbit', 'qbit-old', 'deluge'], 'Deluge: ' . json_encode($roots()));
            // Transmission: download-dir, or download_dir when written snake_case.
            file_put_contents("$home/.config/transmission-daemon/settings.json", json_encode(['download-dir' => "$home/trans", 'rpc-port' => 9091]));
            check($roots() === ['qbit', 'qbit-old', 'deluge', 'trans'], 'Transmission: ' . json_encode($roots()));
            file_put_contents("$home/.config/transmission-daemon/settings.json", json_encode(['download_dir' => "$home/trans-snake"]));
            check($roots() === ['qbit', 'qbit-old', 'deluge', 'trans-snake'], 'Transmission snake_case: ' . json_encode($roots()));
            // The same rules as every root: the home itself, ~/pikkumies, a dot-folder and a folder outside the home are dropped.
            file_put_contents("$home/.config/qBittorrent/qBittorrent.conf", "[BitTorrent]\nSession\\DefaultSavePath=$home\n[Preferences]\nDownloads\\SavePath=$home/pikkumies/local\n");
            file_put_contents("$home/.config/deluge/core.conf", "{\"file\": 1}{\"download_location\": \"/etc\", \"move_completed_path\": \"$home/.config\"}");
            file_put_contents("$home/.config/transmission-daemon/settings.json", json_encode(['download-dir' => "$home/../"]));
            check($roots() === [], 'a client save folder broke the root rules: ' . json_encode($roots()));
            file_put_contents("$home/.config/transmission-daemon/settings.json", json_encode(['download-dir' => "$home/trans"]));
            $req = ServeGate::decide(ServeGate::tokenize('rsync --server --sender -r --files-from=- --from0 . trans/done/'), false, $home, ServeGate::rootsFor($home, "$home/x.php"));
            check($req['root'] === "$home/trans" && $req['rel'] === ['done/'], 'a pull from the Transmission folder: ' . json_encode($req));
        } finally {
            testRmTree($home);
        }
    },
'services serve env: a tool run for a peer sees PIKKUMIES_SERVE=1, a local run of the same unit does not' => function () {
        $fx = svFixture();
        try {
            list($code, $out) = svServe($fx, 'tool system/system-status account');
            check($code === 0 && stLines($out)[0]['serve_env'] === '1', 'served run: ' . $out);
            $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fx['tools'] . '/system/system-status/system-status.php') . ' account',
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $fx['remote'], ['HOME' => $fx['remote'], 'PATH' => (string) getenv('PATH')]);
            fclose($pipes[0]);
            $local = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
            check(stLines($local)[0]['serve_env'] === false, 'local run must not see it: ' . $local);
        } finally {
            stDone($fx);
        }
    },
];
