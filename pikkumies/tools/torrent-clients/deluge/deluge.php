<?php
/**
 * deluge: the user's own Deluge through deluge-web JSON-RPC on 127.0.0.1 (tools/torrent-clients/README.md,
 * this unit's README.md). The verbs and the id and delete rules are shared (lib/Torrents.php).
 * PIKKUMIES_PMSS_DIR and PIKKUMIES_TORRENT_TIMEOUT exist for the tests (a fake PMSS, a short timeout).
 */
$root = dirname(__DIR__, 3);
require_once "$root/lib/Http.php";
require_once dirname(__DIR__) . '/lib/Torrents.php';
require_once dirname(__DIR__) . '/lib/TorrentExport.php';
// DelugeExtras is a trait of DelugeClient, so it is loaded first.
foreach (['DelugeLocate', 'DelugeRpc', 'DelugeFiles', 'DelugeExtras', 'DelugeClient', 'DelugeAdd', 'DelugePause', 'DelugeRemove',
    'DelugeStats',
    'DelugeRestart', 'Export'] as $class) {
    require_once __DIR__ . "/$class.php";
}

$usage = 'usage: php deluge.php <verb> [arguments] [--human]
verbs: list [--state S] [--search T] [--sort F [--reverse]] [--limit N] | show <id>... [--files] [--trackers]
       add <magnet|url|file>... [--paused] [--dir D] | remove <id>... [--with-data --expect SIZE] | pause <id>... | resume <id>...
       stats | traffic | restart | recheck <id>... | reannounce <id>... | limit [--down RATE] [--up RATE]
       export <id> (the .torrent as base64 parts, for move)
ids: the 40-hex ids `list` prints (8+ character prefixes for show, pause, resume). See USAGE.md.';

Torrents::run($argv, function () {
    $home = rtrim((string) getenv('HOME'), '/');
    if ($home === '' || !is_dir($home)) {
        throw new CliError('HOME is not set to a directory', 'run the tool as the seedbox user');
    }
    $timeout = (int) getenv('PIKKUMIES_TORRENT_TIMEOUT');
    $family = Host::family(getenv('PIKKUMIES_PMSS_DIR') ?: '/etc/seedbox/config');
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    return new DelugeClient($home, $family, $uid, $timeout > 0 && $timeout <= 60 ? $timeout : 10);
}, $usage, ['export' => ['DelugeExport', 'verb']]);
