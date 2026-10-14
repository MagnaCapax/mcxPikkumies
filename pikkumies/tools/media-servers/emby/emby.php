<?php
/**
 * emby: the user's own Emby server through its HTTP API (tools/media-servers/README.md, this unit's
 * README.md). Emby is a user install (PMSS installs none; its docs give a rootless-Docker recipe), so
 * it is found by --url or as a container on the user's own rootless Docker. The credential is an API
 * key the user stores with `login` (no Emby password file exists), sent as X-Emby-Token only.
 */
$mediaUnitLib = dirname(__DIR__) . '/lib';
require_once "$mediaUnitLib/MediaServers.php";
foreach (['Mediabrowser', 'MediabrowserRecords', 'MediabrowserInfo', 'MediabrowserServer'] as $mediaClass) {
    require_once "$mediaUnitLib/$mediaClass.php";
}

$usage = <<<'TXT'
usage: php emby.php <verb> [arguments] [--url http://127.0.0.1:<port>] [--human]
  stats             libraries           sessions [--addresses]       recent [--limit N]
  scan <library>... | scan --all        end-stream <session-id>... [--reason <text>]
  logs [--lines N] [--addresses]        restart
  login             (the user types an API key at a terminal; stored for this tool)
Libraries: an id or name from `libraries`. Session ids: from `sessions`. Output: JSON Lines. See USAGE.md.
TXT;

// Test seams: a test may set $mediaSeams before including this file. Nothing here is read from the environment.
$embySeams = (isset($mediaSeams) ? $mediaSeams : []) + ['secrets' => Config::localDir() . '/secrets', 'pmss_dir' => '/etc/seedbox/config',
    'docker_sock' => null, 'tables' => null, 'timeout' => 15, 'wait' => 90];
$embyUid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
$embySecret = new MediaSecret($embySeams['secrets'], 'emby');

/** [Mediabrowser, found]. Emby's API lives under /emby (a live 4.10.1.0 answers there; README.md). */
$embyConnect = function (array $opt) use ($embySeams, $embyUid, $embySecret) {
    $family = Host::family($embySeams['pmss_dir']);
    $url = isset($opt['url']) && is_string($opt['url']) ? $opt['url'] : '';
    $docker = new MediaDocker($embyUid, $embySeams['docker_sock']);
    // emby/embyserver, lscr.io/linuxserver/emby; both serve on 8096 inside (PMSS docs/linuxserver.io.md:366-381: -p 8097:8096).
    $c = $url === '' ? $docker->find('#(^|/)(emby|embyserver)(:|@|$)#i', 8096) : null;
    if ($url === '' && $c === null) {
        throw new CliError('Emby was not found for this user (no --url, no Emby container on the user\'s own rootless Docker)',
            'give its address: --url http://127.0.0.1:<port>');
    }
    $base = $url !== '' ? rtrim($url, '/') : 'http://127.0.0.1:' . $c['host_port'];
    if (!preg_match('#/emby$#i', $base)) {
        $base .= '/emby';
    }
    $port = MediaHttp::local($base)['port'];
    $listen = $c !== null ? $c['host_ip'] . ':' . $c['host_port'] : (MediaLocal::listen($port, $embySeams['tables']) ?: "127.0.0.1:$port");
    $restart = $c !== null ? ['how' => 'docker', 'docker' => $docker, 'container' => $c]
        : MediaRestart::elsewhere($family, 'emby', '--url');
    $found = ['how' => $c !== null ? 'docker' : 'url', 'url' => $base, 'port' => $port, 'listen' => $listen, 'log_dir' => '',
        'pmss_status' => '', 'restart' => $restart];
    $http = new MediaHttp('Emby', $base, Mediabrowser::ALLOW, $embyUid, $embySeams['timeout'], $embySeams['tables']);
    return [new Mediabrowser('emby', $http, $embySecret->deviceId()), $found];
};

$embyLoginHint = 'the user creates an API key in the Emby dashboard (under Advanced) and runs `php ' . __FILE__ . ' login` in a terminal';
MediaServers::run($argv, function (array $opt, $auth = true) use ($embyConnect, $embySecret, $embySeams, $embyLoginHint) {
    list($mb, $found) = $embyConnect($opt);
    if (!$auth) { // restart: public status and the user's own Docker only
        return new MediabrowserServer($mb, $found + ['login_from' => ''], [], $embySeams['wait']);
    }
    $s = $embySecret->load();
    if (empty($s['key']) || !is_string($s['key'])) {
        throw new CliError('no Emby API key stored for this tool', $embyLoginHint);
    }
    if (!empty($s['key_rejected'])) {
        throw new CliError('Emby refused the stored API key; not trying it again', $embyLoginHint);
    }
    $mb->useToken($s['key'], function () use ($embySecret, $embyLoginHint) {
        $embySecret->update(['key_rejected' => true]);
        return new CliError('Emby refused the stored API key; it is marked and will not be tried again', $embyLoginHint);
    });
    $found['login_from'] = 'API key stored with `login` in ' . $embySecret->path();
    return new MediabrowserServer($mb, $found, [$s['key']], $embySeams['wait']);
}, function ($args, $opt) use ($embyConnect, $embySecret) {
    $prompt = 'Emby API key (from the dashboard, under Advanced; not shown): ';
    return $embySecret->login($prompt, __FILE__, function ($key) use ($embyConnect, $opt) {
        list($mb) = $embyConnect($opt);
        $mb->useToken($key, function () {
        });
        $mb->get('/System/Info');
    });
}, $usage);
