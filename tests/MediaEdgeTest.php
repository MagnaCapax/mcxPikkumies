<?php
/**
 * Edge-case coverage for tools/media-servers (area 8 of the mcxPikkumies test-plan review,
 * /tmp/tst16a/areas/area8.md): MediaSecret's device id and unverified-login paths (never its save()/write
 * path, owned by another builder), MediaDocker's ambiguity/availability guards, MediaHttp's refusal-before-
 * header-Closure and bare-query-string edges, MediaRestart's marker-file and never-answers paths,
 * MediaEndStream's dedup and stop-after-rejection rules, MediaServers' clamping/picking/scrubbing helpers,
 * PlexLocal/PlexServer, JellyfinLocate's base-path choice, and MediaLocal's budget-drop order.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/lib/MediaServers.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/plex/PlexRecords.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/plex/PlexClient.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/plex/PlexServer.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/plex/PlexLocal.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/jellyfin/JellyfinLocate.php';

if (!class_exists('MediaEdgeDockerRestartStub')) {
    /** A stand-in for MediaDocker in MediaRestart::run()'s docker branch: restart() always reports success. */
    class MediaEdgeDockerRestartStub
    {
        public function restart($id)
        {
            return array(true, 'restarted');
        }
    }
}

if (!function_exists('mediaEdgeUid')) {
    function mediaEdgeUid()
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    }

    /** A fake rootless Docker (tests/fixtures/media/docker.php) this process owns, with the given `docker ps` containers. */
    function mediaEdgeDockerFixture(array $containers)
    {
        $dir = testTempDir('pk-media-edge-docker');
        mkdir("$dir/bin", 0700, true);
        file_put_contents("$dir/bin/docker", '#!' . PHP_BINARY . "\n" . file_get_contents(__DIR__ . '/fixtures/media/docker.php'));
        chmod("$dir/bin/docker", 0700);
        $sock = stream_socket_server('unix://' . $dir . '/docker.sock');
        file_put_contents("$dir/docker.json", json_encode(array('containers' => $containers, 'calls' => array())));
        return array('dir' => $dir, 'sock' => $sock, 'path' => "$dir/docker.sock");
    }

    function mediaEdgeDockerDone(array $fx)
    {
        fclose($fx['sock']);
        testRmTree($fx['dir']);
    }
}

return array(
    'media secret: deviceId() discards a malformed stored id and generates a fresh pikkumies-<16hex> one' => function () {
        $dir = testTempDir('pk-media-edge-secret');
        try {
            $secret = new MediaSecret($dir, 'edgeunit');
            $secret->update(array('device_id' => 'not-the-right-shape'));
            $id = $secret->deviceId();
            check(preg_match('/^pikkumies-[0-9a-f]{16}$/', $id) === 1 && $id !== 'not-the-right-shape',
                'a malformed stored id was reused: ' . $id);
            check($secret->load()['device_id'] === $id, 'the fresh id was not persisted');
        } finally {
            testRmTree($dir);
        }
    },
    'media secret: login() stores the key unverified with a redacted note when verify() throws a plain CliError (not MediaAuthRejected)' => function () {
        $script = null;
        foreach (explode(':', (string) getenv('PATH')) as $d) {
            $script = $script ?: (is_executable("$d/script") ? "$d/script" : null);
        }
        if ($script === null) {
            skip('this test needs script(1) from util-linux (bsdutils on Debian) to give login a terminal');
        }
        $dir = testTempDir('pk-media-edge-login');
        try {
            $runner = "$dir/run.php";
            file_put_contents($runner, '<?php
require ' . var_export(dirname(__DIR__) . '/pikkumies/tools/media-servers/lib/MediaSecret.php', true) . ';
require ' . var_export(dirname(__DIR__) . '/pikkumies/lib/Cli.php', true) . ';
$secret = new MediaSecret(' . var_export($dir . '/secrets', true) . ', "edgeunit");
$r = $secret->login("Key: ", "edge.php", function ($typed) {
    throw new CliError("the server answered something odd about the typed value", "ignore");
});
echo json_encode($r), "\n";
');
            $typed = 'typed-value-that-is-not-a-real-credential';
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner);
            $p = proc_open(escapeshellarg($script) . ' -qec ' . escapeshellarg($cmd) . ' /dev/null',
                array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            fwrite($pipes[0], "$typed\n");
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
            $line = null;
            foreach (explode("\n", $out) as $one) {
                $one = trim($one);
                if ($one !== '' && $one[0] === '{') {
                    $line = $one;
                }
            }
            check($line !== null, 'no JSON record printed: ' . $out);
            $r = json_decode($line, true);
            check($r['records'][0]['verified'] === false && strpos($r['records'][0]['note'], 'stored without a check:') === 0
                && strpos($r['records'][0]['note'], $typed) === false, 'record: ' . $out);
            $stored = json_decode((string) file_get_contents("$dir/secrets/edgeunit"), true);
            check($stored['key'] === $typed && !array_key_exists('key_rejected', $stored), 'stored: ' . json_encode($stored));
        } finally {
            testRmTree($dir);
        }
    },
    'media docker: find() refuses with both container names when two containers match the image and port' => function () {
        $fx = mediaEdgeDockerFixture(array(
            array('id' => 'c1', 'image' => 'lscr.io/linuxserver/jellyfin', 'name' => 'jellyfin1', 'ports' => '127.0.0.1:18097->8096/tcp'),
            array('id' => 'c2', 'image' => 'lscr.io/linuxserver/jellyfin', 'name' => 'jellyfin2', 'ports' => '127.0.0.1:18098->8096/tcp'),
        ));
        try {
            testWithEnv(array('PATH' => $fx['dir'] . '/bin'), function () use ($fx) {
                $md = new MediaDocker(mediaEdgeUid(), $fx['path']);
                check($md->available() === true, 'the fake docker socket should be owned by this test');
                try {
                    $md->find('#jellyfin#i', 8096);
                    check(false, 'find() should have refused the ambiguity');
                } catch (CliError $e) {
                    check(strpos($e->getMessage(), 'jellyfin1') !== false && strpos($e->getMessage(), 'jellyfin2') !== false,
                        'message: ' . $e->getMessage());
                }
            });
        } finally {
            mediaEdgeDockerDone($fx);
        }
    },
    'media docker: mountSource() returns \'\' when no mount matches the requested destination' => function () {
        $fx = mediaEdgeDockerFixture(array(
            array('id' => 'c1', 'image' => 'lscr.io/linuxserver/jellyfin', 'name' => 'jellyfin1', 'ports' => '127.0.0.1:18097->8096/tcp',
                'mounts' => array(array('Type' => 'bind', 'Source' => '/host/other', 'Destination' => '/other'))),
        ));
        try {
            testWithEnv(array('PATH' => $fx['dir'] . '/bin'), function () use ($fx) {
                $md = new MediaDocker(mediaEdgeUid(), $fx['path']);
                $found = $md->find('#jellyfin#i', 8096);
                check($found !== null, 'the single container should have matched');
                check($md->mountSource($found['id'], '/config') === '', 'an unmatched destination should return empty, not throw or guess');
            });
        } finally {
            mediaEdgeDockerDone($fx);
        }
    },
    'media docker: available() returns false without ever invoking `docker` when there is no owned socket' => function () {
        $dir = testTempDir('pk-media-edge-nosock');
        try {
            mkdir("$dir/bin", 0700, true);
            file_put_contents("$dir/bin/docker", "#!/bin/sh\ntouch " . escapeshellarg("$dir/docker-was-called") . "\nexit 1\n");
            chmod("$dir/bin/docker", 0755);
            testWithEnv(array('PATH' => $dir . '/bin'), function () use ($dir) {
                $md = new MediaDocker(mediaEdgeUid(), ''); // explicit '': none of the user's own
                check($md->available() === false, 'available() should be false with no owned socket');
                check($md->find('#.*#', 80) === null, 'find() should return null with no owned socket');
                check(!file_exists("$dir/docker-was-called"), 'docker was invoked despite no owned socket');
            });
        } finally {
            testRmTree($dir);
        }
    },
    'media http: a Closure header is never invoked when the request is refused by the allowlist' => function () {
        // Defect-check: if reachable()/headers ran before the allowlist check, the counter below would be > 0.
        $calls = 0;
        $http = new MediaHttp('Edge', 'http://127.0.0.1:1', array('GET' => array('#^/ok$#')), mediaEdgeUid(), 5, array('', ''));
        try {
            $http->send('GET', '/not-allowed', array(), null, array(function () use (&$calls) {
                $calls++;
                return 'X-Edge: made';
            }));
            check(false, 'a disallowed path should have been refused');
        } catch (CliError $e) {
            check(strpos($e->getMessage(), 'is not something this tool does') !== false, 'message: ' . $e->getMessage());
        }
        check($calls === 0, 'the header Closure was invoked despite the refusal');
    },
    'media http: local() refuses a URL with a bare trailing \'?\' (empty query string), not only a populated one' => function () {
        try {
            MediaHttp::local('http://127.0.0.1:1/');
        } catch (CliError $e) {
            check(false, 'a plain URL without a query string should not be refused: ' . $e->getMessage());
        }
        try {
            MediaHttp::local('http://127.0.0.1:1/?');
            check(false, 'a bare trailing ? should have been refused');
        } catch (CliError $e) {
            check(strpos($e->getMessage(), 'only talk to http://127.0.0.1') !== false, 'message: ' . $e->getMessage());
        }
    },
    'media restart: pmssGate() refuses when the disable marker exists as a directory, not just a symlink' => function () {
        $home = testTempDir('pk-media-edge-pmssgate');
        try {
            mkdir("$home/.jellyfinDisable", 0700);
            try {
                MediaRestart::pmssGate($home, 'jellyfin');
                check(false, 'a directory marker should have refused');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'is not a plain file') !== false, 'directory marker message: ' . $e->getMessage());
            }
            rmdir("$home/.jellyfinDisable");
            file_put_contents("$home/.jellyfinDisable", '');
            try {
                MediaRestart::pmssGate($home, 'jellyfin');
                check(false, 'a plain-file marker should have refused too, differently');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'is turned off on this account') !== false
                    && strpos($e->getMessage(), 'is not a plain file') === false, 'plain-file marker message: ' . $e->getMessage());
            }
        } finally {
            testRmTree($home);
        }
    },
    'media restart: run() with a Docker plan that never answers returns answered:false with a "not down" state, never throwing' => function () {
        $plan = array('how' => 'docker', 'docker' => new MediaEdgeDockerRestartStub(), 'container' => array('id' => 'c1', 'name' => 'c1'));
        $out = MediaRestart::run($plan, function () {
            return false;
        }, 1);
        check($out['answered'] === false && strpos($out['state'], 'not answering after 1 s') !== false
            && strpos($out['state'], 'not down') !== false, 'never-answers docker restart: ' . json_encode($out));
    },
    'media end-stream: finish() de-duplicates identical unit hints across multiple failed sessions' => function () {
        $done = array(
            array('id' => 's1', 'user' => 'u1', 'title' => 'T1', 'result' => 'failed: refused', 'hint' => 'end it from the dashboard'),
            array('id' => 's2', 'user' => 'u2', 'title' => 'T2', 'result' => 'failed: refused', 'hint' => 'end it from the dashboard'),
        );
        try {
            MediaEndStream::finish($done);
            check(false, 'finish() should have thrown: not every stream ended');
        } catch (CliError $e) {
            check($e->hint === 'end it from the dashboard; `sessions` shows what still plays; tell the user exactly this',
                'the hint was not de-duplicated: ' . $e->hint);
        }
    },
    'media end-stream: each() marks every session after the first MediaAuthRejected as "not sent", without calling stop() again' => function () {
        $sessions = array(
            array('id' => 's1', 'user' => 'u1', 'title' => 'T1'),
            array('id' => 's2', 'user' => 'u2', 'title' => 'T2'),
            array('id' => 's3', 'user' => 'u3', 'title' => 'T3'),
        );
        $calls = 0;
        $out = MediaEndStream::each($sessions, function ($s) use (&$calls) {
            $calls++;
            throw new MediaAuthRejected('refused', 'run login');
        });
        check($calls === 1, "stop() was called $calls times, expected exactly 1 (stop at the first credential failure)");
        check(strpos($out[0]['result'], 'failed:') === 0, 'session 1 result: ' . $out[0]['result']);
        check($out[1]['result'] === 'not sent: the credential was refused' && $out[2]['result'] === 'not sent: the credential was refused',
            'later sessions were not marked not-sent: ' . json_encode($out));
    },
    'media servers: need() clamps a negative --limit and --offset to 0, the same as an explicit 0' => function () {
        // need([]) is NOT the right comparison here: an omitted --limit defaults to Cli::LIMIT (20), not 0.
        // The clamp is proven against an EXPLICIT 0, which --limit -5 must behave identically to.
        check(MediaServers::need(array('limit' => -5, 'offset' => -3)) === MediaServers::need(array('limit' => 0, 'offset' => 0)),
            'a negative limit/offset was not clamped the same as an explicit 0');
        check(MediaServers::need(array('limit' => -5, 'offset' => -3)) === MediaServers::RECENT_CAP,
            'need() with negatives should fall back to RECENT_CAP: ' . MediaServers::need(array('limit' => -5, 'offset' => -3)));
    },
    'media servers: pick() on an ambiguous case-insensitive name match produces the literal "matches 2 librarys" text' => function () {
        $all = array(array('id' => '1', 'name' => 'Movies'), array('id' => '2', 'name' => 'movies'));
        try {
            MediaServers::pick($all, array('movies'), 'library', 'hint text');
            check(false, 'an ambiguous case-insensitive match should have refused');
        } catch (CliError $e) {
            check($e->getMessage() === 'nothing changed: movies: matches 2 librarys', 'message: ' . $e->getMessage());
        }
    },
    'plex local: dirs() uses only the Docker config path and ignores env/home entirely once Docker is found' => function () {
        $dirs = PlexLocal::dirs('/x', '/home/u', '/custom');
        check($dirs === array('/x/Library/Application Support/Plex Media Server'), 'docker-found dirs(): ' . json_encode($dirs));
    },
    'plex server: scan() reports "not a section id" for a non-numeric library id without sending any HTTP request' => function () {
        $http = new MediaHttp('Plex', 'http://127.0.0.1:1', PlexClient::ALLOW, mediaEdgeUid());
        $plex = new PlexClient($http, 'edge-client-id');
        $server = new PlexServer($plex, array(), array());
        $out = $server->scan(array(array('id' => 'all', 'name' => 'All', 'scanning' => false)));
        check($out === array(array('id' => 'all', 'name' => 'All', 'result' => 'not a section id')),
            'a non-numeric library id should never reach plex->post(): ' . json_encode($out));
    },
    'jellyfin locate: bases() for BaseUrl exactly "/" returns only the bare URL, no duplicated/empty-prefixed entry' => function () {
        $home = testTempDir('pk-media-edge-jellyfin');
        try {
            file_put_contents("$home/.media-stack-port-jellyfin", '8096');
            mkdir("$home/.config/jellyfin/config", 0700, true);
            file_put_contents("$home/.config/jellyfin/config/network.xml", '<Config><BaseUrl>/</BaseUrl></Config>');
            $docker = new MediaDocker(mediaEdgeUid(), '');
            $f = JellyfinLocate::find($home, 'pmss', mediaEdgeUid(), '', $docker, array('', ''));
            check($f['bases'] === array('http://127.0.0.1:8096'), 'BaseUrl "/" should give exactly one base: ' . json_encode($f['bases']));
        } finally {
            testRmTree($home);
        }
    },
    'media servers: scrub() never redacts a secret shorter than 4 characters' => function () {
        $records = array('note' => 'the stored value abc is too short to redact');
        $out = MediaServers::scrub($records, array('abc'));
        check($out === $records, 'a 3-character secret was redacted, matching MediaLocal\'s own 4-char floor: ' . json_encode($out));
    },
    'media local: redact() drops the oldest lines first until the JSON-encoded output fits the byte budget' => function () {
        $lines = array();
        for ($i = 1; $i <= 20; $i++) {
            $lines[] = "log line number $i, padded to a reasonable width for the byte budget below";
        }
        list($out, $dropped) = MediaLocal::redact($lines, array(), true, 500);
        check($dropped > 0 && $dropped < 20, "dropped=$dropped, expected some but not all of 20 lines");
        check(end($out) === end($lines), 'the newest line should survive: ' . json_encode($out));
        check(!in_array($lines[0], $out, true), 'the oldest line should have been dropped first: ' . json_encode($out));
    },
);
