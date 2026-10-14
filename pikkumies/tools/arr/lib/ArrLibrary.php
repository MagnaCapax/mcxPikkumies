<?php
/**
 * ArrLibrary: the verbs of the library apps (tools/arr/README.md Table 2): lookup, add (ArrAdd), remove
 * (ArrDelete, ArrRemove), search, queue, wanted. Paths and parameter names: the upstream OpenAPI specs at
 * the commits in each unit's README.md. Every boolean the endpoint declares is sent (ArrApps).
 */
class ArrLibrary
{
    public static function verbs(array $app, callable $c)
    {
        return [
            'lookup' => function ($args, $opt) use ($app, $c) {
                return self::lookup($c(), $app, trim(implode(' ', $args)));
            },
            'add' => function ($args, $opt) use ($app, $c) {
                return ArrAdd::add($c(), $app, $args, $opt);
            },
            'remove' => function ($args, $opt) use ($app, $c) {
                $all = Arr::all($c(), $app);
                $targets = Arr::resolve($app, $all, $args);
                if (!empty($opt['with-data'])) {
                    $expect = isset($opt['expect']) && is_string($opt['expect']) ? $opt['expect'] : '';
                    return ArrDelete::execute($c(), $app, $all, $targets, $expect);
                }
                $sent = ArrRemove::send($c(), $app, $targets, $app['deleteBools']);
                return ArrRemove::finish(ArrRemove::report($c(), $app, $targets, ['per' => []], '', 'kept', $sent));
            },
            'search' => function ($args, $opt) use ($app, $c) {
                $targets = Arr::resolve($app, Arr::all($c(), $app), $args);
                $s = $app['search'];
                if ($s['list']) { // one command for the batch, one record per item with that command's fields, as below
                    $ids = array_map('intval', array_column($targets, 'id'));
                    $cmd = ArrCommand::run($c(), $app, [[$s['name'], [$s['field'] => $ids], ['ids' => $ids]]])[0];
                    unset($cmd['ids']);
                    return array_map(function ($t) use ($cmd) {
                        return ['id' => (int) $t['id'], 'title' => ArrRecords::s($t, 'title')] + $cmd;
                    }, $targets);
                }
                return ArrCommand::run($c(), $app, array_map(function ($t) use ($s) {
                    return [$s['name'], [$s['field'] => (int) $t['id']], ['id' => (int) $t['id'], 'title' => (string) $t['title']]];
                }, $targets));
            },
            'queue' => function ($args, $opt) use ($app, $c) {
                list($rows, $status, $total, $all) = ArrPages::get($c(), 'queue', ArrPages::SERVARR, $app['queueBools'], $opt);
                return ['records' => array_map(function ($q) use ($app) {
                    return ArrRecords::queue($app, $q);
                }, $rows), 'status' => ['in_queue' => $total, 'total' => $all] + $status];
            },
            'wanted' => function ($args, $opt) use ($app, $c) {
                $cutoff = !empty($opt['cutoff']);
                list($rows, $status, $total, $all) = ArrPages::get($c(), $cutoff ? 'wanted/cutoff' : 'wanted/missing', ArrPages::SERVARR,
                    $app[$cutoff ? 'cutoffBools' : 'wantedBools'], $opt);
                return ['records' => array_map(function ($w) use ($app) {
                    return self::wanted($app, $w);
                }, $rows), 'status' => ['wanted' => $total, 'total' => $all] + $status];
            },
        ];
    }

    /** Metadata search by title or prefixed id, marked with whether (and as which id) each result is in the library. */
    public static function lookup(ArrClient $c, array $app, $term)
    {
        if ($term === '') {
            throw new CliError('lookup needs a title or an id',
                isset($app['example']) ? $app['example'] : 'lookup "the matrix"   or   lookup ' . key($app['prefixes']) . ':603');
        }
        $have = array_column(Arr::all($c, $app), 'id', $app['exists']);
        $hits = (array) $c->call('GET', $app['item'] . '/lookup', ['term' => $term], null, ['timeout' => 60]);
        return array_map(function ($i) use ($app, $have) {
            $in = !empty($i[$app['exists']]) && isset($have[$i[$app['exists']]]);
            return ['meta_id' => ArrApps::metaId($app, $i), 'title' => ArrApps::title($app, $i),
                'year' => isset($i['year']) ? (int) $i['year'] : 0, 'in_library' => $in, 'id' => $in ? (int) $have[$i[$app['exists']]] : 0,
                'overview' => ArrRecords::cut(ArrRecords::s($i, 'overview'), 200)];
        }, array_values(array_filter($hits, 'is_array')));
    }

    /** A wanted row: the movie record for Radarr, the episode for Sonarr, the album or book for Lidarr and Readarr. */
    private static function wanted(array $app, array $w)
    {
        if (isset($app['wantedRow'])) {
            $ref = $app['wantedRow']['ref'];
            return ['id' => (int) $w['id'], 'item' => isset($w[$ref]) ? (int) $w[$ref] : 0, 'title' => ArrRecords::s($w, 'title'),
                'released_at' => ArrRecords::time($w, $app['wantedRow']['date'])];
        }
        if ($app['id'] !== 'sonarr') {
            return ArrRecords::item($app, $w);
        }
        return ['id' => (int) $w['id'], 'item' => isset($w['seriesId']) ? (int) $w['seriesId'] : 0, 'title' => ArrRecords::s($w, 'title'),
            'season' => isset($w['seasonNumber']) ? (int) $w['seasonNumber'] : 0,
            'episode' => isset($w['episodeNumber']) ? (int) $w['episodeNumber'] : 0,
            'aired_at' => ArrRecords::time($w, 'airDateUtc')];
    }
}
