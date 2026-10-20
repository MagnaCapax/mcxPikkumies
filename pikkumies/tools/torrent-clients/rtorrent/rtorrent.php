<?php
/**
 * rtorrent: the user's own rTorrent over its SCGI socket (tools/torrent-clients/README.md).
 * php rtorrent.php <verb> [arguments] [--human]; the verbs are listed in USAGE.md.
 */
require_once dirname(__DIR__) . '/lib/Torrents.php';
require_once dirname(__DIR__, 3) . '/lib/Host.php';
require_once __DIR__ . '/RtorrentClient.php';
require_once dirname(__DIR__) . '/lib/TorrentExport.php';
require_once __DIR__ . '/Export.php';
require_once __DIR__ . '/Repath.php';

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
  export <id>       (the .torrent as base64 parts, for move)
  repath <id>... --to <dir>
Ids are the 40-character ids `list` prints. Full usage: USAGE.md next to this file.
TXT;

$rtorrentConnect = function () {
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    $home = (string) getenv('HOME');
    if ($home === '' && function_exists('posix_getpwuid')) {
        $pw = posix_getpwuid($uid);
        $home = is_array($pw) ? (string) $pw['dir'] : '';
    }
    if ($home === '' || !is_dir($home)) {
        throw new CliError('cannot find your home directory ($HOME is not set or not a directory)', 'run the tool as the seedbox user, from a login shell');
    }
    // PIKKUMIES_PMSS_DIR exists for the tests (a fake PMSS marker), the same seam deluge.php uses; unset in production.
    return new RtorrentClient(Host::family(getenv('PIKKUMIES_PMSS_DIR') ?: '/etc/seedbox/config'), $home, $uid);
};

// export (no extra option) and repath (needs --to, which Torrents::run's own Cli::run call does not
// allow-list) are not run through Torrents::run's $extra verbs: both are detected and run here first, the
// same way qbittorrent.php intercepts `login`.
$rtorrentVerbs = ['list', 'show', 'add', 'remove', 'pause', 'resume', 'stats', 'traffic', 'restart', 'recheck', 'reannounce', 'limit', 'export', 'repath'];
list($rtorrentVerb, , $rtorrentOpt) = Cli::parse($argv, $rtorrentVerbs);
if ($rtorrentVerb === null && !empty($rtorrentOpt['verbs'])) {
    echo implode("\n", $rtorrentVerbs), "\n"; // Torrents::run lists only its own shared verbs
    exit(0);
}
if ($rtorrentVerb === 'export') {
    Cli::run($argv, ['export' => function ($args, $opt) use ($rtorrentConnect) {
        return RtorrentExport::verb($rtorrentConnect(), $args, $opt);
    }], $usage);
}
if ($rtorrentVerb === 'repath') {
    Cli::run($argv, ['repath' => function ($args, $opt) use ($rtorrentConnect) {
        return RtorrentRepath::run($rtorrentConnect(), $args, $opt);
    }], $usage, [], ['to']);
}
Torrents::run($argv, $rtorrentConnect, $usage);
