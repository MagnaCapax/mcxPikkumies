<?php
/**
 * transmission: the user's own Transmission through its JSON RPC (tools/torrent-clients/README.md,
 * this unit's README.md). The shared verbs (list, show, add, remove, pause, resume, stats, traffic,
 * restart, recheck, reannounce, limit) are built from the same shared helpers
 * ../lib/Torrents.php uses (Torrents::resolve/natives/dir/printable, TorrentList, DeletePlan,
 * DeleteRun, TorrentChange, TorrentTrackers) -- this unit calls them directly instead of going through
 * Torrents::run(), so it can register `repath` and `export` alongside them in the SAME Cli::run()
 * call: one process, one `--verbs` list, matching ADR-0016's exact-match contract
 * (CliContractTest.php/ContractTest.php check that code verbs == USAGE.md verbs). Nothing here
 * duplicates client logic; only this dispatch wiring is copied from Torrents::run(), because that
 * method's verb map is private to it and has no extension point for a unit's own extra verbs.
 * PIKKUMIES_PMSS_DIR and PIKKUMIES_TORRENT_TIMEOUT exist for the tests (a fake PMSS, a short timeout).
 */
$root = dirname(__DIR__, 3);
require_once "$root/lib/Config.php";
require_once "$root/lib/Http.php";
require_once "$root/lib/OwnProcs.php";
require_once "$root/lib/SecretFile.php";
require_once dirname(__DIR__) . '/lib/Torrents.php'; // pulls in Cli, CliHuman, Quota, TorrentClient, Delete*, TorrentList, TorrentChange, TorrentTrackers
// TransmissionExtras is a trait of TransmissionClient, so it is loaded first.
foreach (['TransmissionLocate', 'TransmissionSecret', 'TransmissionRpc', 'TransmissionRecords', 'TransmissionExtras',
    'TransmissionClient', 'TransmissionAdd', 'TransmissionPause', 'TransmissionRemove', 'TransmissionStats',
    'TransmissionRestart', 'TransmissionRepath', 'TransmissionExport'] as $class) {
    require_once __DIR__ . "/$class.php";
}

$usage = 'usage: php transmission.php <verb> [arguments] [--human]
verbs: list [--state S] [--search T] [--sort F [--reverse]] [--limit N] | show <id>... [--files] [--trackers]
       add <magnet|url|file>... [--paused] [--dir D] | remove <id>... [--with-data --expect SIZE] | pause <id>... | resume <id>...
       stats | traffic | restart | recheck <id>... | reannounce <id>... | limit [--down RATE] [--up RATE]
       repath <id>... --to <dir> | export <id> | login
ids: the 40-hex ids `list` prints (8+ character prefixes for show, pause, resume, recheck, reannounce;
full ids for remove, repath, export). See USAGE.md.';

// Test seam: a test may set this before including this file (a fake secrets dir); production never does.
$transmissionSecretDir = isset($transmissionSecretDir) ? $transmissionSecretDir : Config::localDir() . '/secrets';

$connect = function () use ($transmissionSecretDir) {
    $home = rtrim((string) getenv('HOME'), '/');
    if ($home === '' || !is_dir($home)) {
        throw new CliError('HOME is not set to a directory', 'run the tool as the seedbox user');
    }
    $timeout = (int) getenv('PIKKUMIES_TORRENT_TIMEOUT');
    $family = Host::family(getenv('PIKKUMIES_PMSS_DIR') ?: '/etc/seedbox/config');
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    return new TransmissionClient($home, $family, $uid, $timeout > 0 && $timeout <= 60 ? $timeout : 10, $transmissionSecretDir);
};
$client = null;
$c = function () use (&$client, $connect) {
    return $client !== null ? $client : ($client = $connect());
};

$verbs = [
    'list' => function ($args, $opt) use ($c) {
        return TorrentList::run($c()->torrents(), $opt);
    },
    'show' => function ($args, $opt) use ($c) {
        $all = $c()->torrents();
        $targets = Torrents::resolve($all, $args, 8, 'not found'); // a read: nothing would have changed anyway
        $out = [];
        foreach ($targets as $t) { // the list fields first, in their order (README Records), the client's values win
            $out[] = Torrents::printable(array_replace($t, $c()->details($t['native']) + $t));
        }
        if (!empty($opt['trackers'])) {
            $out = array_merge($out, TorrentTrackers::records($c(), $targets));
        }
        if (!empty($opt['files'])) {
            $plan = DeletePlan::build($c(), $all, $targets);
            return ['records' => array_merge($out, $plan['files']), 'status' => DeletePlan::summary($plan)];
        }
        return $out;
    },
    'add' => function ($args, $opt) use ($c) {
        if (!$args) {
            throw new CliError('add needs a magnet link, a URL or a .torrent file', 'add "magnet:?xt=urn:btih:..." [--paused] [--dir <path>]');
        }
        $dir = isset($opt['dir']) && is_string($opt['dir']) ? Torrents::dir($opt['dir'], (string) getenv('HOME')) : null;
        return $c()->add($args, ['paused' => !empty($opt['paused']), 'dir' => $dir]);
    },
    'remove' => function ($args, $opt) use ($c) {
        $all = $c()->torrents();
        $targets = Torrents::resolve($all, $args, 40);
        if (empty($opt['with-data'])) {
            return DeleteRun::finish(['records' => $c()->remove(Torrents::natives($targets), false), 'status' => ['files' => 'kept, disk not freed']]);
        }
        return DeleteRun::execute($c(), $all, $targets, isset($opt['expect']) && is_string($opt['expect']) ? $opt['expect'] : '');
    },
    'pause' => function ($args, $opt) use ($c) {
        return $c()->pause(Torrents::natives(Torrents::resolve($c()->torrents(), $args, 8)));
    },
    'resume' => function ($args, $opt) use ($c) {
        return $c()->resume(Torrents::natives(Torrents::resolve($c()->torrents(), $args, 8)));
    },
    'stats' => function ($args, $opt) use ($c) {
        // The user's quota, not the filesystem's free space, which on shared servers is the whole array.
        return [$c()->stats() + Quota::fields((string) getenv('HOME'))];
    },
    'traffic' => function ($args, $opt) use ($c) {
        return [$c()->traffic()];
    },
    'restart' => function ($args, $opt) use ($c) {
        return [$c()->restart()];
    },
    'repath' => function ($args, $opt) use ($c) {
        $targets = Torrents::resolve($c()->torrents(), $args, 40);
        return TransmissionRepath::run($c(), $targets, isset($opt['to']) && is_string($opt['to']) ? $opt['to'] : null);
    },
    'export' => function ($args, $opt) use ($c) {
        $targets = Torrents::resolve($c()->torrents(), $args, 40, 'not found');
        return TransmissionExport::run($c(), $targets);
    },
    // Only needed when settings.json has rpc-authentication-required: true (PMSS's own unix socket never
    // does). Builds its own probe connection -- never through $c(), which would try to load a credential
    // that does not exist yet -- and never suggests turning authentication off on a failure.
    'login' => function ($args, $opt) use ($transmissionSecretDir) {
        $home = rtrim((string) getenv('HOME'), '/');
        $family = Host::family(getenv('PIKKUMIES_PMSS_DIR') ?: '/etc/seedbox/config');
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $at = TransmissionLocate::find($home, $family, $uid);
        if (empty($at['auth']['required'])) {
            throw new CliError('Transmission does not require RPC authentication on this account (rpc-authentication-required is off)',
                'nothing to store; the tool connects without a login');
        }
        if ($at['auth']['username'] === '') {
            throw new CliError('settings.json has rpc-authentication-required: true but no rpc-username', 'set rpc-username in settings.json while Transmission is stopped, then run login again');
        }
        $secretValue = TransmissionSecret::prompt("Transmission RPC password for {$at['auth']['username']} (not shown): ");
        // Saved first, then tried through the real TransmissionSecret/TransmissionRpc path (the same one
        // every other verb uses): a rejection removes it again, so nothing wrong is ever left stored.
        $secret = new TransmissionSecret($transmissionSecretDir, $uid);
        $secret->save($secretValue);
        $probe = new TransmissionRpc($at['url'], $at['unix_socket'], 10, $at['auth']['username'], $secret);
        try {
            $probe->call('session-get', ['fields' => ['version']]);
        } catch (CliError $e) {
            @unlink(rtrim($transmissionSecretDir, '/') . '/transmission');
            throw new CliError('Transmission rejected that credential; nothing was stored', 'run login again with the current rpc-password setting');
        }
        return ['records' => [['stored' => true, 'username' => $at['auth']['username']]], 'status' => ['verified' => true]];
    },
] + TorrentChange::verbs($c);

Cli::run($argv, $verbs, $usage, ['with-data', 'files', 'paused', 'trackers', 'reverse'],
    ['state', 'search', 'dir', 'expect', 'sort', 'down', 'up', 'to']);
