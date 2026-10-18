<?php
/**
 * Arr: the shared entry of every *ARR unit (tools/arr/README.md). A Servarr unit calls Arr::run($argv, '<app>',
 * $usage); the verbs of Table 1 live in ArrVerbs, the library verbs in ArrLibrary, Prowlarr's in ArrIndexers.
 * Bazarr and Autobrr have their own APIs: they load the shared code from here and run their own verbs.
 * PIKKUMIES_PMSS_DIR, PIKKUMIES_ARR_TIMEOUT and PIKKUMIES_ARR_WAIT exist for the tests only.
 */
$lib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config', 'Host', 'Http'] as $f) {
    require_once "$lib/$f.php";
}
foreach (['ArrApps', 'ArrAppsMore', 'ArrConfig', 'ArrShell', 'ArrLocate', 'ArrClient', 'ArrErrorText', 'ArrRecords', 'ArrCommand',
    'ArrPages', 'ArrDelete', 'ArrRemove', 'ArrRestart', 'ArrVerbs', 'ArrLibrary', 'ArrAdd', 'ArrReleases', 'ArrIndexers',
    'ArrGrabClient', 'ArrGrab', 'ArrLibraryReleases'] as $f) {
    require_once __DIR__ . "/$f.php";
}

class Arr
{
    public static function run(array $argv, $id, $usage)
    {
        list($verbs, $flags, $options) = self::build(ArrApps::get($id));
        Cli::run($argv, $verbs, $usage, $flags, $options);
    }

    /**
     * [verbs, flags, options] of a Servarr unit for one table row. Nothing is contacted until a verb runs, so
     * Whisparr builds its verb list first and picks the row (v2 or v3) when a verb runs (whisparr/WhisparrRun.php).
     * noAdd: the library verbs without lookup and add.
     */
    public static function build(array $app)
    {
        $locate = self::locator($app);
        $timeout = self::timeout();
        $client = null;
        $c = function () use (&$client, $locate, $app, $timeout) {
            return $client !== null ? $client : ($client = new ArrClient($locate(), $locate, $app['api'], $timeout));
        };
        $fresh = function () use ($locate, $app, $timeout) {
            return new ArrClient($locate(), $locate, $app['api'], $timeout);
        };
        $verbs = ArrVerbs::shared($app, $c, $locate, $fresh);
        $flags = [];
        $options = ['search', 'since'];
        if ($app['kind'] === 'library' && !empty($app['readOnly'])) { // Readarr: the read verbs only
            $verbs += array_intersect_key(ArrLibrary::verbs($app, $c), array_flip(['queue', 'wanted']));
            $flags = ['cutoff'];
        } elseif ($app['kind'] === 'library') {
            $lib = ArrLibrary::verbs($app, $c);
            $verbs += empty($app['noAdd']) ? $lib : array_diff_key($lib, array_flip(['lookup', 'add']));
            $flags = empty($app['noAdd']) ? ['files', 'with-data', 'no-search', 'cutoff'] : ['files', 'with-data', 'cutoff'];
            $options = array_merge($options, empty($app['noAdd']) ? ['expect', 'profile', 'root'] : ['expect']);
            if (in_array($app['id'], ['sonarr', 'radarr'], true)) { // the exact-release path; the API shape was checked only for these two (docs/adr/0046 §3)
                $verbs += ArrLibraryReleases::verbs($app, $c);
                $options = array_merge($options, ['season', 'episode']);
            }
        } else {
            $verbs += ArrIndexers::verbs($app, $c);
            $options[] = 'indexer';
            if ($app['id'] === 'prowlarr') {
                $verbs['grab'] = function ($args, $opt) use ($c) {
                    return ArrGrab::grab($c(), self::account(), $args, $opt);
                };
                $flags[] = 'via-prowlarr';
                $options[] = 'client';
            }
        }
        return [$verbs, $flags, $options];
    }

    /** fn($lenient = false) => ArrLocate::find for this user and app (tools/arr/README.md "Where each app is found"). */
    public static function locator(array $app)
    {
        $family = Host::family(getenv('PIKKUMIES_PMSS_DIR') ?: '/etc/seedbox/config');
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $home = ArrLocate::home($uid);
        return function ($lenient = false) use ($app, $home, $family, $uid) {
            if ($home === '' || !is_dir($home)) {
                throw new CliError('no home folder of this user found (HOME, or the passwd entry)', 'run the tool as the seedbox user');
            }
            $cfg = Config::load();
            $over = isset($cfg['arr'][$app['id']]) && is_array($cfg['arr'][$app['id']]) ? $cfg['arr'][$app['id']] : [];
            return ArrLocate::find($app, $home, $family, $uid, $over, $lenient);
        };
    }

    /** [home, uid] of this account, the way locator() finds them (grab's in-process torrent-client path). */
    public static function account()
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        return [ArrLocate::home($uid), $uid];
    }

    /** Seconds per API call: 15, or PIKKUMIES_ARR_TIMEOUT (1-60, the tests). */
    public static function timeout()
    {
        $t = (int) getenv('PIKKUMIES_ARR_TIMEOUT');
        return $t > 0 && $t <= 60 ? $t : 15;
    }

    /** Every library item (series, movies) or, for Prowlarr, every indexer, as the API returns them. */
    public static function all(ArrClient $c, array $app)
    {
        $rows = $c->call('GET', $app['item'], $app['kind'] === 'library' ? $app['listBools'] : [], null, ['max' => ArrClient::LIST_MAX]);
        $rows = array_values(array_filter((array) $rows, 'is_array'));
        foreach (isset($app['titleField']) ? array_keys($rows) : [] as $n) {
            $rows[$n]['title'] = ArrApps::title($app, $rows[$n]); // Lidarr artistName, Readarr authorName
        }
        return $rows;
    }

    public static function records(array $app, array $all)
    {
        return array_map(function ($i) use ($app) {
            return $app['kind'] === 'library' ? ArrRecords::item($app, $i) : ArrRecords::indexer($i);
        }, $all);
    }

    /** The items $args name: internal ids only, each resolved against the live list. One bad id fails the call; $read: a read verb. */
    public static function resolve(array $app, array $all, array $args, $read = false)
    {
        $hint = 'ids are the `id` values `list` prints (find one with `list --search <text>`)';
        if (!$args) {
            throw new CliError("name at least one {$app['item']} id", $hint);
        }
        $byId = array_column($all, null, 'id');
        $out = [];
        $bad = [];
        foreach (array_unique($args) as $a) {
            if (!ctype_digit((string) $a) || (int) $a < 1) {
                $meta = strpos((string) $a, ':') !== false;
                $what = isset($app['metaHint']) ? $app['metaHint'] : 'tvdb:/tmdb:/imdb: ids are for `lookup` and `add`';
                $bad[] = "$a: not an id" . ($meta ? " ($what)" : '');
            } elseif (!isset($byId[(int) $a])) {
                $bad[] = "$a: no such {$app['item']}";
            } else {
                $out[(int) $a] = $byId[(int) $a];
            }
        }
        if ($bad) {
            throw new CliError(($read ? 'not found: ' : 'nothing changed: ') . implode('; ', $bad), $hint);
        }
        return array_values($out);
    }

    /** Records to ask for in one request that cannot page (a search): ArrPages::need, at most one page. */
    public static function limit(array $opt)
    {
        return min(ArrPages::PAGE, ArrPages::need($opt));
    }
}
