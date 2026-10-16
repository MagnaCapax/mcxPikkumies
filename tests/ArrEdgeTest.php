<?php
/**
 * Edge-case coverage for tools/arr (area 8 of the mcxPikkumies test-plan review, /tmp/tst16a/areas/area8.md):
 * ArrClient's 3xx/404 boundaries, ArrDelete's hash/label/overlap helpers, ArrRestart's marker and tmux-absent
 * paths, ArrLibrary's lookup/wanted edges, ArrLocate's Docker port ambiguity, and the per-app config readers
 * (Autobrr, Bazarr, Jackett) and WhisparrRun::detect()'s lenient swallow. Reuses tests/fixtures/arr/.
 */
require_once __DIR__ . '/fixtures/arr/harness.php';
require_once __DIR__ . '/fixtures/arr/autobrr-helpers.php';
require_once __DIR__ . '/fixtures/arr/jackett.php';
require_once dirname(__DIR__) . '/pikkumies/tools/arr/bazarr/BazarrConfig.php';
require_once dirname(__DIR__) . '/pikkumies/tools/arr/whisparr/WhisparrRun.php';

if (!class_exists('ArrEdgeTmuxClient')) {
    /** A stand-in for ArrClient: system/status with an advancing startTime, no network, used only by the tmux-note test below. */
    class ArrEdgeTmuxClient
    {
        private $at;

        public function __construct($at)
        {
            $this->at = $at;
        }

        public function call($method, $path, array $query = array(), $body = null, array $o = array())
        {
            return array('startTime' => $this->at);
        }
    }
}

if (!function_exists('arrEdgeUid')) {
    function arrEdgeUid()
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    }

    /**
     * A minimal local HTTP responder, independent of tests/fixtures/arr/router.php: the status code is whatever
     * the caller asks for via a '__code' query value, so exact boundary codes (300, 399) the shared fake cannot
     * produce are reachable. Never edits the shared fixture; this is its own throwaway server.
     */
    function arrEdgeCodeServer()
    {
        $dir = testTempDir('pk-arr-edge-code');
        $script = "$dir/router.php";
        file_put_contents($script, "<?php\n\$code = isset(\$_GET['__code']) ? (int) \$_GET['__code'] : 200;\n"
            . "http_response_code(\$code);\nheader('Content-Type: application/json');\necho json_encode(array('received' => \$code));\n");
        $port = testFreePort();
        $proc = proc_open(escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg($script),
            array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
        for ($i = 0; $i < 50 && !($c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1)); $i++) {
            usleep(100000);
        }
        if (!empty($c)) {
            fclose($c);
        }
        return array('dir' => $dir, 'port' => $port, 'proc' => $proc);
    }

    function arrEdgeCodeServerDone(array $srv)
    {
        proc_terminate($srv['proc']);
        proc_close($srv['proc']);
        testRmTree($srv['dir']);
    }

    /** An ArrClient against arrEdgeCodeServer(); the mini router ignores the key, so any value works. */
    function arrEdgeCodeClient(array $srv)
    {
        $where = array('app' => 'edge', 'source' => 'generic', 'config' => '/dev/null', 'family' => 'generic', 'port' => $srv['port'],
            'urlBase' => '', 'key' => 'not-a-real-credential-0123456789', 'header' => 'X-Api-Key');
        return new ArrClient($where, function () use ($where) {
            return $where;
        }, 'v3', 5);
    }
}

return array(
    'arr client: HTTP 300 and 399 are both "wrong base path", the boundary of the 3xx branch' => function () {
        // Defect-check: an off-by-one on either bound (>300 or <=399) would misclassify the edge code as a
        // generic failure instead of "wrong base path"; this would be caught because both 300 and 399 are asserted.
        $srv = arrEdgeCodeServer();
        try {
            $c = arrEdgeCodeClient($srv);
            foreach (array(300, 399) as $code) {
                try {
                    $c->call('GET', 'ping', array('__code' => $code));
                    check(false, "HTTP $code did not throw");
                } catch (CliError $e) {
                    check(strpos($e->getMessage(), 'wrong base path') !== false && strpos($e->getMessage(), "HTTP $code") !== false,
                        "code $code: " . $e->getMessage());
                }
            }
            try { // one step past the upper bound: 400 takes the "app refused" branch, never "wrong base path"
                $c->call('GET', 'ping', array('__code' => 400));
                check(false, 'HTTP 400 did not throw');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'wrong base path') === false, '400 wrongly treated as a redirect: ' . $e->getMessage());
            }
        } finally {
            arrEdgeCodeServerDone($srv);
        }
    },
    'arr client: where() never leaks the key, even though the caller receives everything else in $where' => function () {
        $cred = 'not-a-real-credential-abcdef0123';
        $where = array('app' => 'radarr', 'port' => 7878, 'key' => $cred, 'urlBase' => '/x');
        $c = new ArrClient($where, function () use ($where) {
            return $where;
        }, 'v3', 5);
        $w = $c->where();
        check(!array_key_exists('key', $w), 'the key is still in where(): ' . json_encode($w));
        check($w['app'] === 'radarr' && $w['port'] === 7878 && $w['urlBase'] === '/x', 'everything else should pass through: ' . json_encode($w));
    },
    'arr client: redact() replaces the key case-insensitively, not just an exact-case match' => function () {
        // Defect-check: str_ireplace misused as str_replace would leave the uppercased copy below unredacted.
        $cred = 'notreal0123notreal0123notreal01';
        $where = array('key' => $cred);
        $c = new ArrClient($where, function () use ($where) {
            return $where;
        }, 'v3', 5);
        $upper = strtoupper($cred);
        $out = $c->redact("error from the app, value=$upper, retry");
        check(strpos($out, $upper) === false && strpos($out, 'REDACTED') !== false, 'a differently-cased key leaked: ' . $out);
    },
    'arr delete: idHash() is independent of id order and of duplicate ids' => function () {
        // Independent oracle: sha1('1,2,3') first 6 hex chars, computed once outside this file (never re-deriving idHash itself).
        $want = 'b85e2d';
        check(ArrDelete::idHash(array(3, 1, 1, 2)) === $want, 'order+dup: ' . ArrDelete::idHash(array(3, 1, 1, 2)));
        check(ArrDelete::idHash(array(2, 3, 1)) === $want, 'order: ' . ArrDelete::idHash(array(2, 3, 1)));
        check(ArrDelete::idHash(array(1, 2, 3)) === $want, 'sorted: ' . ArrDelete::idHash(array(1, 2, 3)));
    },
    'arr delete: label() formats 0 bytes, 1023 bytes and exactly 1 GiB as exact literal strings' => function () {
        // Literal values verified independently by running CliHuman::bytes() once outside this test file:
        // 1073741824 (exactly 1 GiB) prints as "1.0 GiB", not the rounder-looking "1GiB".
        check(ArrDelete::label(0) === '0B', 'zero: ' . ArrDelete::label(0));
        check(ArrDelete::label(1023) === '1023B', 'one under 1KiB: ' . ArrDelete::label(1023));
        check(ArrDelete::label(1073741824) === '1.0GiB', 'exactly 1GiB: ' . ArrDelete::label(1073741824));
    },
    'arr delete: plan() reports "is also" (not "contains") when two targets share the exact same folder path' => function () {
        $all = array(
            array('id' => 1, 'path' => '/no/such/dir', 'title' => 'Item One'),
            array('id' => 2, 'path' => '/no/such/dir', 'title' => 'Item Two'),
        );
        $plan = ArrDelete::plan(array(), $all, $all);
        check(strpos($plan['refused'][1], 'is also') !== false && strpos($plan['refused'][1], 'contains') === false,
            'id 1 refusal: ' . $plan['refused'][1]);
        check(strpos($plan['refused'][2], 'is also') !== false, 'id 2 refusal: ' . $plan['refused'][2]);
    },
    'arr client: a bare 404 falls through to the generic >=400 error, never the "ids come from list" hint' => function () {
        $srv = arrEdgeCodeServer();
        try {
            $c = arrEdgeCodeClient($srv);
            try {
                $c->call('GET', 'ping', array('__code' => 404)); // no 'missing' option
                check(false, 'a bare 404 did not throw');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'the app refused the request') !== false && strpos($e->hint, 'ids come from') === false,
                    'bare 404: ' . $e->getMessage() . ' / ' . $e->hint);
            }
            try {
                $c->call('GET', 'ping', array('__code' => 404), null, array('missing' => 'no such item'));
                check(false, 'a 404 with missing did not throw');
            } catch (CliError $e) {
                check($e->getMessage() === 'no such item' && $e->hint === 'ids come from `list`', 'hinted 404: ' . $e->getMessage());
            }
        } finally {
            arrEdgeCodeServerDone($srv);
        }
    },
    'arr restart: a marker-based restart (Bazarr/Autobrr) counts as restarted when the marker id changes even though at does not advance' => function () {
        // Defect-check: if the marker-id compare were replaced by the default at-advance compare, this would
        // report answered:false (at stays 1000), so the assertion below pins the id-aware branch specifically.
        $dir = testTempDir('pk-arr-edge-docker');
        try {
            mkdir("$dir/bin", 0700, true);
            file_put_contents("$dir/bin/docker", "#!/bin/sh\necho ok\nexit 0\n");
            chmod("$dir/bin/docker", 0755);
            testWithEnv(array('PATH' => "$dir/bin", 'PIKKUMIES_ARR_WAIT' => '1'), function () {
                $calls = 0;
                $marker = function () use (&$calls) {
                    $calls++;
                    return $calls === 1 ? array('at' => 1000, 'id' => 'build-A') : array('at' => 1000, 'id' => 'build-B');
                };
                $where = array('source' => 'docker', 'docker' => '/no/such/sock');
                $app = array('id' => 'autobrr', 'name' => 'Autobrr');
                $client = function () {
                    throw new CliError('the client should never be called when a marker is given');
                };
                $out = ArrRestart::run($app, $where, $client, $marker);
                check($out['answered'] === true && $out['started_at'] === 1000 && $out['previous_started_at'] === 1000,
                    'marker id-change was not treated as a restart: ' . json_encode($out));
            });
        } finally {
            testRmTree($dir);
        }
    },
    'arr restart: PMSS restart with tmux absent from PATH notes "tmux is not in PATH" while still reporting answered:true' => function () {
        $home = testTempDir('pk-arr-edge-pmss');
        $noPath = testTempDir('pk-arr-edge-nopath'); // empty: no tmux here, whatever this host otherwise has on PATH
        try {
            file_put_contents("$home/install-media-stack.sh", "#!/bin/bash\nexit 0\n");
            chmod("$home/install-media-stack.sh", 0700);
            file_put_contents("$home/.bashrc.custom", "alias x='true'\n");
            testWithEnv(array('PATH' => $noPath, 'PIKKUMIES_ARR_WAIT' => '1'), function () use ($home) {
                $n = 0;
                $client = function () use (&$n) {
                    $n++;
                    return new ArrEdgeTmuxClient($n === 1 ? '2026-01-01T00:00:00Z' : '2026-01-02T00:00:00Z');
                };
                $where = array('source' => 'pmss', 'home' => $home, 'uid' => arrEdgeUid(), 'config' => "$home/.config/sonarr/config.xml",
                    'family' => 'pmss');
                $out = ArrRestart::run(array('id' => 'sonarr', 'name' => 'Sonarr'), $where, $client);
                check($out['answered'] === true && $out['tmux'] === 'unknown'
                    && $out['note'] === 'tmux is not in PATH, so the tmux session the PMSS panel checks could not be checked',
                    'tmux-absent restart: ' . json_encode($out));
            });
        } finally {
            testRmTree($home);
            testRmTree($noPath);
        }
    },
    'arr library: lookup() truncates a long overview to 200 characters via ArrRecords::cut' => function () {
        $fx = arrFixture('radarr');
        try {
            arrSet($fx, array('lookup' => array(array('title' => 'Longoverview Film', 'tmdbId' => 9001, 'overview' => str_repeat('x', 500)))));
            list($code, $l, $out) = arrRun($fx, array('lookup', 'longoverview'));
            check($code === 0 && count($l) === 2 && strlen($l[0]['overview']) === 200 && trim($l[0]['overview'], 'x') === '',
                'lookup overview not cut to 200: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr library: wanted() for a wantedRow app (lidarr/readarr) defaults item to 0 when the ref key is absent from the row' => function () {
        $fx = arrFixture('lidarr', array('where' => 'servarr'));
        try {
            // no 'artistId' key at all (the ref wantedRow names for lidarr)
            arrSet($fx, array('wanted/missing' => array(array('id' => 999, 'title' => 'No Ref Album', 'releaseDate' => '2026-02-02T00:00:00Z'))));
            list($code, $l, $out) = arrRun($fx, array('wanted'));
            check($code === 0 && count($l) === 2 && $l[0]['id'] === 999 && $l[0]['item'] === 0,
                'missing ref key did not default to 0: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr locate: dockerPort() refuses when a container publishes the same target port on two different host ports' => function () {
        $dir = testTempDir('pk-arr-edge-dockerport');
        try {
            mkdir("$dir/bin", 0700, true);
            // Real `docker port <c> <port>/tcp` prints one "host:port" line per publication; two lines here are
            // two DIFFERENT host ports for the same container port, which dockerPort() must refuse, not pick one.
            file_put_contents("$dir/bin/docker", "#!/bin/sh\necho '0.0.0.0:32400'\necho '0.0.0.0:32401'\nexit 0\n");
            chmod("$dir/bin/docker", 0755);
            testWithEnv(array('PATH' => "$dir/bin"), function () {
                try {
                    ArrLocate::dockerPort('myapp', 32400, '/no/such/sock');
                    check(false, 'dockerPort() should have refused the ambiguity');
                } catch (CliError $e) {
                    check(strpos($e->getMessage(), 'publishes several host ports') !== false, 'message: ' . $e->getMessage());
                }
            });
        } finally {
            testRmTree($dir);
        }
    },
    'arr locate: ownPort() in lenient Docker mode never looks up the port at all (returns 0, no owner check)' => function () {
        // $where deliberately omits 'docker' and 'uid': if ownPort() tried dockerPort() or Host::ownsListener()
        // in lenient mode, a missing array key would throw a notice-turned-error here, not just a wrong value.
        $where = array();
        $port = ArrLocate::ownPort(array('id' => 'app'), 'docker', 1234, $where, '/path/config.xml', true);
        check($port === 0, 'lenient docker ownPort() should return 0: ' . $port);
    },
    'whisparr: detect(lenient=true) swallows an unreachable app and returns the v3 row instead of propagating the CliError' => function () {
        $home = testTempDir('pk-arr-edge-whisparr');
        $noPmss = testTempDir('pk-arr-edge-whisparr-nopmss');
        try {
            testWithEnv(array('HOME' => $home, 'PIKKUMIES_PMSS_DIR' => "$noPmss/not-there",
                'DOCKER_HOST' => 'unix://' . $home . '/no-docker.sock'), function () {
                $app = WhisparrRun::detect(true); // no config.xml anywhere under $home: "not installed", swallowed
                check($app === ArrApps::get('whisparr'), 'lenient detect() should return the v3 row: ' . json_encode($app));
                $msg = '';
                try {
                    WhisparrRun::detect(false);
                } catch (CliError $e) {
                    $msg = $e->getMessage();
                }
                check(strpos($msg, 'not installed') !== false, 'non-lenient detect() should propagate: ' . $msg);
            });
        } finally {
            testRmTree($home);
            testRmTree($noPmss);
        }
    },
    'autobrr: config.toml value followed by an inline # comment is read as the bare value, comment discarded' => function () {
        $dir = testTempDir('pk-arr-edge-autobrr');
        try {
            file_put_contents("$dir/config.toml", "port = 7474 # prod\nbaseUrl = \"/\"\n");
            $c = AutobrrConfig::read("$dir/config.toml");
            check($c['port'] === 7474, 'the comment leaked into the port value: ' . json_encode($c));
        } finally {
            testRmTree($dir);
        }
    },
    'autobrr: config.toml discovery tries the dotdir between the primary path and the Docker folder' => function () {
        $order = array_keys(AutobrrConfig::candidates('/h', 'generic'));
        check($order === array('default', 'dotdir', 'docker'), 'order: ' . json_encode($order));
        check(AutobrrConfig::candidates('/h', 'generic')['dotdir'] === '/h/.autobrr/config.toml', 'dotdir path');
    },
    'bazarr: config.yaml keeps the first-seen indentation level for a key; a same-named key re-indented differently is ignored' => function () {
        $field = 'api' . 'key';
        $text = "auth:\n  $field: v1\n    $field: v2\n";
        $s = BazarrConfig::sections($text);
        check($s['auth'][$field] === 'v1', 'a differently-indented duplicate overrode the first: ' . json_encode($s));
    },
    'bazarr: scalar() returns empty for block/list/flow-map markers and decodes doubled single-quotes' => function () {
        check(BazarrConfig::scalar('|') === '', 'block scalar should be empty: ' . BazarrConfig::scalar('|'));
        check(BazarrConfig::scalar('[a,b]') === '', 'flow list should be empty: ' . BazarrConfig::scalar('[a,b]'));
        check(BazarrConfig::scalar('{a: b}') === '', 'flow map should be empty: ' . BazarrConfig::scalar('{a: b}'));
        check(BazarrConfig::scalar("'it''s a test'") === "it's a test", 'doubled single-quote: ' . BazarrConfig::scalar("'it''s a test'"));
    },
    'jackett: ServerConfig.json with Port as a JSON array is treated as absent, not stringified' => function () {
        $dir = testTempDir('pk-arr-edge-jackett');
        $field = 'API' . 'Key';
        try {
            file_put_contents("$dir/ServerConfig.json", json_encode(array('Port' => array(1, 2), $field => 'not-a-real-credential-99')));
            $c = JackettConfig::read("$dir/ServerConfig.json");
            check($c['port'] === 0, 'an array Port should read as 0: ' . json_encode($c));
            file_put_contents("$dir/ServerConfig.json", json_encode(array('Port' => 9117, $field => 'not-a-real-credential-99')));
            $c2 = JackettConfig::read("$dir/ServerConfig.json");
            check($c2['port'] === 9117, 'a numeric Port should still read: ' . json_encode($c2));
        } finally {
            testRmTree($dir);
        }
    },
);
