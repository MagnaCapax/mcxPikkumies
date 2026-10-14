<?php
/**
 * ArrApps: the per-app table every Servarr unit reads (tools/arr/README.md); Lidarr and Readarr are in
 * ArrAppsMore. Paths, parameter names and
 * command names come from the upstream OpenAPI specs and command classes at the commits named in
 * each unit's README.md. Every boolean query parameter an endpoint declares is listed here and sent
 * explicitly, so no API default is ever inherited (queue removal's removeFromClient=true deletes data).
 */
class ArrApps
{
    const APPS = [
        'sonarr' => [
            'name' => 'Sonarr', 'api' => 'v3', 'kind' => 'library', 'docs' => 'TV series',
            'item' => 'series', 'ref' => 'seriesId',
            // Lookup prefixes Sonarr resolves itself (SkyHookProxy.cs:113); the metadata field each one matches.
            'prefixes' => ['tvdb' => 'tvdbId'], 'exists' => 'tvdbId',
            'listBools' => ['includeSeasonImages' => false],
            'deleteBools' => ['deleteFiles' => false, 'addImportListExclusion' => false],
            'queueBools' => ['includeUnknownSeriesItems' => false, 'includeSeries' => false, 'includeEpisode' => false],
            'wantedBools' => ['includeSeries' => false, 'includeImages' => false, 'monitored' => true],
            'cutoffBools' => ['includeSeries' => false, 'includeEpisodeFile' => false, 'includeImages' => false, 'monitored' => true],
            'historyBools' => ['includeSeries' => false, 'includeEpisode' => false],
            // SeriesSearchCommand.cs:7 takes one SeriesId: one command per series.
            'search' => ['name' => 'SeriesSearch', 'field' => 'seriesId', 'list' => false],
            'searchOption' => 'searchForMissingEpisodes',
            'addOptions' => ['monitor' => 'all', 'searchForCutoffUnmetEpisodes' => false, 'ignoreEpisodesWithFiles' => false, 'ignoreEpisodesWithoutFiles' => false],
            'addFields' => ['monitored' => true, 'seasonFolder' => true],
            'commands' => ['SeriesSearch'],
        ],
        'radarr' => [
            'name' => 'Radarr', 'api' => 'v3', 'kind' => 'library', 'docs' => 'movies',
            'item' => 'movie', 'ref' => 'movieId',
            // SkyHookProxy.cs:473,495 (imdb:, tmdb:).
            'prefixes' => ['tmdb' => 'tmdbId', 'imdb' => 'imdbId'], 'exists' => 'tmdbId',
            'listBools' => ['excludeLocalCovers' => false],
            'deleteBools' => ['deleteFiles' => false, 'addImportExclusion' => false],
            'queueBools' => ['includeUnknownMovieItems' => false, 'includeMovie' => false],
            'wantedBools' => ['monitored' => true],
            'cutoffBools' => ['monitored' => true],
            'historyBools' => ['includeMovie' => false],
            // MoviesSearchCommand.cs:8 takes a list of MovieIds: one command for the batch.
            'search' => ['name' => 'MoviesSearch', 'field' => 'movieIds', 'list' => true],
            'searchOption' => 'searchForMovie',
            'addOptions' => ['monitor' => 'movieOnly', 'addMethod' => 'manual', 'ignoreEpisodesWithFiles' => false, 'ignoreEpisodesWithoutFiles' => false],
            'addFields' => ['monitored' => true],
            'commands' => ['MoviesSearch'],
        ],
        'prowlarr' => [
            'name' => 'Prowlarr', 'api' => 'v1', 'kind' => 'indexer', 'docs' => 'indexers',
            'item' => 'indexer', 'ref' => 'indexerId',
            'historyBools' => [],
            // ApplicationIndexerSyncCommand.cs:7 ForceSync, sent as false.
            'sync' => ['name' => 'ApplicationIndexerSync', 'body' => ['forceSync' => false]],
            'commands' => ['ApplicationIndexerSync'],
        ],
    ];

    /** Commands no unit may ever send, whatever a table says (research §3d; tools/arr/README.md "Safety"). */
    const NEVER = '/^(ApplicationUpdate|ApplicationUpdateCheck|ApplicationCheckUpdate|ResetApiKey|Shutdown|Restart|DeleteSeriesFiles|ClearLog|DeleteLogFiles|DeleteUpdateLogFiles|ClearBlocklist|Rename.*|Move.*|BulkMove.*)$/i';

    public static function get($app)
    {
        $all = self::APPS + ArrAppsMore::APPS;
        if (!isset($all[$app])) {
            throw new CliError("unknown *ARR app: $app", 'apps: ' . implode(', ', array_keys($all)));
        }
        return $all[$app] + ['id' => $app];
    }

    /**
     * "tmdb:603" -> ['tmdb', '603'] when the app takes that prefix, else null. imdb ids keep their tt.
     * mbid: takes a MusicBrainz id (a UUID), lower-cased.
     */
    public static function parseMetaId(array $app, $s)
    {
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
        if (!preg_match('/^(tvdb|tmdb|imdb):(tt[0-9]+|[0-9]+)$/i', trim((string) $s), $m)
            && !preg_match('/^(mbid):(' . $uuid . ')$/i', trim((string) $s), $m)) {
            return null;
        }
        $prefix = strtolower($m[1]);
        $value = strtolower($m[2]);
        if (!isset($app['prefixes'][$prefix]) || (($prefix === 'imdb') !== (strpos($value, 'tt') === 0))) {
            return null;
        }
        return [$prefix, $value];
    }

    /** An item's title: `title`, or the app's own name field (Lidarr artistName, Readarr authorName). */
    public static function title(array $app, array $item)
    {
        return ArrRecords::s($item, isset($app['titleField']) ? $app['titleField'] : 'title');
    }

    /** The prefixed metadata id of a library or lookup item: tvdb:81189, tmdb:603. */
    public static function metaId(array $app, array $item)
    {
        foreach ($app['prefixes'] as $prefix => $field) {
            if (!empty($item[$field])) {
                return $prefix . ':' . $item[$field];
            }
        }
        return '';
    }
}
