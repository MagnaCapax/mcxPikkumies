<?php
/**
 * BazarrRun: Bazarr's verb table. Calls: GET series, movies (list, show), system/status, system/health,
 * episodes|movies/wanted and /history; PATCH series|movies action=search-missing (search, the one change
 * besides restart: Bazarr downloads subtitle files). Bazarr's search runs inside the request
 * (api/series/series.py:261-267), so every search of one call shares the ArrCommand::WAIT budget.
 */
class BazarrRun
{
    public static function run(array $argv, $usage)
    {
        $family = Host::family(getenv('PIKKUMIES_PMSS_DIR') ?: '/etc/seedbox/config');
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $home = ArrLocate::home($uid);
        $locate = function ($lenient = false) use ($home, $family, $uid) {
            if ($home === '' || !is_dir($home)) {
                throw new CliError('no home folder of this user found (HOME, or the passwd entry)', 'run the tool as the seedbox user');
            }
            $cfg = Config::load();
            $over = isset($cfg['arr']['bazarr']) && is_array($cfg['arr']['bazarr']) ? $cfg['arr']['bazarr'] : [];
            return BazarrConfig::find($home, $family, $uid, $over, $lenient);
        };
        $t = (int) getenv('PIKKUMIES_ARR_TIMEOUT');
        $timeout = $t > 0 && $t <= 60 ? $t : 15;
        $fresh = function () use ($locate, $timeout) {
            return new ArrClient($locate(), $locate, '', $timeout);
        };
        $client = null;
        $c = function () use (&$client, $fresh) {
            return $client !== null ? $client : ($client = call_user_func($fresh));
        };
        Cli::run($argv, self::verbs($c, $locate, $fresh), $usage, [], ['search']);
    }

    public static function verbs(callable $c, callable $locate, callable $fresh)
    {
        return [
            'list' => function ($args, $opt) use ($c) {
                $s = isset($opt['search']) && is_string($opt['search']) ? strtolower($opt['search']) : null;
                $out = [];
                foreach (['series', 'movie'] as $kind) {
                    foreach (BazarrVerbs::all($c(), $kind)['rows'] as $i) {
                        $r = BazarrVerbs::record($kind, $i);
                        if ($s === null || strpos(strtolower($r['title']), $s) !== false) {
                            $out[] = $r;
                        }
                    }
                }
                return $out;
            },
            'show' => function ($args, $opt) use ($c) {
                return array_map(function ($f) {
                    list($kind, $i) = $f;
                    return BazarrVerbs::record($kind, $i) + ['overview' => ArrRecords::cut(ArrRecords::s($i, 'overview'), 500),
                        'audio_languages' => BazarrVerbs::languages(isset($i['audio_language']) ? $i['audio_language'] : []),
                        'subtitle_files' => array_values(array_map(function ($s) {
                            return ['language' => BazarrVerbs::languages([$s])[0], 'path' => ArrRecords::s($s, 'path')];
                        }, array_filter(isset($i['subtitles']) ? (array) $i['subtitles'] : [], 'is_array')))];
                }, BazarrVerbs::resolve($c(), $args));
            },
            'stats' => function ($args, $opt) use ($c) {
                return [self::stats($c())];
            },
            'health' => function ($args, $opt) use ($c) {
                $h = $c()->call('GET', 'system/health');
                return array_map(function ($i) {
                    return ['source' => ArrRecords::s($i, 'object'), 'type' => 'warning',
                        'message' => ArrErrorText::urls(ArrRecords::s($i, 'issue')), 'wiki' => ''];
                }, array_values(array_filter(isset($h['data']) ? (array) $h['data'] : [], 'is_array')));
            },
            'history' => function ($args, $opt) use ($c) {
                return self::history($c(), $opt);
            },
            'restart' => function ($args, $opt) use ($locate, $fresh) {
                $marker = function () use ($fresh) {
                    try {
                        $s = call_user_func($fresh)->call('GET', 'system/status', [], null, ['timeout' => 5]);
                    } catch (CliError $e) {
                        return null;
                    }
                    $t = isset($s['data']['start_time']) && is_numeric($s['data']['start_time']) ? $s['data']['start_time'] : null;
                    return $t === null ? null : ['at' => (int) $t, 'id' => (string) $t];
                };
                return [ArrRestart::run(BazarrConfig::APP, $locate(true), $fresh, $marker)];
            },
            'wanted' => function ($args, $opt) use ($c) {
                return BazarrVerbs::wanted($c(), $opt);
            },
            'search' => function ($args, $opt) use ($c) {
                return BazarrSearch::run($c(), BazarrVerbs::resolve($c(), $args));
            },
            'login-check' => function ($args, $opt) use ($c, $locate) {
                return BazarrLogin::check($locate, $c, Arr::timeout());
            },
            'set-login' => function ($args, $opt) use ($c, $locate) {
                return [BazarrLogin::set($locate, $c(), Arr::timeout())];
            },
            'config' => function ($args, $opt) use ($c) {
                return BazarrSettings::run($c(), $args);
            },
        ];
    }

    /** `stats`: version, start time, where it was found, the Sonarr/Radarr links, counts and health warnings. */
    private static function stats(ArrClient $c)
    {
        $s = $c->call('GET', 'system/status');
        $s = isset($s['data']) && is_array($s['data']) ? $s['data'] : [];
        $w = $c->where();
        $count = function ($path) use ($c) {
            $p = $c->call('GET', $path, ['start' => 0, 'length' => 1]);
            return isset($p['total']) ? (int) $p['total'] : 0;
        };
        $h = $c->call('GET', 'system/health');
        return ['app' => 'Bazarr', 'version' => ArrRecords::s($s, 'bazarr_version'),
            'started_at' => isset($s['start_time']) && is_numeric($s['start_time']) ? (int) $s['start_time'] : 0,
            'sonarr_version' => ArrRecords::s($s, 'sonarr_version'), 'radarr_version' => ArrRecords::s($s, 'radarr_version'),
            'found' => $w['source'], 'config' => str_replace($w['home'], '~', $w['config']), 'port' => $w['port'],
            'url_base' => $w['urlBase'],
            'process' => 'answering', 'series' => $count('series'), 'movies' => $count('movies'),
            'episodes_wanted' => $count('episodes/wanted'), 'movies_wanted' => $count('movies/wanted'),
            'health_warnings' => count(isset($h['data']) ? (array) $h['data'] : [])];
    }

    /** Newest subtitle events of both kinds. Times: Bazarr sends local-format text; event_at is 0 when it does not parse. */
    private static function history(ArrClient $c, array $opt)
    {
        $out = [];
        $note = [];
        $all = 0; // both kinds' totals; null once one is unknown
        foreach (['episodes' => 'series', 'movies' => 'movie'] as $path => $kind) {
            list($rows, $more, , $total) = ArrPages::get($c, "$path/history", ['start', 'length', 'data', 'total'], [], $opt);
            $note += $more;
            $all = $all === null || $total === null ? null : $all + $total;
            foreach ($rows as $h) {
                $id = $kind === 'series' ? ArrRecords::s($h, 'sonarrSeriesId') : ArrRecords::s($h, 'radarrId');
                $at = strtotime(ArrRecords::s($h, 'parsed_timestamp'));
                $out[] = ['event_at' => $at === false ? 0 : $at, 'when' => ArrRecords::s($h, 'timestamp'), 'item' => "$kind:" . (int) $id,
                    'title' => trim(ArrRecords::s($h, $kind === 'series' ? 'seriesTitle' : 'title') . ' '
                        . ArrRecords::s($h, 'episode_number')),
                    'event' => ArrRecords::s($h, 'description'), 'provider' => ArrRecords::s($h, 'provider'),
                    'language' => isset($h['language']) && is_array($h['language']) ? ArrRecords::s($h['language'], 'name') : '',
                    'score' => ArrRecords::s($h, 'score')];
            }
        }
        usort($out, function ($a, $b) {
            return $b['event_at'] - $a['event_at'];
        });
        return ['records' => array_slice($out, 0, ArrPages::need($opt)), 'status' => ['total' => $all] + $note];
    }
}
