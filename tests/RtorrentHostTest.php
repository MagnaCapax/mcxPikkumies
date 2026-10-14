<?php
/**
 * tools/torrent-clients/rtorrent: stats, traffic, restart, locating rTorrent and the XML-RPC codec, against a fake rTorrent
 * (tests/fixtures/rtorrent/server.php, helpers in tests/fixtures/rtorrent/helpers.php). Nothing real is contacted.
 */
require_once __DIR__ . '/fixtures/rtorrent/helpers.php';

return [
    'rtorrent: stats and traffic; a missing method (fault) is tolerated where optional' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($code, $out, $l) = rtorrentRun($h, ['stats']);
            check($code === 0 && $l[0]['version'] === '0.9.8' && $l[0]['pid'] === 4242 && $l[0]['torrents'] === 6 && $l[0]['states']['seeding'] === 2, 'stats: ' . $out);
            check(!isset($l[0]['uptime']) && $l[0]['up_limit'] === 512000 && $l[0]['endpoint'] === '~/.rtorrent.socket', 'stats fields: ' . $out);
            list($code, $out, $l) = rtorrentRun($h, ['traffic']);
            check($code === 0 && $l[0]['down_rate'] === 1000 && $l[0]['up_total'] === 20000 && $l[0]['down_limit'] === 0, 'traffic: ' . $out);
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'rtorrent: restart refuses on ~/.rtorrentDisable and on other providers; on PMSS it uses the marker and the PMSS script' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            touch("$h/.rtorrentDisable");
            list($code, $out) = rtorrentRun($h, ['restart'], "$h/run-pmss.php");
            check($code === 1 && strpos($out, 'rtorrentDisable') !== false && !file_exists("$h/www/.rtorrentRestart"), 'restart overrode the disable marker: ' . $out);
            unlink("$h/.rtorrentDisable");
            list($code, $out) = rtorrentRun($h, ['restart'], "$h/run-ultra.php");
            check($code === 1 && strpos($out, 'app-rtorrent restart') !== false, 'ultra hint: ' . $out);
            list($code, $out) = rtorrentRun($h, ['restart'], "$h/run-pmss.php");
            check($code === 1 && strpos($out, 'missing') !== false, 'restart without the PMSS script: ' . $out);
            // A stand-in for PMSS's ~/.rtorrentRestart.php: it only records that it ran from $HOME and consumes the marker.
            file_put_contents("$h/.rtorrentRestart.php", '<?php if (file_exists("www/.rtorrentRestart")) { unlink("www/.rtorrentRestart"); file_put_contents("ran", getcwd()); }');
            list($code, $out, $l) = rtorrentRun($h, ['restart'], "$h/run-pmss.php");
            check($code === 0 && $l[0]['answered'] === true && $l[0]['version'] === '0.9.8' && trim((string) @file_get_contents("$h/ran")) === $h, 'restart: ' . $out);
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'rtorrent: locate errors name the cause (not running, not installed/enabled, another account, busy) and TCP works' => function () {
        $h = rtorrentHome(null);
        try {
            list($code, $out) = rtorrentRun($h, ['list'], "$h/run-pmss.php");
            check($code === 1 && preg_match('/not running|not installed/', $out), 'no socket: ' . $out);
            list($code, $out) = rtorrentRun($h, ['list'], "$h/run-generic.php");
            check($code === 1 && preg_match('/not installed|not enabled/', $out), 'no rc: ' . $out);
            $probe = stream_socket_server('tcp://127.0.0.1:0');
            $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
            fclose($probe);
            file_put_contents("$h/.rtorrent.rc", "scgi_port = localhost:$port\n");
            list($code, $out) = rtorrentRun($h, ['list'], "$h/run-generic.php");
            check($code === 1 && strpos($out, 'not running') !== false, 'free port: ' . $out);
            $p = rtorrentServe($h, "tcp://127.0.0.1:$port");
            list($code, $out, $l) = rtorrentRun($h, ['list'], "$h/run-generic.php");
            proc_terminate($p);
            proc_close($p);
            clearstatcache();
            check($code === 0 && count($l) === 7, 'list over TCP: ' . $out);
            file_put_contents("$h/.rtorrent.rc", "network.scgi.open_local = ~/.rtorrent.socket\n");
            $p = rtorrentServe($h, null, 'hang');
            $t = microtime(true);
            list($code, $out) = rtorrentRun($h, ['traffic'], "$h/run-generic.php");
            proc_terminate($p);
            proc_close($p);
            clearstatcache();
            check($code === 1 && strpos($out, 'busy') !== false && strpos($out, 'not necessarily down') !== false && microtime(true) - $t < 15, 'timeout: ' . $out);
        } finally {
            rtorrentDone($h, null);
        }
    },
    'rtorrent: a TCP port of another account is refused (a fixed /proc/net/tcp, so the test always runs)' => function () {
        require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/rtorrent/Locate.php';
        $h = rtorrentHome("network.scgi.open_port = \"127.0.0.1:40123\"\n");
        try {
            $uid = posix_geteuid();
            $row = function ($owner) {
                return "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n"
                    . sprintf("   0: 0100007F:9CBB 00000000:0000 0A 00000000:00000000 00:00000000 00000000 %5d        0 4242 1\n", $owner);
            };
            check(RtorrentLocate::address('generic', $h, $uid, [$row($uid), '']) === 'tcp://127.0.0.1:40123', 'own port refused');
            try {
                RtorrentLocate::address('generic', $h, $uid, [$row($uid + 1), '']);
                check(false, 'a port of another account was accepted');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'another account') !== false, 'foreign port: ' . $e->getMessage());
            }
        } finally {
            rtorrentDone($h, null);
        }
    },
    'rtorrent: XML-RPC codec round-trips the types rtorrent uses and turns a fault into an error' => function () {
        require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/rtorrent/XmlRpc.php';
        $req = RtorrentXmlRpc::request('load.raw_start', ['', new RtorrentBase64("d4:infoe\x00"), 5000000000, true, 1.5, ['a' => [1, 'x<&']]]);
        check(strpos($req, '<base64>' . base64_encode("d4:infoe\x00") . '</base64>') !== false && strpos($req, '<i8>5000000000</i8>') !== false
            && strpos($req, '<boolean>1</boolean>') !== false && strpos($req, '<struct><member><name>a</name>') !== false && strpos($req, 'x&lt;&amp;') !== false, 'encode: ' . $req);
        $v = RtorrentXmlRpc::response('<?xml version="1.0"?><methodResponse><params><param><value><array><data><value><i8>7</i8></value><value>plain</value>'
            . '<value><boolean>0</boolean></value><value><base64>aGk=</base64></value><value><struct><member><name>k</name><value><double>2.5</double></value></member></struct></value></data></array></value></param></params></methodResponse>', 'x');
        check($v === [7, 'plain', false, 'hi', ['k' => 2.5]], 'decode: ' . json_encode($v));
        try {
            RtorrentXmlRpc::response('<?xml version="1.0"?><methodResponse><fault><value><struct><member><name>faultCode</name><value><i4>-501</i4></value></member>'
                . '<member><name>faultString</name><value><string>Unsupported target type found.</string></value></member></struct></value></fault></methodResponse>', 'd.name');
            check(false, 'a fault was not an error');
        } catch (CliError $e) {
            check(strpos($e->getMessage(), 'Unsupported target type found.') !== false, 'fault text: ' . $e->getMessage());
        }
    },
];
