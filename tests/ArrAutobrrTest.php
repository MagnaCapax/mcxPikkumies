<?php
/** The autobrr unit against the fake autobrr API (tests/fixtures/arr/autobrr-router.php, phase2.php, autobrr-wrapper.php). */
require_once __DIR__ . '/fixtures/arr/autobrr-helpers.php';

return [
    'autobrr: login stores only a key autobrr accepts (0600, in local/secrets), read from a terminal, never printed' => function () {
        $fx = arrOtherFixture('autobrr');
        $file = $fx['dir'] . '/secrets/autobrr';
        try {
            list($code, $l, $out) = abRun($fx, ['list']);
            check($code === 1 && strpos($out, 'no autobrr API key is stored') !== false
                && strpos($out, 'autobrr.php login') !== false, 'no key: ' . $out);
            list($code, $l, $out) = abRun($fx, ['login']);
            check($code === 1 && strpos($out, 'stdin is not one') !== false && !file_exists($file), 'no terminal: ' . $out);
            list($code, $l, $out) = abRun($fx, ['login'], 'wrongkey0000000000000000000000ab');
            check($code === 1 && strpos($out, 'rejected that key; nothing was stored') !== false
                && !file_exists($file), 'wrong key: ' . $out);
            list($code, $l, $out) = abRun($fx, ['login'], ARR_KEY);
            clearstatcache();
            check($code === 0 && $l[0]['verified'] === true && (fileperms($file) & 0777) === 0600
                && (fileperms(dirname($file)) & 0777) === 0700
                && (new AutobrrSecret(dirname($file)))->load() === ARR_KEY, 'login: ' . $out);
            list($code, $l, $out) = abRun($fx, ['stats']);
            check($code === 0 && $l[0]['version'] === 'v1.70.0', 'the stored key is used: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'autobrr: list, show, history, health and stats print allowed fields only; the key only in X-API-Token' => function () {
        $fx = arrOtherFixture('autobrr');
        abLogin($fx);
        try {
            $all = '';
            foreach ([['list'], ['show', '1', '2'], ['history'], ['health'], ['stats']] as $args) {
                list($code, $l, $out) = abRun($fx, $args);
                check($code === 0, implode(' ', $args) . ': ' . $out);
                $all .= $out;
            }
            check(strpos($all, ARR_LEAK) === false, 'a tracker setting, IRC password or download link was printed: ' . $all);
            list($code, $l, $out) = abRun($fx, ['show', '1']);
            check($l[0]['name'] === 'TV 1080p' && $l[0]['indexers'] === ['Tracker One'] && $l[0]['resolutions'] === ['1080p']
                && $l[0]['action_list'] === [['name' => 'to qbit', 'type' => 'QBITTORRENT', 'enabled' => true]], 'show: ' . $out);
            list($code, $l, $out) = abRun($fx, ['history']);
            check($l[0]['title'] === 'Show.S01E01.1080p' && $l[0]['actions'][0]['status'] === 'PUSH_APPROVED'
                && !isset($l[0]['download_url']), 'history: ' . $out);
            list($code, $l, $out) = abRun($fx, ['health']);
            check($l[0]['type'] === 'ok' && $l[1]['source'] === 'irc TrackerNet' && $l[1]['type'] === 'warning', 'health: ' . $out);
            list($code, $l, $out) = abRun($fx, ['stats']);
            check($l[0]['filters'] === 2 && $l[0]['filters_enabled'] === 1 && $l[0]['releases_total'] === 50
                && $l[0]['health_warnings'] === 1
                && $l[0]['found'] === 'pmss' && $l[0]['url_base'] === '/public-' . arrUser() . '/autobrr'
                && $l[0]['url_base_from'] === 'installer environment' && !isset($l[0]['url_base_note']), 'stats: ' . $out);
            list($code, $l, $out) = abRun($fx, ['show', '1', '9']);
            check($code === 1 && strpos($out, 'no filter 9') !== false, 'an unknown id: ' . $out);
            foreach (arrState($fx)['calls'] as $c) {
                check(stripos($c['query'], 'apikey') === false
                    && $c['method'] === 'GET', 'a key in a URL, or a change: ' . json_encode($c));
            }
        } finally {
            arrP2Done($fx);
        }
    },
    'autobrr: toggle turns named filters on or off and reads them back; exactly one of --on or --off' => function () {
        $fx = arrOtherFixture('autobrr');
        abLogin($fx);
        try {
            foreach ([['toggle', '2'], ['toggle', '2', '--on', '--off'], ['toggle', '2', '9', '--on'], ['toggle', '--on']] as $args) {
                list($code, $l, $out) = abRun($fx, $args);
                check($code === 1 && !arrCalls($fx, 'PUT', 'filters/2/enabled'), implode(' ', $args) . ' changed something: ' . $out);
            }
            list($code, $l, $out) = abRun($fx, ['toggle', '2', '--on']);
            check($code === 0 && $l[0]['was_enabled'] === false && $l[0]['enabled'] === true && $l[1]['verified'] === true
                && arrCalls($fx, 'PUT', 'filters/2/enabled')[0]['body'] === ['enabled' => true], 'toggle on: ' . $out);
            arrSet($fx, ['stuck' => true]);
            list($code, $l, $out) = abRun($fx, ['toggle', '1', '--off']);
            $want = 'filter 1 (TV 1080p): failed, now on, autobrr accepted the change but reads back otherwise';
            check($code === 1 && strpos($out, $want) !== false, 'a change that did not stick: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'autobrr: the key never reaches the output (401, a 500 that echoes it, a dead port)' => function () {
        $fx = arrOtherFixture('autobrr', ['key' => 'another-key-autobrr-has-now-0000']);
        abLogin($fx);
        try {
            list($code, $l, $out) = abRun($fx, ['list']);
            check($code === 1 && strpos($out, 'rejected the stored API key') !== false
                && strpos($out, 'runs `login` again') !== false, '401: ' . $out);
            arrSet($fx, ['key' => ARR_KEY, 'mode' => 'http500']);
            list($code, $l, $out) = abRun($fx, ['list']);
            check($code === 1 && strpos($out, 'HTTP 500') !== false && strpos($out, 'REDACTED') !== false, '500: ' . $out);
        } finally {
            arrP2Done($fx);
        }
        $fx = arrOtherFixture('autobrr', ['start' => false]);
        abLogin($fx);
        try {
            list($code, $l, $out) = abRun($fx, ['list']);
            check($code === 1 && strpos($out, 'not running') !== false
                && strpos($out, 'tmux session: stopped') !== false, 'dead port: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'autobrr: config.toml read with regexes (legacy mode, [tables] ignored); a port another account owns is refused' => function () {
        $tmp = sys_get_temp_dir() . '/pk-ab-' . getmypid() . '-' . mt_rand() . '.toml';
        file_put_contents($tmp, "host = \"127.0.0.1\"\nport = 7575\n#baseUrl = \"/x/\"\n\n[metrics]\nport = 1\nbaseUrl = \"/evil/\"\n");
        $c = AutobrrConfig::read($tmp);
        check($c['port'] === 7575 && $c['apiBase'] === '/api/' && $c['urlBase'] === '', 'legacy: ' . json_encode($c));
        file_put_contents($tmp, "baseUrl = \"/a/../b/\"\nbaseUrlModeLegacy = false\n");
        check(AutobrrConfig::read($tmp)['apiBase'] === '' && AutobrrConfig::read($tmp)['port'] === 7474, 'a traversing baseUrl was used');
        unlink($tmp);
        $fx = arrOtherFixture('autobrr', ['start' => false]);
        abLogin($fx);
        try {
            arrPhase2OwnerRefusal(function ($tables) use ($fx) {
                return AutobrrConfig::find($fx['home'], 'generic', arrUid(), new AutobrrSecret($fx['dir'] . '/secrets'), [], false, $tables);
            }, $fx['port']);
            check(AutobrrRun::listener($fx['port'], arrUid(), arrP2Tables($fx['port'], arrUid(), 777)) === '777'
                && AutobrrRun::listener($fx['port'], arrUid() + 1, arrP2Tables($fx['port'], arrUid())) === '', 'listener inode');
        } finally {
            arrP2Done($fx);
        }
    },
    'autobrr: PMSS restart is the panel\'s --stop-app/--start-app; it counts when a new process listens and its tmux session runs' => function () {
        $fx = arrOtherFixture('autobrr');
        abLogin($fx);
        try {
            $d = $fx['dir'];
            $serve = 'ARR_FAKE_STATE=' . escapeshellarg("$d/state.json") . ' nohup ' . escapeshellarg(PHP_BINARY)
                . " -S 127.0.0.1:{$fx['port']} "
                . escapeshellarg($fx['router']) . " >/dev/null 2>&1 & echo \$! > \"$d/server.pid\"";
            file_put_contents($fx['home'] . '/install-media-stack.sh', "#!/bin/bash\necho \"\$*\" >> \"$d/installer.log\"\ncase \"\$1\" in\n"
                . "  --stop-app=*) kill \$(cat \"$d/server.pid\"); rm -f \"$d/tmux-up\"; sleep 0.5 ;;\n"
                . "  --start-app=*) $serve; touch \"$d/tmux-up\" ;;\nesac\n");
            file_put_contents($fx['home'] . '/.bashrc.custom', "alias autobrr='true'\n");
            touch($fx['home'] . '/.autobrrDisable');
            list($code, $l, $out) = abRun($fx, ['restart']);
            check($code === 1 && strpos($out, 'turned off') !== false && !file_exists("$d/installer.log"), 'restart while off: ' . $out);
            unlink($fx['home'] . '/.autobrrDisable');
            list($code, $l, $out) = abRun($fx, ['restart'], null, ['PIKKUMIES_ARR_WAIT' => '10']);
            check($code === 0 && $l[0]['answered'] === true && $l[0]['tmux'] === 'running'
                && file_get_contents("$d/installer.log") === "--stop-app=autobrr\n--start-app=autobrr\n", 'restart: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'autobrr: a stored key others can read is set back to 0600; another owner or a symlink is refused, login still works' => function () {
        $fx = arrOtherFixture('autobrr');
        $file = $fx['dir'] . '/secrets/autobrr';
        abLogin($fx);
        try {
            chmod($file, 0644);
            list($code, $l, $out, $err) = abRun($fx, ['stats']);
            clearstatcache();
            check($code === 0 && (fileperms($file) & 0777) === 0600 && strpos($err, 'set to 0600') !== false, 'a 0644 key file: ' . $out . $err);
            try {
                (new AutobrrSecret(dirname($file), arrUid() + 1))->load();
                check(false, 'a key file another account owns was trusted');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'belongs to uid') !== false && strpos($e->hint, 'autobrr.php login') !== false,
                    'owner: ' . $e->getMessage());
            }
            rename($file, "$file.real");
            symlink("$file.real", $file);
            list($code, $l, $out) = abRun($fx, ['stats']);
            check($code === 1 && strpos($out, 'is a symlink') !== false && strpos($out, 'login') !== false, 'a symlinked key file: ' . $out);
            list($code, $l, $out) = abRun($fx, ['login'], ARR_KEY);
            clearstatcache();
            check($code === 0 && !is_link($file) && (fileperms($file) & 0777) === 0600, 'login replaces a refused file: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'autobrr: history reads page by page (offset, limit); --limit 0 is "as many as fit" and marks truncation' => function () {
        $fx = arrOtherFixture('autobrr');
        abLogin($fx);
        try {
            $r = arrState($fx)['releases'][0];
            $rows = [];
            for ($i = 1; $i <= 250; $i++) {
                $rows[] = ['id' => $i] + $r;
            }
            arrSet($fx, ['releases' => $rows]);
            list($code, $l, $out) = abRun($fx, ['history', '--limit', '0']);
            $q = array_values(array_map(function ($c) {
                return $c['query'];
            }, array_filter(arrState($fx)['calls'], function ($c) {
                return $c['path'] === 'release';
            })));
            $st = end($l);
            check($code === 0 && $q === ['offset=0&limit=200', 'offset=200&limit=200'] && $st['releases'] === 250
                && $st['truncated'] === true && $st['total'] === 250, 'history --limit 0: ' . json_encode($q) . $out);
        } finally {
            arrP2Done($fx);
        }
    },
];
