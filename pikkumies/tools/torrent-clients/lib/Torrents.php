<?php
/**
 * Torrents: the verbs every torrent-client unit shares (tools/torrent-clients/README.md). A unit
 * implements TorrentClient for its client and calls Torrents::run(); ids, the "never all" rule and
 * the delete plan live here once, so the three clients behave the same.
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/Cli.php";
require_once "$lib/CliHuman.php";
require_once "$lib/Quota.php";
require_once __DIR__ . '/TorrentClient.php';
require_once __DIR__ . '/DeleteFreed.php';
require_once __DIR__ . '/DeletePlan.php';
require_once __DIR__ . '/DeleteRun.php';
require_once __DIR__ . '/TorrentList.php';
require_once __DIR__ . '/TorrentChange.php';
require_once __DIR__ . '/TorrentTrackers.php';

class Torrents
{
    const STATES = ['downloading', 'seeding', 'paused', 'checking', 'queued', 'error', 'stalled', 'unknown'];

    /** $connect: fn() => TorrentClient, called only when a verb needs the client. */
    public static function run(array $argv, callable $connect, $usage)
    {
        $client = null;
        $c = function () use (&$client, $connect) {
            return $client !== null ? $client : ($client = $connect());
        };
        Cli::run($argv, [
            'list' => function ($args, $opt) use ($c) {
                return TorrentList::run($c()->torrents(), $opt);
            },
            'show' => function ($args, $opt) use ($c) {
                $all = $c()->torrents();
                $targets = self::resolve($all, $args, 8, 'not found'); // a read: nothing would have changed anyway
                $out = [];
                foreach ($targets as $t) { // the list fields first, in their order (README Records), the client's values win
                    $out[] = self::printable(array_replace($t, $c()->details($t['native']) + $t));
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
                $dir = isset($opt['dir']) && is_string($opt['dir']) ? self::dir($opt['dir'], (string) getenv('HOME')) : null;
                return $c()->add($args, ['paused' => !empty($opt['paused']), 'dir' => $dir]);
            },
            'remove' => function ($args, $opt) use ($c) {
                $all = $c()->torrents();
                $targets = self::resolve($all, $args, 40);
                if (empty($opt['with-data'])) {
                    return DeleteRun::finish(['records' => $c()->remove(self::natives($targets), false), 'status' => ['files' => 'kept, disk not freed']]);
                }
                return DeleteRun::execute($c(), $all, $targets, isset($opt['expect']) && is_string($opt['expect']) ? $opt['expect'] : '');
            },
            'pause' => function ($args, $opt) use ($c) {
                return $c()->pause(self::natives(self::resolve($c()->torrents(), $args, 8)));
            },
            'resume' => function ($args, $opt) use ($c) {
                return $c()->resume(self::natives(self::resolve($c()->torrents(), $args, 8)));
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
        ] + TorrentChange::verbs($c), $usage, ['with-data', 'files', 'paused', 'trackers', 'reverse'], ['state', 'search', 'dir', 'expect',
            'sort', 'down', 'up']);
    }

    /**
     * The torrents $args name: full ids, or unique prefixes of at least $min hex characters.
     * One bad id fails the whole call, so a batch never half-runs. Never returns an empty list.
     * $lead starts the error: what a change verb did (nothing changed); a read says `not found`.
     */
    public static function resolve(array $all, array $args, $min, $lead = 'nothing changed')
    {
        if (!$args) {
            throw new CliError('name at least one torrent id', 'ids come from `list` (find one by name with `list --search <text>`)');
        }
        $out = [];
        $bad = [];
        foreach (array_unique(array_map('strtolower', $args)) as $a) {
            if (!preg_match('/^[0-9a-f]+$/', $a) || strlen($a) < $min || strlen($a) > 40) {
                $bad[] = "$a: not an id (" . ($min === 40 ? '40' : "$min to 40") . ' hex characters)';
                continue;
            }
            $m = array_values(array_filter($all, function ($r) use ($a) {
                return strpos($r['id'], $a) === 0;
            }));
            if (count($m) !== 1) {
                $bad[] = $m ? "$a: matches " . count($m) . ' torrents (' . implode(', ', array_map(function ($r) {
                    return $r['id'] . ' ' . $r['name'];
                }, array_slice($m, 0, 5))) . ')' : "$a: no such torrent";
                continue;
            }
            if (!preg_match('/^[0-9a-fA-F]{40}$/', (string) $m[0]['native'])) {
                $bad[] = "$a: the client reported an id that is not 40 hex characters";
                continue;
            }
            $out[$m[0]['id']] = $m[0];
        }
        if ($bad) {
            throw new CliError($lead . ': ' . implode('; ', $bad), 'ids come from `list` (find one by name with `list --search <text>`)');
        }
        return array_values($out);
    }

    /** --dir as its real path: an existing folder inside $home, for every client alike (rTorrent's rule, the strictest). */
    public static function dir($dir, $home)
    {
        $real = realpath($dir);
        $realHome = $home === '' ? false : realpath($home);
        if ($real === false || !is_dir($real) || $realHome === false || strpos($real . '/', rtrim($realHome, '/') . '/') !== 0) {
            throw new CliError("nothing added: --dir $dir is not an existing folder inside your home", 'create it first, e.g. mkdir -p ~/data/new');
        }
        return $real;
    }

    /** The client's own id strings, never empty (no verb may reach the client's "all"). */
    public static function natives(array $targets)
    {
        $n = array_column($targets, 'native');
        if (!$n) {
            throw new CliError('no torrent selected', 'name at least one id');
        }
        return $n;
    }

    /** A record as printed: the client's own id string stays internal. */
    public static function printable(array $r)
    {
        unset($r['native']);
        return $r;
    }
}
