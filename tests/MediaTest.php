<?php
/** The media-server libs' safety rules, in process: allowlists, loopback only, the owner check, redaction, the guard. */
require_once __DIR__ . '/fixtures/media/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/lib/MediaServers.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/lib/Mediabrowser.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/plex/PlexClient.php';

function mediaRefused(callable $fn)
{
    try {
        $fn();
    } catch (CliError $e) {
        return $e->getMessage();
    }
    return '';
}

/** An empty fake Jellyfin with no base path. */
function mediaEmptyJellyfin()
{
    return mediaFixture('mediabrowser', ['kind' => 'jellyfin', 'base' => '', 'version' => '12.2.0', 'tokens' => ['k'],
        'libraries' => [], 'sessions' => []]);
}

return [
    'media libs: the allowlists refuse DELETE, /Auth/Keys, restart, overwrite or credential query names; nothing is sent' => function () {
        $fx = mediaEmptyJellyfin();
        try {
            $uid = posix_geteuid();
            $mb = new MediaHttp('Jellyfin', 'http://127.0.0.1:' . $fx['port'], Mediabrowser::ALLOW, $uid, 5);
            foreach ([['DELETE', '/Items/abc', []], ['DELETE', '/Items', ['ids' => 'a,b']], ['POST', '/Auth/Keys', ['app' => 'x']],
                ['GET', '/Auth/Keys', []], ['POST', '/System/Restart', []], ['POST', '/System/Shutdown', []], ['POST', '/Startup/User', []],
                ['POST', '/System/Configuration/network', []], ['POST', '/Items/abc/Refresh', ['replaceAllMetadata' => 'true']],
                ['POST', '/Items/abc/Refresh', ['ReplaceAllImages' => 'true']], ['GET', '/Sessions', ['api_key' => 'k']],
                ['GET', '/Sessions', ['ApiKey' => 'k']], ['GET', '/Items/../Auth/Keys', []]] as $c) {
                check(strpos(mediaRefused(function () use ($mb, $c) {
                    $mb->send($c[0], $c[1], $c[2]);
                }), 'refused') === 0, "not refused: {$c[0]} {$c[1]}");
            }
            $plex = new MediaHttp('Plex', 'http://127.0.0.1:' . $fx['port'], PlexClient::ALLOW, $uid, 5);
            foreach ([['DELETE', '/library/metadata/12', []], ['PUT', '/updater/apply', []],
                ['PUT', '/:/prefs', ['allowedNetworks' => '0.0.0.0/0']],
                ['POST', '/library/sections/1/refresh', ['force' => '1']], ['GET', '/status/sessions', ['X-Plex-Token' => 't']],
                ['DELETE', '/library/sections/1/refresh', []]] as $c) {
                check(strpos(mediaRefused(function () use ($plex, $c) {
                    $plex->send($c[0], $c[1], $c[2]);
                }), 'refused') === 0, "not refused: {$c[0]} {$c[1]}");
            }
            check(mediaState($fx)['calls'] === [], 'a refused request reached the server: ' . implode(', ', mediaCalls($fx)));
        } finally {
            mediaDone($fx);
        }
    },
    'media libs: only this machine\'s loopback, checked without a DNS lookup (never plex.tv)' => function () {
        foreach (['https://plex.tv', 'http://plex.tv:32400', 'http://10.0.0.1:8096', 'http://example.org:80', 'http://127.0.0.1',
            'http://u:p@127.0.0.1:1/', 'ftp://127.0.0.1:21', 'http://127.0.0.1:1/?api_key=x'] as $u) {
            check(mediaRefused(function () use ($u) {
                MediaHttp::local($u);
            }) !== '', "accepted $u");
        }
        $ok = MediaHttp::local('http://localhost:8096/jellyfin/');
        check($ok['origin'] === 'http://127.0.0.1:8096' && $ok['path'] === '/jellyfin'
            && MediaHttp::local('http://[::1]:9/')['origin'] === 'http://[::1]:9', 'loopback forms: ' . json_encode($ok));
    },
    'media libs: the --url base path is plain segments only (no . or .. segment, no %-escape, no fragment)' => function () {
        foreach (['/x/..', '/../Auth', '/a/./b', '/%2e%2e/x', '/jelly%66in', '/a#f', '/a b', '/a;b'] as $bad) {
            $u = "http://127.0.0.1:8096$bad";
            $why = mediaRefused(function () use ($u) {
                MediaHttp::local($u);
            });
            check(strpos($why, 'base path') !== false || strpos($why, 'not an address') !== false, "accepted $u");
        }
        $good = ['http://127.0.0.1:8096' => '', 'http://127.0.0.1:8096/' => '',
            'http://127.0.0.1:8096/public-u_1/jelly.fin~x/' => '/public-u_1/jelly.fin~x'];
        foreach ($good as $u => $p) {
            check(MediaHttp::local($u)['path'] === $p, "refused or changed $u");
        }
    },
    'media libs: a port another account owns is refused before anything is sent (Host::ownsListener seam)' => function () {
        $fx = mediaEmptyJellyfin();
        try {
            $uid = posix_geteuid();
            $url = 'http://127.0.0.1:' . $fx['port'];
            $other = new MediaHttp('Jellyfin', $url, Mediabrowser::ALLOW, $uid, 5, mediaNetTable($fx['port'], $uid + 1));
            check(strpos(mediaRefused(function () use ($other) {
                $other->send('GET', '/System/Info/Public');
            }), 'belongs to another account') !== false, 'a foreign port was used');
            $none = new MediaHttp('Jellyfin', $url, Mediabrowser::ALLOW, $uid, 5, mediaNetTable($fx['port'] + 1, $uid));
            check(strpos(mediaRefused(function () use ($none) {
                $none->send('GET', '/System/Info/Public');
            }), 'not running') !== false, 'an unowned, unlistened port was used');
            check(mediaState($fx)['calls'] === [], 'the server was contacted: ' . implode(', ', mediaCalls($fx)));
            $mine = new MediaHttp('Jellyfin', $url, Mediabrowser::ALLOW, $uid, 5, mediaNetTable($fx['port'], $uid));
            check($mine->send('GET', '/System/Info/Public')['code'] === 200
                && MediaLocal::listen($fx['port'], mediaNetTable($fx['port'], $uid)) === '127.0.0.1:' . $fx['port'], 'own port refused');
        } finally {
            mediaDone($fx);
        }
    },
    'media libs: redaction removes tokens and addresses; scrub removes the literal credential; pick never acts on all' => function () {
        $in = ['from 203.0.113.7 at 07:48:29', 'X-Plex-Token=abcd1234 and Token="zz99yy88"', 'v6 2001:db8::1', 'raw SECRETVALUE1'];
        list($l) = MediaLocal::redact($in, ['SECRETVALUE1'], false);
        check($l === ['from ADDRESS at 07:48:29', 'X-Plex-Token=REDACTED and Token="REDACTED"', 'v6 ADDRESS', 'raw REDACTED'],
            'redact: ' . json_encode($l));
        check(MediaLocal::redact(['from 203.0.113.7'], [], true)[0] === ['from 203.0.113.7'], '--addresses must keep them');
        $scrubbed = MediaServers::scrub([['a' => 'x SECRETVALUE1 y', 'n' => 3]], ['SECRETVALUE1']);
        check($scrubbed === [['a' => 'x REDACTED y', 'n' => 3]], 'scrub');
        $all = [['id' => '1', 'name' => 'Movies'], ['id' => '2', 'name' => 'TV']];
        check(MediaServers::pick($all, ['movies', '2'], 'library', '') === $all, 'pick by name and id');
        foreach ([[], ['all'], ['1', 'nope']] as $args) {
            check(mediaRefused(function () use ($all, $args) {
                MediaServers::pick($all, $args, 'library', '');
            }) !== '', 'pick accepted ' . json_encode($args));
        }
    },
    'media units: the USAGE examples get no answer from the guard hook (nothing in them deletes)' => function () {
        $hook = dirname(__DIR__) . '/pikkumies/hooks/pre_tool_call/guard-destructive.php';
        $n = 0;
        foreach (['jellyfin', 'emby', 'plex'] as $unit) {
            $usage = (string) file_get_contents(dirname(__DIR__) . "/pikkumies/tools/media-servers/$unit/USAGE.md");
            foreach (explode("\n", $usage) as $line) {
                if (preg_match('/^php \$[A-Z] (.*)$/', $line, $m)) {
                    $cmd = "php ~/pikkumies/tools/media-servers/$unit/$unit.php " . $m[1];
                    list(, $out) = runPhp($hook, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
                    check(trim($out) === '', "the guard answered for: $cmd => $out");
                    $n++;
                }
            }
        }
        check($n >= 27, "only $n examples found");
    },
];
