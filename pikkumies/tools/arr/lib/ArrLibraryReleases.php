<?php
/**
 * ArrLibraryReleases: `releases`/`grab` on Sonarr and Radarr (docs/adr/0046 §3): the exact-release
 * path for a title already in the library, as opposed to Prowlarr's free-text `releases`/`grab`.
 * Sonarr `GET /api/v3/release?episodeId=ID` or `?seriesId=<id>&seasonNumber=N`, both required
 * together (ReleaseController.cs GetReleases:171-190; neither given falls back to the RSS feed,
 * which this unit never asks for); Radarr `GET /api/v3/release?movieId=<id>` (its own
 * ReleaseController, same shape). `grab` posts the same app's own `DownloadRelease`
 * ({guid, indexerId}; 404 the cache entry expired inside the app too, "search again"): the app then
 * sends it to its own configured download client, so this never touches a torrent client directly.
 * Only sonarr and radarr: Lidarr, Readarr and Whisparr were not checked against the same API shape
 * (ADR-0046).
 */
class ArrLibraryReleases
{
    public static function verbs(array $app, callable $c)
    {
        return [
            'releases' => function ($args, $opt) use ($app, $c) {
                return self::releases($c(), $app, $args, $opt);
            },
            'grab' => function ($args, $opt) use ($app, $c) {
                return self::grab($c(), $app, $args);
            },
        ];
    }

    private static function releases(ArrClient $c, array $app, array $args, array $opt)
    {
        if (count($args) !== 1 || !ctype_digit((string) $args[0])) {
            throw new CliError("releases needs one {$app['item']} id", 'releases <id> (an id from `list`)');
        }
        $q = self::query($app, (int) $args[0], $opt);
        $rows = array_values(array_filter((array) $c->call('GET', 'release', $q, null, ['timeout' => 60, 'raw' => true]), 'is_array'));
        $cache = ArrReleases::save(array_map(function ($r) {
            return ['indexerId' => isset($r['indexerId']) ? (int) $r['indexerId'] : 0, 'indexer' => ArrRecords::s($r, 'indexer'),
                'guid' => ArrRecords::s($r, 'guid'), 'title' => ArrRecords::s($r, 'title'), 'size' => isset($r['size']) ? (int) $r['size'] : 0,
                'seeders' => isset($r['seeders']) ? (int) $r['seeders'] : null, 'protocol' => ArrRecords::s($r, 'protocol')];
        }, $rows), $app['id']);
        return array_map(function ($r, $n) use ($cache) {
            $rejections = isset($r['rejections']) ? array_values(array_filter((array) $r['rejections'], 'is_string')) : [];
            return ['handle' => $cache[$n]['handle'], 'title' => ArrRecords::s($r, 'title'), 'size' => isset($r['size']) ? (int) $r['size'] : 0,
                'quality' => isset($r['quality']['quality']['name']) ? (string) $r['quality']['quality']['name'] : '',
                'indexer' => ArrRecords::s($r, 'indexer'), 'seeders' => isset($r['seeders']) ? (int) $r['seeders'] : null,
                'rejected' => !empty($r['rejected']), 'rejection' => isset($rejections[0]) ? $rejections[0] : ''];
        }, $rows, array_keys($rows));
    }

    /** seriesId+seasonNumber or episodeId (Sonarr, both required together); movieId (Radarr). */
    private static function query(array $app, $id, array $opt)
    {
        if ($app['id'] !== 'sonarr') {
            if (isset($opt['season']) || isset($opt['episode'])) {
                throw new CliError("{$app['name']} releases takes no --season or --episode", 'releases <id>');
            }
            return ['movieId' => $id];
        }
        if (isset($opt['episode']) && isset($opt['season'])) {
            throw new CliError('sonarr releases takes --season or --episode, not both', 'releases <id> --season 1');
        }
        foreach (['episode', 'season'] as $k) {
            if (isset($opt[$k]) && !ctype_digit((string) $opt[$k])) {
                throw new CliError("--$k needs a number", 'releases <id> --season 1');
            }
        }
        if (isset($opt['episode'])) {
            return ['episodeId' => (int) $opt['episode']];
        }
        if (isset($opt['season'])) {
            return ['seriesId' => $id, 'seasonNumber' => (int) $opt['season']];
        }
        throw new CliError('sonarr releases needs --season N or --episode <id>', 'releases <id> --season 1');
    }

    private static function grab(ArrClient $c, array $app, array $args)
    {
        if (count($args) !== 1) {
            throw new CliError('grab needs exactly one handle', 'grab <handle> (the handle `releases` printed)');
        }
        $entry = ArrReleases::take($args[0], $app['id']);
        $c->call('POST', 'release', [], ['guid' => $entry['guid'], 'indexerId' => (int) $entry['indexerId']],
            ['missing' => 'no such result; search again']);
        ArrReleases::forget($args[0]);
        return ['result' => 'sent', 'title' => $entry['title'], 'size' => (int) $entry['size']];
    }
}
