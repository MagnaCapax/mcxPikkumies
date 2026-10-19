<?php
/**
 * system-status health (ADR-0066): the panel probe and PMSS's 502 reasons, torrent clients that restarted while the
 * machine kept running, a stale rTorrent socket, quota and memory findings, no probe off PMSS, nothing that names the
 * account, and DocSources::pageUrl. A fake panel (`php -S`, its answer read from a file per request), a fake /proc, a
 * temporary HOME; the 502 bodies copy the plain-text head of PMSS's renderer (watchdogErrorPageFile.php:34-40).
 */
require_once __DIR__ . '/fixtures/storage/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/system-status/StatusHealth.php';

if (!function_exists('shPanel')) {
    /** [proc, base url, dir]: answers with the code and body in $dir/answer.json, and counts requests in $dir/hits. */
    function shPanel()
    {
        $dir = testTempDir('pk-sh');
        file_put_contents("$dir/router.php", '<?php file_put_contents(__DIR__ . "/hits", "x", FILE_APPEND);'
            . ' $a = json_decode(file_get_contents(__DIR__ . "/answer.json"), true); http_response_code($a[0]); echo $a[1];');
        file_put_contents("$dir/answer.json", json_encode([401, 'auth']));
        $srv = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($srv, false), ':'), 1);
        fclose($srv);
        $proc = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg("$dir/router.php"), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50 && !($s = @fsockopen('127.0.0.1', $port, $e, $es, 0.1)); $i++) {
            usleep(100000);
        }
        if ($s) {
            fclose($s);
        }
        return [$proc, "http://127.0.0.1:$port", $dir];
    }

    function sh502($headline)
    {
        return "502 - Bad Gateway\n\nYour web interface could not be loaded.\n\nSpecific status:\n- $headline\n- detail\n\nPlease wait 1-2 minutes and try again.\n";
    }

    /** A temporary HOME and /proc: uptime $up s, the rTorrent socket when $socket, and rTorrent processes [pid => started s after boot]. */
    function shHost($up, $socket, array $procs)
    {
        $d = testTempDir('pk-shh');
        mkdir("$d/home", 0700);
        mkdir("$d/proc/self/ns", 0700, true);
        symlink('mnt:[1]', "$d/proc/self/ns/mnt");
        file_put_contents("$d/proc/uptime", "$up.42 100.00\n");
        foreach ($procs as $pid => $after) {
            mkdir("$d/proc/$pid/ns", 0700, true);
            file_put_contents("$d/proc/$pid/comm", "rtorrent main\n");
            file_put_contents("$d/proc/$pid/stat", "$pid (rtorrent main) S " . str_repeat('0 ', 18) . ($after * 100) . " 0\n");
            symlink('mnt:[1]', "$d/proc/$pid/ns/mnt");
        }
        $srv = $socket ? stream_socket_server("unix://$d/home/.rtorrent.socket") : null;
        return [$d, $srv];
    }

    function shRecord($d, $family, $url = null, $usl = null)
    {
        return StatusHealth::record(['family' => $family, 'home' => "$d/home", 'uid' => posix_geteuid(), 'proc' => "$d/proc", 'panel_url' => $url, 'usl' => $usl]);
    }
}

return [
    'system-status health: each PMSS 502 reason maps to its key; a 502 without "Specific status:", a 504 or no server is no_answer; 401 and 302 answer' => function () {
        list($p, $url, $dir) = shPanel();
        try {
            foreach (StatusHealth::REASONS as $headline => $r) {
                file_put_contents("$dir/answer.json", json_encode([502, sh502($headline)]));
                $panel = StatusHealth::panel("$url/user-x/");
                check($panel['state'] === 'platform_reason' && $panel['reason'] === $r[0] && $panel['next_step'] === $r[1], "$headline: " . json_encode($panel));
            }
            check(count(StatusHealth::REASONS) === 7, 'PMSS has seven reasons (watchdogErrorPageFile.php:15-21)');
            foreach ([[502, "<html>502 Bad Gateway</html>"], [502, sh502('Something new.')], [504, 'gateway timeout']] as $a) {
                file_put_contents("$dir/answer.json", json_encode($a));
                check(StatusHealth::panel("$url/user-x/")['state'] === 'no_answer', 'not a platform reason: ' . json_encode($a));
            }
            foreach ([401, 302, 200] as $code) {
                file_put_contents("$dir/answer.json", json_encode([$code, 'x']));
                check(StatusHealth::panel("$url/user-x/")['state'] === 'answers', "HTTP $code from the account's own web server is an answer");
            }
        } finally {
            proc_terminate($p);
            proc_close($p);
            testRmTree($dir);
        }
        check(StatusHealth::panel('http://127.0.0.1:1/user-x/')['state'] === 'no_answer', 'nothing listening is no_answer');
    },
    'system-status health: rTorrent started long after boot is "the client restarted, not the server"; one started with the machine is fine' => function () {
        list($d, $srv) = shHost(86400, true, [500 => 86400 - 300]);
        try {
            $r = shRecord($d, 'pmss'); // no panel address: no probe
            check($r['clients'][0]['client'] === 'rtorrent' && $r['clients'][0]['state'] === 'listening' && $r['clients'][0]['running_s'] === 300, json_encode($r['clients']));
            check(strpos($r['summary'], 'rTorrent started 5 minutes ago while the machine kept running') === 0, 'summary: ' . $r['summary']);
        } finally {
            fclose($srv);
            testRmTree($d);
        }
        list($d, $srv) = shHost(86400, true, [500 => 30]);
        try {
            $r = shRecord($d, 'pmss');
            check($r['findings'] === [] && $r['clients'][0]['started_after_boot'] === false, 'a client started with the machine was reported: ' . json_encode($r));
        } finally {
            fclose($srv);
            testRmTree($d);
        }
    },
    'system-status health: on PMSS a socket with no rTorrent process is not running, with the watchdog timing; the panel is probed once and named nowhere' => function () {
        list($p, $url, $dir) = shPanel();
        list($d, $srv) = shHost(86400, true, []);
        try {
            $r = shRecord($d, 'pmss', "$url/user-fixture-acct/");
            $c = $r['clients'][0];
            check($c['state'] === 'not_running' && strpos($c['next_step'], 'every 2 minutes') !== false, 'stale socket: ' . json_encode($c));
            check($r['panel']['state'] === 'answers' && strlen((string) @file_get_contents("$dir/hits")) === 1, 'panel: ' . json_encode($r['panel']));
            $json = json_encode($r, JSON_UNESCAPED_SLASHES);
            check(strpos($json, 'fixture-acct') === false && strpos($json, '127.0.0.1') === false && strpos($json, $d) === false, 'the record names the account, the address or the home: ' . $json);
            $r = shRecord($d, 'generic', "$url/user-fixture-acct/");
            check($r['panel'] === null && strlen((string) @file_get_contents("$dir/hits")) === 1, 'probed off PMSS');
        } finally {
            fclose($srv);
            proc_terminate($p);
            proc_close($p);
            testRmTree($d);
            testRmTree($dir);
        }
    },
    'system-status health: the hard quota limit and PMSS memory HIGH are findings; OK memory and a plain quota are not' => function () {
        list($d, $srv) = shHost(86400, false, []);
        $head = "Disk quotas for user fake (uid 1001):\n     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n";
        $usl = require __DIR__ . '/fixtures/system-status/userShowLimits.php';
        $usl = ['generated_at' => $usl['generated_at'], 'sections' => $usl['sections']];
        try {
            Quota::$live = function () use ($head) { return $head . "       /dev/md2    100G   1024G   1280G   5 0 0\n"; };
            $r = shRecord($d, 'pmss', null, $usl);
            check(!preg_grep('/hard limit|memory/', $r['findings']) && $r['quota']['state'] === 'ok' && $r['memory']['status'] === 'OK', 'healthy: ' . json_encode($r));
            Quota::$live = function () use ($head) { return $head . "       /dev/md2    1280G*  1024G   1280G   5 0 0\n"; };
            foreach ($usl['sections']['memory']['metrics'] as $i => $m) {
                if ($m['metric'] === 'memory status') {
                    $usl['sections']['memory']['metrics'][$i]['value'] = 'HIGH';
                }
            }
            $r = shRecord($d, 'pmss', null, $usl);
            check($r['quota']['state'] === 'over_hard' && preg_grep('/hard limit/', $r['findings']) && preg_grep('/memory HIGH/', $r['findings']), 'findings: ' . json_encode($r['findings']));
        } finally {
            Quota::$live = null;
            testRmTree($d);
        }
    },
    'system-status health: space held by deleted-but-open files is a finding only when the quota is nearly full' => function () {
        list($d, $srv) = shHost(86400, false, []);
        $head = "Disk quotas for user fake (uid 1001):\n     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n";
        $usl = require __DIR__ . '/fixtures/system-status/userShowLimits.php';
        $usl = ['generated_at' => $usl['generated_at'], 'sections' => $usl['sections']];
        try {
            $usl['sections']['disk']['metrics'] = array_values(array_filter($usl['sections']['disk']['metrics'], function ($m) { return $m['metric'] !== 'held by deleted files'; }));
            Quota::$live = function () use ($head) { return $head . "       /dev/md2    1280G*  1024G   1280G   5 0 0\n"; };
            $r = shRecord($d, 'pmss', null, $usl);
            check($r['quota']['held_deleted_bytes'] === null && !preg_grep('/held by deleted/', $r['findings']), 'no metric: ' . json_encode($r['quota']));
            $usl['sections']['disk']['metrics'][] = ['metric' => 'held by deleted files', 'value' => 300.0 * 1073741824, 'limit' => null, 'unit' => 'bytes', 'percent' => null, 'source' => 'proc'];
            $r = shRecord($d, 'pmss', null, $usl);
            check(preg_grep('/^300 GiB of the quota is held by deleted files/', $r['findings']) && $r['quota']['held_deleted_bytes'] === 300.0 * 1073741824, 'full quota: ' . json_encode($r['findings']));
            Quota::$live = function () use ($head) { return $head . "       /dev/md2    100G   1024G   1280G   5 0 0\n"; };
            $r = shRecord($d, 'pmss', null, $usl);
            check(!preg_grep('/held by deleted/', $r['findings']), 'plenty of room: ' . json_encode($r['findings']));
        } finally {
            Quota::$live = null;
            testRmTree($d);
        }
    },
    'DocSources::pageUrl: the address of a downloaded wiki page by its title, any case; null for a missing page or a source that is not active' => function () {
        $tree = testTempDir('pk-pageurl');
        mkdir("$tree/config", 0700);
        mkdir("$tree/local/docs-cache/w", 0700, true);
        file_put_contents("$tree/config/documentationSources.md", "## w\n- type: mediawiki\n- url: https://wiki.example.org/api.php\n- trust: official\n- scope: all\n");
        file_put_contents("$tree/local/docs-cache/w/seedbox-storage-quota.md", "---\ntitle: \"Seedbox Storage Quota\"\nurl: \"https://wiki.example.org/Quota\"\n---\n# Seedbox Storage Quota\n");
        DocSources::$root = $tree;
        DocSources::$host = ['family' => 'pmss', 'stack' => 'none'];
        try {
            check(DocSources::pageUrl('seedbox storage QUOTA') === 'https://wiki.example.org/Quota', 'found: ' . var_export(DocSources::pageUrl('seedbox storage QUOTA'), true));
            check(DocSources::pageUrl('Diagnose on PMSS') === null, 'a missing page got an address');
            file_put_contents("$tree/config/documentationSources.md", "## w\n- type: mediawiki\n- url: https://wiki.example.org/api.php\n- trust: official\n- scope: all\n- status: off\n");
            check(DocSources::pageUrl('Seedbox Storage Quota') === null, 'a source that is off still answered');
        } finally {
            DocSources::$root = null;
            DocSources::$host = null;
            testRmTree($tree);
        }
    },
];
