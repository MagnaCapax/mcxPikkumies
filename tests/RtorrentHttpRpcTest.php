<?php
/**
 * tools/torrent-clients/rtorrent/HttpRpc.php (ADR-0107): rTorrent's XML-RPC over HTTPS with basic auth through an injected
 * requester (tests/fixtures/rtorrent/httprpc.php), never the network. The oracle is the logged request and the CliError
 * text: the body is the codec's output, the header carries user:secret, every refusal comes before any request, and no
 * message holds the secret, the header or a URL's userinfo.
 */
require_once __DIR__ . '/fixtures/rtorrent/httprpc.php';

return [
    'rtorrent http rpc: the body is the XML-RPC codec\'s output, POSTed to the URL with a basic-auth header from the user and the stored secret' => function () {
        $fx = hrFixture();
        try {
            $rpc = new RtorrentHttpRpc(HR_URL, HR_HOST, 'rutorrent', 'secret:feral1-rpc', 15, hrRequester($fx));
            check($rpc->address === HR_URL, 'address: ' . $rpc->address);
            check($rpc->call('system.client_version') === '0.9.8', 'decoded answer');
            check($rpc->call('d.creation_date', [str_repeat('A', 40)]) === 1690000000, 'a call with a target');
            $log = hrLog($fx);
            check(count($log) === 2 && $log[0]['method'] === 'POST' && $log[0]['url'] === HR_URL, 'request: ' . json_encode($log));
            check($log[0]['body'] === RtorrentXmlRpc::request('system.client_version', []) && $log[1]['body'] === RtorrentXmlRpc::request('d.creation_date', [str_repeat('A', 40)]),
                'the body must be the codec output: ' . $log[1]['body']);
            check(in_array('Authorization: Basic ' . base64_encode('rutorrent:' . HR_SECRET), $log[0]['headers'], true) && in_array('Content-Type: text/xml', $log[0]['headers'], true),
                'headers: ' . json_encode($log[0]['headers']));
            check($log[0]['opt']['max'] === RtorrentScgi::MAX && $log[0]['opt']['timeout'] === 15, 'reply cap and timeout: ' . json_encode($log[0]['opt']));
            $root = dirname(__DIR__) . '/pikkumies';
            foreach (["$root/lib/Http.php", "$root/tools/torrent-clients/rtorrent/HttpRpc.php"] as $f) {
                check(stripos((string) file_get_contents($f), 'SSL_VERIFY') === false, "$f turns TLS verification off");
            }
        } finally {
            hrDone($fx);
        }
    },
    'rtorrent http rpc: http, user:secret@, another port, a query, another host, an unpinned, differently pinned or inline secret and a denied host are refused before any request' => function () {
        $fx = hrFixture(['feral1-rpc' => [HR_SECRET, HR_HOST], 'loose' => [HR_SECRET, null], 'elsewhere' => [HR_SECRET, 'other.example.org']]);
        try {
            $req = hrRequester($fx);
            $cases = [
                ['http://kepler.feralhosting.com/xyzjohnny/rtorrent/rpc', HR_HOST, 'secret:feral1-rpc', '/not https/'],
                ['https://rutorrent:' . HR_SECRET . '@kepler.feralhosting.com/xyzjohnny/rtorrent/rpc', HR_HOST, 'secret:feral1-rpc', '/user or a login/'],
                ['https://kepler.feralhosting.com:8443/xyzjohnny/rtorrent/rpc', HR_HOST, 'secret:feral1-rpc', '/port other than 443/'],
                ['https://kepler.feralhosting.com/rpc?x=1', HR_HOST, 'secret:feral1-rpc', '/plain https/'],
                ['https://kepler.feralhosting.com/xyzjohnny/%2e%2e/other/rpc', HR_HOST, 'secret:feral1-rpc', '/plain https/'],
                ['https://kepler.feralhosting.com/xyzjohnny/../other/rpc', HR_HOST, 'secret:feral1-rpc', '/empty, "\." or "\.\." path part/'],
                ['https://kepler.feralhosting.com//other/rpc', HR_HOST, 'secret:feral1-rpc', '/empty, "\." or "\.\." path part/'],
                ['https://kepler.feralhosting.com\\@evil.example.org/rpc', HR_HOST, 'secret:feral1-rpc', '/user or a login/'],
                ['https://other.example.org/rpc', HR_HOST, 'secret:feral1-rpc', '/not the service\'s own host/'],
                ['https://other.example.org/rpc', 'other.example.org', 'secret:feral1-rpc', '/pinned to kepler\.feralhosting\.com, but this service\'s record now names other\.example\.org\/rpc/'],
                [HR_URL, HR_HOST, 'secret:loose', '/not pinned to a host/'],
                [HR_URL, HR_HOST, 'secret:elsewhere', '/pinned to other\.example\.org, but/'],
                [HR_URL, HR_HOST, HR_SECRET, '/clients\.rtorrent\.auth must be secret:<handle>/'],
            ];
            foreach ($cases as $i => $c) {
                check(hrRefuses(function () use ($c, $req) {
                    new RtorrentHttpRpc($c[0], $c[1], 'rutorrent', $c[2], 15, $req);
                }, $c[3]), "case $i was not refused as expected: " . $c[3]);
            }
            $folded = new RtorrentHttpRpc('HTTPS://KEPLER.feralhosting.com./xyzjohnny/rtorrent/rpc', 'Kepler.FeralHosting.com', 'rutorrent', 'secret:feral1-rpc', 15, $req);
            check($folded->address !== '', 'upper case and a trailing dot name the same host and must be accepted');
            file_put_contents($fx['dir'] . '/config.php', "<?php\nreturn ['ssh' => ['deny' => ['*.feralhosting.com']]];\n");
            check(hrRefuses(function () use ($req) {
                new RtorrentHttpRpc(HR_URL, HR_HOST, 'rutorrent', 'secret:feral1-rpc', 15, $req);
            }, '/deny list/'), 'a host on ssh.deny must be refused');
            check(hrLog($fx) === [], 'a refused address reached the requester: ' . json_encode(hrLog($fx)));
        } finally {
            hrDone($fx);
        }
    },
    'rtorrent http rpc: a path-prefix pin allows only URLs under it on a / boundary; a mismatch hint never offers a re-pin command or the record\'s address' => function () {
        $fx = hrFixture(['feral1-rpc' => [HR_SECRET, HR_HOST, '/xyzjohnny/'], 'loose' => [HR_SECRET, null]]);
        try {
            $req = hrRequester($fx);
            check((new RtorrentHttpRpc(HR_URL, HR_HOST, 'rutorrent', 'secret:feral1-rpc', 15, $req))->address === HR_URL, 'the pinned path must be allowed');
            foreach (['https://kepler.feralhosting.com/otheruser/rtorrent/rpc', 'https://kepler.feralhosting.com/xyzjohnnyx/rtorrent/rpc', 'https://kepler.feralhosting.com/'] as $url) {
                $e = hrError(function () use ($url, $req) {
                    new RtorrentHttpRpc($url, HR_HOST, 'rutorrent', 'secret:feral1-rpc', 15, $req);
                });
                check($e !== null && strpos($e->getMessage(), 'secret feral1-rpc is pinned to kepler.feralhosting.com/xyzjohnny/, but') === 0,
                    "$url: " . ($e !== null ? $e->getMessage() : 'allowed'));
                check(strpos($e->hint, '--host') === false && strpos($e->hint, 'secrets rotate') === false && strpos($e->hint, 'secrets set') === false
                    && strpos($e->hint, 'kepler.feralhosting.com') === false && strpos($e->hint, 'services show') !== false, 'the mismatch hint offers a re-pin: ' . $e->hint);
            }
            $e = hrError(function () use ($req) {
                new RtorrentHttpRpc('https://other.example.org/xyzjohnny/rtorrent/rpc', 'other.example.org', 'rutorrent', 'secret:feral1-rpc', 15, $req);
            });
            check($e !== null && strpos($e->hint, '--host') === false && strpos($e->hint, 'other.example.org') === false, 'an edited host and URL got a re-pin hint: ' . ($e !== null ? $e->hint : 'allowed'));
            $e = hrError(function () use ($req) {
                new RtorrentHttpRpc(HR_URL, HR_HOST, 'rutorrent', 'secret:loose', 15, $req);
            });
            check($e !== null && strpos($e->getMessage(), 'not pinned') !== false && strpos($e->hint, HR_HOST) === false && strpos($e->hint, '<host>/<path>/') !== false,
                'the unpinned hint must not recommend the record\'s host: ' . ($e !== null ? $e->hint : 'allowed'));
            check(hrLog($fx) === [], 'a refused address reached the requester');
        } finally {
            hrDone($fx);
        }
    },
    'rtorrent http rpc: a timeout, an unreachable host, HTTP 401 and 500, a non-XML or DOCTYPE reply and a fault become CliErrors that never hold the secret' => function () {
        $fx = hrFixture();
        try {
            $pair = base64_encode('rutorrent:' . HR_SECRET);
            $cases = [
                [['code' => 0, 'error' => 'Operation timed out after 15001 milliseconds with 0 bytes received'], '/did not answer system\.pid within 15 s/'],
                [['code' => 0, 'error' => 'Could not connect: ' . HR_SECRET . ' ' . $pair], '/cannot reach rtorrent at kepler\.feralhosting\.com: Could not connect: \[REDACTED\] \[REDACTED\]/'],
                [['code' => 401, 'body' => 'no'], '/refused the login for system\.pid \(HTTP 401\)/'],
                [['code' => 500, 'body' => 'oops'], '/answered system\.pid with HTTP 500/'],
                [['code' => 200, 'error' => 'response larger than 67108864 bytes'], '/was cut off/'],
                [['code' => 200, 'body' => '<html>login</html>'], '/rtorrent/'],
                [['code' => 200, 'body' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><methodResponse/>'], '/not an XML-RPC reply/'],
                [['code' => 200, 'body' => hrFault('bad auth for rutorrent:' . HR_SECRET)], '/refused system\.pid: bad auth for rutorrent:\[REDACTED\]/'],
                [['code' => 200, 'body' => "\0" . implode("\0", str_split('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><methodResponse/>'))], '/answered system\.pid with something that is not an XML-RPC reply/'],
                [['code' => 200, 'body' => "\xEF\xBB\xBF" . hrReply('ok')], '/^$/'],
                [['code' => 200, 'body' => "\n  " . substr(hrReply('ok'), strlen('<?xml version="1.0"?>'))], '/^$/'],
                [['code' => 200, 'body' => hrFault(str_repeat('x', 1000))], '/^rtorrent refused system\.pid: x{300} \[cut\]$/'],
            ];
            foreach ($cases as $i => $c) {
                $rpc = new RtorrentHttpRpc(HR_URL, HR_HOST, 'rutorrent', 'secret:feral1-rpc', 15, hrRequester($fx, null, $c[0]));
                if ($c[1] === '/^$/') { // a UTF-8 BOM before <?xml, or whitespace before <methodResponse, is still a reply
                    check($rpc->call('system.pid') === 'ok', "case $i: a BOM-led reply was refused");
                    continue;
                }
                check(hrRefuses(function () use ($rpc) {
                    $rpc->call('system.pid');
                }, $c[1]), "case $i: expected " . $c[1]);
            }
        } finally {
            hrDone($fx);
        }
    },
    'rtorrent http rpc: a locked store refuses before any request; without auth no Authorization header is sent' => function () {
        $fx = hrFixture();
        try {
            $req = hrRequester($fx);
            SecretsCache::forget($fx['vault']);
            check(hrRefuses(function () use ($req) {
                new RtorrentHttpRpc(HR_URL, HR_HOST, 'rutorrent', 'secret:feral1-rpc', 15, $req);
            }, '/^locked: /'), 'a locked store must refuse at once');
            check(hrLog($fx) === [], 'a locked store still sent a request');
            (new RtorrentHttpRpc(HR_URL, HR_HOST, '', null, 15, $req))->call('system.pid');
            $log = hrLog($fx);
            check(count($log) === 1 && !preg_grep('/^Authorization:/i', $log[0]['headers']), 'headers without auth: ' . json_encode($log));
        } finally {
            hrDone($fx);
        }
    },
    'rtorrent http rpc: RtorrentClient with the injected transport builds the local records from it; without one it is unchanged' => function () {
        $fx = hrFixture();
        try {
            $c = new RtorrentClient('remote', '', 0, new RtorrentHttpRpc(HR_URL, HR_HOST, 'rutorrent', 'secret:feral1-rpc', 15, hrRequester($fx)));
            $t = $c->torrents();
            check(count($t) === 2 && $t[0]['id'] === str_repeat('a', 40) && $t[0]['state'] === 'seeding' && $t[1]['state'] === 'paused' && $t[0]['ratio'] === 1.5,
                'records: ' . json_encode($t));
            $s = $c->stats();
            check($s['version'] === '0.9.8' && $s['torrents'] === 2 && $s['endpoint'] === HR_URL && !array_key_exists('uptime', $s) && $s['up_total'] === 4500,
                'stats: ' . json_encode($s));
            check($c->address() === HR_URL, 'address');
            check((new RtorrentClient('pmss', '/srv/fixtureuserx', 1000))->address() === '', 'a local client must still start with no address');
        } finally {
            hrDone($fx);
        }
    },
];
