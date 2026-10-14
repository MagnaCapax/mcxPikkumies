<?php
/**
 * plex: the user's own Plex Media Server through its local HTTP API (tools/media-servers/README.md,
 * this unit's README.md). Plex is a user install (PMSS docs/linuxserver.io.md:341-347 and :385-399:
 * a rootless-Docker recipe, not a PMSS-supported server). Found by --url or as a container on the
 * user's own rootless Docker. Never contacts plex.tv or any other machine; the token stays in a header.
 */
$mediaUnitLib = dirname(__DIR__) . '/lib';
require_once "$mediaUnitLib/MediaServers.php";
require_once __DIR__ . '/PlexClient.php';
require_once __DIR__ . '/PlexRecords.php';
require_once __DIR__ . '/PlexServer.php';

$usage = <<<'TXT'
usage: php plex.php <verb> [arguments] [--url http://127.0.0.1:<port>] [--human]
  stats             libraries           sessions [--addresses]       recent [--limit N]
  scan <library>... | scan --all        end-stream <session-id>... [--reason <text>]
  logs [--lines N] [--addresses]        restart
  login             (the user types a Plex token at a terminal; stored for this tool)
Libraries: an id or name from `libraries`. Session ids: from `sessions`. Output: JSON Lines. See USAGE.md.
TXT;

// Test seams: a test may set $mediaSeams before including this file. Nothing here is read from the environment.
$plexSeams = (isset($mediaSeams) ? $mediaSeams : []) + ['secrets' => Config::localDir() . '/secrets', 'pmss_dir' => '/etc/seedbox/config',
    'docker_sock' => null, 'tables' => null, 'timeout' => 15, 'wait' => 90];
$plexUid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
$plexSecret = new MediaSecret($plexSeams['secrets'], 'plex');

/** [PlexClient, found]. The data directory is the host side of the container's /config mount. */
$plexConnect = function (array $opt) use ($plexSeams, $plexUid, $plexSecret) {
    $family = Host::family($plexSeams['pmss_dir']);
    $url = isset($opt['url']) && is_string($opt['url']) ? rtrim($opt['url'], '/') : '';
    $docker = new MediaDocker($plexUid, $plexSeams['docker_sock']);
    // plexinc/pms-docker and lscr.io/linuxserver/plex serve on 32400 inside (PMSS docs/linuxserver.io.md:385-399).
    $c = $url === '' ? $docker->find('#(^|/)(plex|pms-docker)(:|@|$)#i', 32400) : null;
    if ($url === '' && $c === null) {
        throw new CliError('Plex was not found for this user (no --url, no Plex container on the user\'s own rootless Docker)',
            'give its address: --url http://127.0.0.1:<port>');
    }
    $base = $url !== '' ? $url : 'http://127.0.0.1:' . $c['host_port'];
    $port = MediaHttp::local($base)['port'];
    $config = $c !== null ? $docker->mountSource($c['id'], '/config') : '';
    $listen = $c !== null ? $c['host_ip'] . ':' . $c['host_port'] : (MediaLocal::listen($port, $plexSeams['tables']) ?: "127.0.0.1:$port");
    $restart = $c !== null ? ['how' => 'docker', 'docker' => $docker, 'container' => $c]
        : MediaRestart::elsewhere($family, 'plex', '--url');
    $found = ['how' => $c !== null ? 'docker' : 'url', 'url' => $base, 'port' => $port, 'listen' => $listen,
        'data_dir' => $config !== '' ? "$config/Library/Application Support/Plex Media Server" : '', 'restart' => $restart];
    $http = new MediaHttp('Plex', $base, PlexClient::ALLOW, $plexUid, $plexSeams['timeout'], $plexSeams['tables']);
    return [new PlexClient($http, $plexSecret->deviceIdLater()), $found];
};

$plexLoginHint = 'the user runs `php ' . __FILE__ . ' login` in a terminal and types the server\'s X-Plex-Token';
MediaServers::run($argv, function (array $opt, $auth = true) use ($plexConnect, $plexSecret, $plexSeams, $plexLoginHint) {
    list($plex, $found) = $plexConnect($opt);
    if (!$auth) { // restart: /identity (open) and the user's own Docker only
        return new PlexServer($plex, $found + ['login_from' => ''], [], $plexSeams['wait']);
    }
    $s = $plexSecret->load();
    $prefs = $found['data_dir'] !== '' ? $found['data_dir'] . '/Preferences.xml' : '';
    if (!empty($s['key']) && is_string($s['key'])) {
        list($token, $from, $mark) = [$s['key'], 'token stored with `login` in ' . $plexSecret->path(), 'key_rejected'];
    } else {
        // The server's own account token, PlexOnlineToken in Preferences.xml ([UNVERIFIED]: README.md).
        $xml = $prefs !== '' && is_file($prefs) ? (string) @file_get_contents($prefs, false, null, 0, 1048576) : '';
        if (!preg_match('/\bPlexOnlineToken="([^"]+)"/', $xml, $m)) {
            throw new CliError('no Plex token: none stored with `login`' . ($prefs !== '' ? ', none readable in Preferences.xml' : ''),
                $plexLoginHint);
        }
        list($token, $from, $mark) = [html_entity_decode($m[1], ENT_QUOTES | ENT_XML1), 'PlexOnlineToken in ' . $prefs, 'prefs_rejected'];
    }
    if (!empty($s[$mark])) {
        throw new CliError("Plex refused this token before ($from); not trying it again", $plexLoginHint);
    }
    $plex->useToken($token, function () use ($plexSecret, $mark, $from, $plexLoginHint) {
        $plexSecret->update([$mark => true]);
        return new CliError("Plex refused the token ($from); it is marked and will not be tried again", $plexLoginHint);
    });
    $found['login_from'] = $from;
    return new PlexServer($plex, $found, [$token], $plexSeams['wait']);
}, function ($args, $opt) use ($plexConnect, $plexSecret) {
    return $plexSecret->login('Plex token (X-Plex-Token; not shown): ', __FILE__, function ($key) use ($plexConnect, $opt) {
        list($plex) = $plexConnect($opt);
        $plex->useToken($key, function () {
        });
        $plex->get('/library/sections/all');
    });
}, $usage);
