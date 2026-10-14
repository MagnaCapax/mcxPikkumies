<?php
/**
 * ArrVerbs: the verbs every *ARR unit has (tools/arr/README.md Table 1): list, show, stats, health,
 * history, restart. $c: fn() => the shared ArrClient; $locate: ArrLocate::find for this user and app;
 * $fresh: fn() => a new ArrClient (restart reads config.xml again).
 */
class ArrVerbs
{
    public static function shared(array $app, callable $c, callable $locate, callable $fresh)
    {
        return [
            'list' => function ($args, $opt) use ($c, $app) {
                $search = isset($opt['search']) && is_string($opt['search']) ? strtolower($opt['search']) : null;
                return array_values(array_filter(Arr::records($app, Arr::all($c(), $app)), function ($r) use ($search) {
                    return $search === null || strpos(strtolower(isset($r['title']) ? $r['title'] : $r['name']), $search) !== false;
                }));
            },
            'show' => function ($args, $opt) use ($c, $app) {
                return self::show($c(), $app, $args, $opt);
            },
            'stats' => function ($args, $opt) use ($c, $app) {
                return [ArrRecords::stats($c(), $app)];
            },
            'health' => function ($args, $opt) use ($c) {
                return array_map(function ($h) {
                    return ['source' => ArrRecords::s($h, 'source'), 'type' => ArrRecords::s($h, 'type'),
                        'message' => ArrRecords::s($h, 'message'), 'wiki' => ArrRecords::s($h, 'wikiUrl')];
                }, (array) $c()->call('GET', 'health'));
            },
            'history' => function ($args, $opt) use ($c, $app) {
                return self::history($c(), $app, $opt);
            },
            'restart' => function ($args, $opt) use ($locate, $fresh, $app) {
                return [ArrRestart::run($app, $locate(true), $fresh)];
            },
        ];
    }

    /** The items, and with --files every file under their folders and the delete preview (ArrDelete::plan). */
    public static function show(ArrClient $c, array $app, array $args, array $opt)
    {
        $all = Arr::all($c, $app);
        $targets = Arr::resolve($app, $all, $args);
        if ($app['kind'] === 'indexer') {
            return ArrIndexers::show($c, $targets);
        }
        $out = array_map(function ($i) use ($app) {
            return ArrRecords::item($app, $i) + ['overview' => ArrRecords::cut(ArrRecords::s($i, 'overview'), 500),
                'root' => ArrRecords::s($i, 'rootFolderPath'),
                'quality_profile_id' => isset($i['qualityProfileId']) ? (int) $i['qualityProfileId'] : 0,
                'imdb' => ArrRecords::s($i, 'imdbId')];
        }, $targets);
        if (empty($opt['files'])) {
            return $out;
        }
        $plan = ArrDelete::plan($app, $all, $targets);
        return ['records' => array_merge($out, $plan['files']), 'status' => ArrDelete::summary($plan)];
    }

    public static function history(ArrClient $c, array $app, array $opt)
    {
        if (isset($opt['since'])) {
            $t = is_string($opt['since']) ? strtotime($opt['since']) : false;
            if ($t === false) {
                throw new CliError('--since needs a time, e.g. 2026-10-01 or "-2 days"', 'nothing was read');
            }
            $rows = (array) $c->call('GET', 'history/since', ['date' => gmdate('Y-m-d\TH:i:s\Z', $t)] + $app['historyBools']);
        } else {
            $q = ['page' => 1, 'pageSize' => Arr::limit($opt), 'sortKey' => 'date', 'sortDirection' => 'descending'];
            $page = $c->call('GET', 'history', $q + $app['historyBools']);
            $rows = isset($page['records']) ? (array) $page['records'] : [];
        }
        return array_map(function ($h) use ($app) {
            return ArrRecords::history($app, $h);
        }, array_values(array_filter($rows, 'is_array')));
    }
}
