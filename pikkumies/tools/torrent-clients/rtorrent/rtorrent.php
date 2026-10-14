<?php
/**
 * rtorrent: the user's own rTorrent over its SCGI socket (tools/torrent-clients/README.md).
 * php rtorrent.php <verb> [arguments] [--human]; the verbs are listed in USAGE.md.
 */
require_once dirname(__DIR__) . '/lib/Torrents.php';
require_once dirname(__DIR__, 3) . '/lib/Host.php';
require_once __DIR__ . '/RtorrentClient.php';

$usage = <<<'TXT'
usage: php rtorrent.php <verb> [arguments] [--human]
  list [--state <state>] [--search <text>] [--sort <field> [--reverse]] [--limit N]
  show <id>... [--files] [--trackers]
  add <magnet|url|file.torrent>... [--paused] [--dir <path>]
  remove <id>... [--with-data --expect <size>]
  pause <id>...
  resume <id>...
  stats
  traffic
  restart
  recheck <id>...
  reannounce <id>...
  limit [--down <rate>] [--up <rate>]
Ids are the 40-character ids `list` prints. Full usage: USAGE.md next to this file.
TXT;

Torrents::run($argv, function () {
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    $home = (string) getenv('HOME');
    if ($home === '' && function_exists('posix_getpwuid')) {
        $pw = posix_getpwuid($uid);
        $home = is_array($pw) ? (string) $pw['dir'] : '';
    }
    if ($home === '' || !is_dir($home)) {
        throw new CliError('cannot find your home directory ($HOME is not set or not a directory)', 'run the tool as the seedbox user, from a login shell');
    }
    return new RtorrentClient(Host::family(), $home, $uid);
}, $usage);
