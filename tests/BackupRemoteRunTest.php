<?php
/**
 * tools/backup/remote run, restore, receiver, schedule and passphrase (ADR-0049): the real tar and the real recv.sh, with
 * fake ssh (the backup machine under $FAKE_DIR/remote), gpg and pigz that record their argv.
 */
require_once __DIR__ . '/fixtures/backupremote/harness.php';

const BR_LIST = 'pikkumies-file-list.tsv.gz';

/** A set-up fixture with a home that has a bit of everything meta mode must take or leave. */
function brHome(array $state = [])
{
    $fx = brFixture($state);
    if (brSetup($fx)[0] !== 0) {
        stDone($fx); // the caller's finally never runs for a fixture it did not get
        check(false, 'setup failed');
    }
    stClearLog($fx);
    $h = $fx['home'];
    foreach (['session', '.config/qBittorrent', '.config/app/cache', '.config/app/Cache', '.cache/x', '.bin', 'data', '.lighttpd',
        'pikkumies/local/logs', '.local/share/qBittorrent/BT_backup', '.ssh'] as $d) {
        @mkdir("$h/$d", 0700, true);
    }
    foreach (['.rtorrent.rc' => 'rc', 'session/a.torrent' => 't', '.config/qBittorrent/q.conf' => 'q', '.config/app/settings.json' => 's',
        '.config/app/cache/c' => 'c', '.config/app/Cache/c' => 'c', '.cache/x/y' => 'y', '.bin/tool' => 'b', 'data/movie.mkv' => 'big',
        '.bashrc' => 'b', '.bash_history' => 'a typed line', '.pikkumiesKey' => 'k', '.lighttpd/l.conf' => 'l',
        'pikkumies/local/config.php' => '<?php return [];', 'pikkumies/local/logs/l.jsonl' => 'l', '.local/share/qBittorrent/BT_backup/h.fastresume' => 'r',
        '.ssh/config' => 'Host x'] as $f => $c) {
        file_put_contents("$h/$f", $c);
    }
    return $fx;
}

/** The generated gpg passphrase of a fixture (for "never printed" checks). */
function brPhrase(array $fx)
{
    return trim((string) file_get_contents($fx['secrets'] . '/pass'));
}

return [
    'backup remote run meta: PMSS panel set, ~/.config without caches, dotfiles, pikkumies local and a file list; never secrets, logs, caches, history, keys or data; restore brings it back' => function () {
        $fx = brHome();
        try {
            list($code, $l, $out) = brRun($fx, ['run', '--mode', 'meta']);
            check($code === 0 && $l[0]['ok'] === true && end($l)['verified'] === true && $l[0]['file_list_complete'] === true, 'run: ' . $out);
            check(preg_match('/^\d{8}T\d{6}Z\.meta\.tgz\.gpg$/', $l[0]['name']) === 1, 'name: ' . $l[0]['name']);
            list(, $list) = brRun($fx, ['list']);
            check($list[0]['name'] === $l[0]['name'] && $list[0]['bytes'] === $l[0]['bytes'], 'list: ' . json_encode($list));
            list($code, $r, $out) = brRun($fx, ['restore', $l[0]['name'], '--target', $fx['home'] . '/restored']);
            check($code === 0 && end($r)['verified'] === true, 'restore: ' . $out);
            $t = $fx['home'] . '/restored';
            foreach (['.rtorrent.rc', 'session/a.torrent', '.config/qBittorrent/q.conf', '.config/app/settings.json', '.bashrc', '.lighttpd/l.conf',
                'pikkumies/local/config.php', '.local/share/qBittorrent/BT_backup/h.fastresume', BR_LIST] as $f) {
                check(is_file("$t/$f"), "meta lacks $f");
            }
            foreach (['.config/app/cache', '.config/app/Cache', '.cache', '.bin', 'data', '.bash_history', '.pikkumiesKey',
                'pikkumies/local/secrets', 'pikkumies/local/logs', '.ssh'] as $f) {
                check(!file_exists("$t/$f"), "meta holds $f");
            }
            $files = substr((string) file_get_contents("$t/" . BR_LIST), 9); // the fake pigz header
            check(strpos($files, "./data/movie.mkv\n") !== false && strpos($files, 'local/secrets/') === false, 'file list: ' . $files);
            list($code) = brRun($fx, ['restore', $l[0]['name'], '--target', $fx['home'] . '/restored']);
            check($code === 1, 'a restore over a non-empty directory ran');
        } finally {
            stDone($fx);
        }
    },
    'backup remote run: each stage counts (tar 1 is a warning), the gpg passphrase stays a file, a short upload is never committed' => function () {
        foreach ([[[], 0], [['tar_code' => 1], 0], [['tar_code' => 2], 1], [['gpg_fail' => true], 1], [['pigz_fail' => true], 1],
            [['truncate_put' => true], 1]] as $case) {
            list($state, $want) = $case;
            $fx = brHome();
            try {
                brSetState($fx, $state);
                list($code, $l, $out) = brRun($fx, ['run', '--mode', 'meta']);
                check($code === $want, json_encode($state) . " exit $code: $out");
                $archives = glob($fx['remote'] . '/pikkumies-backups/*/*.gpg');
                check(count($archives) === ($want === 0 ? 1 : 0), json_encode($state) . ' archives: ' . json_encode($archives));
                if (!empty($state['truncate_put'])) {
                    check(strpos(end($l)['error'], 'stored') !== false && count(glob($fx['remote'] . '/pikkumies-backups/*/*.part')) === 1, $out);
                }
                if (isset($state['tar_code']) && $state['tar_code'] === 1) {
                    check($l[0]['files_changed_while_read'] === true, 'tar 1 not reported: ' . $out);
                }
                check(strpos(file_get_contents($fx['dir'] . '/argv.log'), brPhrase($fx)) === false && strpos($out, brPhrase($fx)) === false, 'passphrase leaked');
                $gpg = stCalls($fx, 'gpg');
                check(strpos($gpg[0], '--batch --yes --pinentry-mode loopback --no-symkey-cache --passphrase-file ' . $fx['secrets'] . '/pass --symmetric'
                    . ' --cipher-algo AES256 --compress-algo none') === 0, 'gpg argv: ' . json_encode($gpg));
                check(strpos(stCalls($fx, 'tar')[0], '--one-file-system') === 0 && stCalls($fx, 'pigz')[1] === '-p 2 -c', 'tar/pigz argv');
                list(, $st) = brRun($fx, ['stats']);
                check($st[0]['last_run']['ok'] === ($want === 0), 'stats last_run: ' . json_encode($st));
            } finally {
                stDone($fx);
            }
        }
    },
    'backup remote run: full above 100 GiB needs --allow-large; any run that cannot fit the free space sends nothing' => function () {
        $fx = brHome(['du_kib' => 200 * 1048576, 'df_kib' => 300 * 1048576]);
        try {
            list($code, $l) = brRun($fx, ['run', '--mode', 'full']);
            check($code === 1 && strpos(end($l)['hint'], '--allow-large') !== false && !stCalls($fx, 'tar'), 'full gate: ' . json_encode($l));
            brSetState($fx, ['df_kib' => 100 * 1048576]);
            list($code, $l) = brRun($fx, ['run', '--mode', 'full', '--allow-large']);
            check($code === 1 && strpos(end($l)['error'], 'free') !== false && !stCalls($fx, 'tar'), 'space gate: ' . json_encode($l));
            brSetState($fx, ['du_kib' => 1000, 'df_kib' => 10]);
            list($code, $l) = brRun($fx, ['run', '--mode', 'meta']);
            check($code === 1 && strpos(end($l)['error'], 'free') !== false && !stCalls($fx, 'tar'), 'meta space gate: ' . json_encode($l));
        } finally {
            stDone($fx);
        }
    },
    'backup remote receiver: only its five commands and fixed names; never overwrites; keeps the newest 3 of each mode past keep-days; clears old .part files' => function () {
        $home = testTempDir('pk-recv');
        try {
            $n = function ($day, $mode) {
                return sprintf('202610%02dT030000Z.%s.tgz.gpg', $day, $mode);
            };
            foreach (['id', 'put ../x.meta.tgz.gpg', 'put 20261018T030000Z.meta.tgz.gpg extra', 'get /etc/hosts', 'put x', 'commit ' . $n(1, 'meta') . ' 1a',
                'list *', 'rm -rf .', 'put 20261018T030000Z.mta.tgz.gpg', 'df -h', ''] as $cmd) {
                list($code, , $err) = brRecv($home, $cmd, 'data');
                check($code === 2 && strpos($err, 'refused') === 0, "accepted: '$cmd' ($code $err)");
            }
            $dir = "$home/pikkumies-backups/box";
            for ($day = 1; $day <= 6; $day++) {
                foreach (['meta', 'full'] as $mode) {
                    check(brRecv($home, 'put ' . $n($day, $mode), "$mode$day")[1] === "received " . strlen("$mode$day") . "\n", 'put');
                    check(brRecv($home, 'commit ' . $n($day, $mode) . ' ' . strlen("$mode$day"))[0] === 0, 'commit');
                    touch("$dir/" . $n($day, $mode), time() - (40 - $day) * 86400);
                }
            }
            list($code, , $err) = brRecv($home, 'put ' . $n(6, 'meta'), 'other');
            check($code === 2 && file_get_contents("$dir/" . $n(6, 'meta')) === 'meta6', 'an archive was overwritten: ' . $err);
            check(brRecv($home, 'commit ' . $n(9, 'meta') . ' 5')[0] !== 0, 'committed an archive never put');
            brRecv($home, 'put ' . $n(7, 'meta'), 'short');
            check(brRecv($home, 'commit ' . $n(7, 'meta') . ' 9')[0] === 2 && !is_file("$dir/" . $n(7, 'meta')), 'a short upload was committed');
            touch("$dir/" . $n(7, 'meta') . '.part', time() - 2 * 86400);
            brRecv($home, 'put ' . $n(8, 'meta'), 'meta8');
            check(!is_file("$dir/" . $n(7, 'meta') . '.part'), 'an old .part file was kept');
            check(brRecv($home, 'commit ' . $n(8, 'meta') . ' 5')[0] === 0, 'commit 8');
            list(, $list) = brRecv($home, 'list');
            $names = array_map(function ($l) {
                return explode(' ', $l)[0];
            }, array_filter(explode("\n", $list)));
            sort($names);
            $want = [$n(4, 'full'), $n(5, 'full'), $n(6, 'full'), $n(5, 'meta'), $n(6, 'meta'), $n(8, 'meta')];
            sort($want);
            check($names === $want, 'retention: ' . json_encode($names));
            check(brRecv($home, 'get ' . $n(8, 'meta'))[1] === 'meta8' && preg_match('/^avail_kib \d+$/', trim(brRecv($home, 'df')[1])) === 1, 'get, df');
            check((fileperms("$dir/" . $n(8, 'meta')) & 0777) === 0600, 'archive mode');
        } finally {
            testRmTree($home);
        }
    },
    'backup remote schedule: one tagged crontab line, recorded so ensureScheduled restores it after a move; --off removes both' => function () {
        $fx = brHome();
        try {
            list($code) = brRun($fx, ['schedule', '--daily', '25:00', '--mode', 'meta']);
            check($code === 1 && !is_file($fx['dir'] . '/crontab'), 'a bad time was scheduled');
            file_put_contents($fx['dir'] . '/crontab', "@reboot ~/start-rtorrent\n");
            list($code, , $out) = brRun($fx, ['schedule', '--daily', '04:30', '--mode', 'meta']);
            $tab = (string) file_get_contents($fx['dir'] . '/crontab');
            // The PHP that ran the verb, by full path: cron's PATH may hold another or none.
            check($code === 0 && preg_match('#^30 4 \* \* \* ' . preg_quote(escapeshellarg(PHP_BINARY), '#')
                . ' \'\S+/tools/backup/remote/remote\.php\' run --mode meta > \S+ 2>&1 \# pikkumies-backup-remote$#m', $tab)
                && strpos($tab, "@reboot ~/start-rtorrent\n") === 0, 'crontab: ' . $tab . $out);
            $kept = $fx['localdir'] . '/secrets/cron/backup-remote.cron';
            check(is_file($kept) && (fileperms($kept) & 0777) === 0600, 'line not recorded');
            file_put_contents($fx['dir'] . '/crontab', "@reboot ~/start-rtorrent\n");
            Update::$crontab = $fx['bin'] . '/crontab';
            Update::$keptDir = dirname($kept);
            $restored = testWithEnv(['FAKE_DIR' => $fx['dir'], 'PATH' => $fx['bin'] . ':' . getenv('PATH')], function () {
                return Update::ensureScheduled(Config::merge(Config::defaults(), ['autoUpdate' => ['disabled' => true], 'cron' => ['disabled' => true]]), 1061, false);
            });
            check(count($restored) === 1 && strpos((string) file_get_contents($fx['dir'] . '/crontab'), '# pikkumies-backup-remote') !== false,
                'not restored: ' . json_encode($restored));
            list($code) = brRun($fx, ['schedule', '--off']);
            check($code === 0 && strpos((string) file_get_contents($fx['dir'] . '/crontab'), 'pikkumies-backup-remote') === false && !is_file($kept), 'off');
        } finally {
            Update::$crontab = 'crontab';
            Update::$keptDir = null;
            stDone($fx);
        }
    },
    'backup remote passphrase verb refuses without a terminal, no verb the agent runs prints it, and a terminal shows it' => function () {
        $fx = brHome();
        try {
            $all = '';
            foreach ([['passphrase'], ['passphrase', '--confirm-saved'], ['stats'], ['list'], ['run', '--mode', 'meta']] as $args) {
                $all .= brRun($fx, $args)[2];
            }
            check(strpos($all, brPhrase($fx)) === false, 'a verb printed the passphrase');
            check(strpos($all, 'own terminal') !== false && brRun($fx, ['stats'])[1][0]['passphrase_saved_by_user'] === false, 'refusal: ' . $all);
            $script = trim((string) shell_exec('command -v script 2>/dev/null'));
            if ($script === '') {
                skip('script(1) is not installed, so the terminal case cannot run here; the refusal above did');
            }
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(stScript($fx, 'backup/remote/remote.php', ['remoteLocalDir' => $fx['localdir'],
                'remoteMountinfo' => $fx['mountinfo']]));
            $shown = (string) shell_exec(escapeshellarg($script) . ' -qec ' . escapeshellarg("$cmd passphrase") . ' /dev/null < /dev/null');
            check(strpos($shown, brPhrase($fx)) !== false, 'not shown on a terminal: ' . $shown);
            shell_exec(escapeshellarg($script) . ' -qec ' . escapeshellarg("$cmd passphrase --confirm-saved") . ' /dev/null < /dev/null');
            clearstatcache();
            check(brRun($fx, ['stats'])[1][0]['passphrase_saved_by_user'] === true, 'confirm-saved not recorded');
        } finally {
            stDone($fx);
        }
    },
    'backup remote run: an upload that dies is reported as the upload, not as the stages that then hit the broken pipe' => function () {
        $fx = brHome(['die_put' => true]);
        try {
            file_put_contents($fx['home'] . '/.config/app/big.bin', random_bytes(2097152)); // more than a pipe holds, so gpg sees the break
            list($code, $l, $out) = brRun($fx, ['run', '--mode', 'meta']);
            check($code === 1 && strpos(end($l)['error'], 'the upload failed (exit 255)') === 0 && strpos(end($l)['error'], 'Connection reset') !== false
                && strpos($out, 'gpg failed') === false && strpos($out, 'tar failed') === false, 'blame: ' . $out);
        } finally {
            stDone($fx);
        }
    },
    'backup remote run: every failure, also before anything is sent (lock, host key), replaces last_run with ok false' => function () {
        $fx = brHome();
        try {
            check(brRun($fx, ['run', '--mode', 'meta'])[0] === 0, 'first run');
            $lock = fopen($fx['secrets'] . '/run.lock', 'c');
            flock($lock, LOCK_EX);
            list($code) = brRun($fx, ['run', '--mode', 'meta']);
            flock($lock, LOCK_UN);
            fclose($lock);
            $last = brRun($fx, ['stats'])[1][0]['last_run'];
            check($code === 1 && $last['ok'] === false && strpos($last['error'], 'another backup run') !== false && $last['mode'] === 'meta',
                'lock: ' . json_encode($last));
            brSetState($fx, ['host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAISomeoneElseInBetween00000000000000']);
            list($code) = brRun($fx, ['run', '--mode', 'full']);
            $last = brRun($fx, ['stats'])[1][0]['last_run'];
            check($code === 1 && $last['ok'] === false && strpos($last['error'], 'identity') !== false && $last['mode'] === 'full',
                'host key: ' . json_encode($last));
        } finally {
            stDone($fx);
        }
    },
    'backup remote label: the account name comes from the passwd entry before USER, so it does not depend on the environment' => function () {
        require_once dirname(__DIR__) . '/pikkumies/tools/backup/remote/RemoteDest.php';
        $pw = function_exists('posix_getpwuid') ? @posix_getpwuid(posix_geteuid()) : false;
        $label = testWithEnv(['USER' => 'not-the-account'], function () {
            return RemoteDest::label();
        });
        $who = is_array($pw) ? $pw['name'] : 'not-the-account';
        check(substr($label, -strlen($who) - 1) === "-$who", "label $label, expected the name $who");
        $noUser = testWithEnv(['USER' => null], function () {
            return RemoteDest::label();
        });
        check(preg_match('/^[A-Za-z0-9._-]+$/', $noUser) === 1 && (!is_array($pw) || $noUser === $label), "without USER: $noUser");
    },
];
