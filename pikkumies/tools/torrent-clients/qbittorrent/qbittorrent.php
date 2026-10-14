<?php
/**
 * qbittorrent: this account's qBittorrent through its WebUI API v2 (README.md in this directory).
 * The shared verbs live in ../lib/Torrents.php; this unit adds `login`, which stores the WebUI
 * password the user types at a terminal (ADR-0019). Never sends the client's "all" value, never
 * changes its login settings, never contacts a port another account owns.
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/Config.php";
require_once "$lib/Host.php";
require_once "$lib/Http.php";
require_once dirname(__DIR__) . '/lib/Torrents.php';
require_once dirname(__DIR__) . '/lib/Bencode.php';
foreach (['QbitSecret', 'QbitEndpoint', 'QbitApi', 'QbitProc', 'QbitRestart', 'QbitStats', 'QbitAdd', 'QbitChange', 'QbitDetails',
    'QbitExtras', 'QbittorrentClient'] as $qbitClass) {
    require_once __DIR__ . "/$qbitClass.php";
}

$usage = <<<'TXT'
usage: php qbittorrent.php <verb> [arguments] [--human]
  list [--state <state>] [--search <text>] [--sort <field> [--reverse]] [--limit N]
  show <id>... [--files] [--trackers]
  add <magnet|url|file.torrent>... [--paused] [--dir <path>]
  remove <id>... [--with-data --expect <size>]
  pause <id>...     resume <id>...
  stats             traffic             restart
  recheck <id>...   reannounce <id>...  limit [--down <rate>] [--up <rate>]
  login             (the user types the WebUI password at a terminal; stored for this tool)
Ids are the 40-hex ids `list` prints. Output: JSON Lines; --human for people. See USAGE.md.
TXT;

// Test seams: a test may set these before including this file. Nothing here is read from the environment.
$qbitSecretDir = isset($qbitSecretDir) ? $qbitSecretDir : Config::localDir() . '/secrets';
$qbitProcRoot = isset($qbitProcRoot) ? $qbitProcRoot : '/proc';
$qbitFamily = isset($qbitFamily) ? $qbitFamily : null;
$qbitPage = isset($qbitPage) ? $qbitPage : null;
$qbitMake = function () use ($qbitSecretDir, $qbitFamily) {
    $ep = new QbitEndpoint((string) getenv('HOME'), $qbitFamily !== null ? $qbitFamily : Host::family());
    $secret = new QbitSecret($qbitSecretDir);
    return [new QbitApi($ep, $secret), $secret];
};

$qbitVerbs = ['list', 'show', 'add', 'remove', 'pause', 'resume', 'stats', 'traffic', 'restart', 'recheck', 'reannounce', 'limit', 'login'];
list($qbitVerb, , $qbitOpt) = Cli::parse($argv, $qbitVerbs);
if ($qbitVerb === null && !empty($qbitOpt['verbs'])) {
    echo implode("\n", $qbitVerbs), "\n"; // Torrents::run lists only the shared verbs
    exit(0);
}
if ($qbitVerb === 'login') {
    Cli::run($argv, ['login' => function () use ($qbitMake) {
        list($api, $secret) = $qbitMake();
        $api->ep->readConfig();
        if ($api->ep->username === '') {
            throw new CliError('no WebUI\\Username in ~' . QbitEndpoint::CONF, 'set the WebUI username in qBittorrent once (Tools > Options > WebUI)');
        }
        $pw = QbitSecret::prompt("qBittorrent WebUI password for {$api->ep->username} (not shown): ");
        try {
            $api->ep->locate();
        } catch (CliError $e) {
            $secret->save($pw);
            return ['records' => [['stored' => $secret->path(), 'user' => $api->ep->username, 'verified' => false, 'note' => 'stored without a check: ' . $e->getMessage()]], 'status' => ['verified' => false]];
        }
        if ($api->attempt($pw) === 'fails') {
            throw new CliError('qBittorrent rejected that password; nothing was stored', 'run login again with the WebUI password (on Pulsed Media the account password, which PMSS copies into qBittorrent); 5 failures ban this address for an hour');
        }
        $secret->save($pw);
        return ['records' => [['stored' => $secret->path(), 'user' => $api->ep->username, 'verified' => true]], 'status' => ['verified' => true]];
    }], $usage);
}

Torrents::run($argv, function () use ($qbitMake, $qbitProcRoot, $qbitPage) {
    list($api) = $qbitMake();
    $client = new QbittorrentClient($api, new QbitRestart(new QbitProc($qbitProcRoot)));
    if ($qbitPage !== null) {
        $client->page = (int) $qbitPage;
    }
    return $client;
}, $usage);
