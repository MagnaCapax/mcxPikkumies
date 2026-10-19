<?php
/**
 * jellyfin: the user's own Jellyfin media server through its HTTP API (tools/media-servers/README.md,
 * this unit's README.md). The verbs and their rules are shared (../lib/MediaServers.php); this file
 * finds the server and its credential. Never deletes, never creates an API key, never calls
 * /System/Restart, never contacts a port another account owns or any other machine.
 */
$mediaUnitLib = dirname(__DIR__) . '/lib';
require_once "$mediaUnitLib/MediaServers.php";
foreach (['Mediabrowser', 'MediabrowserRecords', 'MediabrowserInfo', 'MediabrowserServer'] as $mediaClass) {
    require_once "$mediaUnitLib/$mediaClass.php";
}
require_once __DIR__ . '/JellyfinLocate.php';
require_once __DIR__ . '/JellyfinAuth.php';
// The login and settings verbs share the *ARR login code (ADR-0078): the password maker, the cause records, the typing rules.
foreach (['ArrAuth', 'ArrLoginCheck', 'ArrSettings'] as $mediaClass) {
    require_once dirname(__DIR__, 2) . "/arr/lib/$mediaClass.php";
}
require_once __DIR__ . '/JellyfinLogin.php';
require_once __DIR__ . '/JellyfinSettings.php';

$usage = <<<'TXT'
usage: php jellyfin.php <verb> [arguments] [--url http://127.0.0.1:<port>[/base]] [--human]
  stats             libraries           sessions [--addresses]       recent [--limit N]
  scan <library>... | scan --all        end-stream <session-id>... [--reason <text>]
  logs [--lines N] [--addresses]        restart
  login             (the user types an API key at a terminal; stored for this tool)
  login-check       set-login           config show [<field>] | config set <field> <value>
Libraries: an id or name from `libraries`. Session ids: from `sessions`. Output: JSON Lines. See USAGE.md.
TXT;

// Test seams: a test may set $mediaSeams before including this file. Nothing here is read from the environment.
$jfSeams = (isset($mediaSeams) ? $mediaSeams : []) + ['secrets' => Config::localDir() . '/secrets', 'pmss_dir' => '/etc/seedbox/config',
    'docker_sock' => null, 'tables' => null, 'timeout' => 15, 'wait' => 90, 'stop_wait' => 15];
$jfUid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
$jfHome = Host::home($jfUid); // HOME only when this uid owns it: set-login writes ~/.media-stack-credentials.txt there
$jfSecret = new MediaSecret($jfSeams['secrets'], 'jellyfin');

/**
 * [Mediabrowser, found, family]: the server and how it runs. The first base path whose public info answers;
 * when none answers (stopped, starting) the first one, the configured BaseUrl: Jellyfin 12 answers 302 on the
 * bare path and 503 on the prefix while it starts (PMSS install-media-stack.sh:1816-1818). `answers` polls every base.
 */
$jfConnect = function (array $opt, array $allow = Mediabrowser::ALLOW) use ($jfSeams, $jfHome, $jfUid, $jfSecret) {
    if ($jfHome === '' || !is_dir($jfHome)) {
        throw new CliError('HOME is not set to a directory', 'run the tool as the seedbox user');
    }
    $family = Host::family($jfSeams['pmss_dir']);
    $url = isset($opt['url']) && is_string($opt['url']) ? $opt['url'] : '';
    $found = JellyfinLocate::find($jfHome, $family, $jfUid, $url, new MediaDocker($jfUid, $jfSeams['docker_sock']), $jfSeams['tables']);
    $all = array_map(function ($base) use ($jfSeams, $jfUid, $jfSecret, $allow) {
        $http = new MediaHttp('Jellyfin', $base, $allow, $jfUid, $jfSeams['timeout'], $jfSeams['tables']);
        return new Mediabrowser('jellyfin', $http, $jfSecret->deviceIdLater());
    }, $found['bases']);
    $pick = 0;
    foreach (count($all) > 1 ? $all : [] as $i => $mb) {
        if ($mb->publicInfo() !== null) {
            $pick = $i;
            break;
        }
    }
    $found['url'] = $found['bases'][$pick];
    $found['answers'] = function () use ($all) {
        foreach ($all as $mb) {
            if ($mb->publicInfo(3) !== null) {
                return true;
            }
        }
        return false;
    };
    if ($found['restart']['how'] === 'pmss-installer') {
        $found['restart']['stop_wait'] = $jfSeams['stop_wait'];
    }
    return [$all[$pick], $found, $family];
};

MediaServers::run($argv, function (array $opt, $auth = true) use ($jfConnect, $jfSecret, $jfHome, $jfSeams) {
    list($mb, $found, $family) = $jfConnect($opt);
    if ($auth) {
        $mb->http->reachable(); // another account's port or a stopped server is the first thing to say, before any credential
    }
    list($found['login_from'], $secrets) = $auth ? JellyfinAuth::load($mb, $jfSecret, $jfHome, $family, __FILE__) : ['', []];
    return new MediabrowserServer($mb, $found, $secrets, $jfSeams['wait']);
}, function ($args, $opt) use ($jfConnect, $jfSecret) {
    $prompt = 'Jellyfin API key (Administration > API Keys in the dashboard; not shown): ';
    return $jfSecret->login($prompt, __FILE__, function ($key) use ($jfConnect, $opt) {
        list($mb) = $jfConnect($opt);
        $mb->useToken($key, function () {
        });
        $mb->get('/System/Info');
    });
}, $usage, [], [
    // Jellyfin only (README.md "Login and settings"): the changing verbs get JellyfinLogin::allow(), the others never do.
    'login-check' => function ($args, $opt) use ($jfConnect, $jfSecret, $jfHome) {
        return JellyfinLogin::check(function () use ($jfConnect, $opt) {
            return $jfConnect($opt);
        }, $jfSecret, $jfHome);
    },
    'set-login' => function ($args, $opt) use ($jfConnect, $jfSecret, $jfHome) {
        list($mb) = $jfConnect($opt, JellyfinLogin::allow());
        return [JellyfinLogin::set($mb, $jfSecret, $jfHome)];
    },
    'config' => function ($args, $opt) use ($jfConnect, $jfSecret, $jfHome) {
        list($mb, , $family) = $jfConnect($opt, JellyfinLogin::allow());
        $mb->http->reachable();
        list(, $secrets) = JellyfinAuth::load($mb, $jfSecret, $jfHome, $family, __FILE__);
        return MediaServers::scrub(JellyfinSettings::run($mb, $args), $secrets);
    },
]);
