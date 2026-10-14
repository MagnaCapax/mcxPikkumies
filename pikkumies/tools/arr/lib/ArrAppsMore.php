<?php
/**
 * ArrAppsMore: the per-app table of Lidarr, Readarr and Whisparr (v3 and v2 rows), read through ArrApps::get() like the others.
 * Paths and every boolean query parameter come from the OpenAPI files at the commits named in the
 * lidarr, readarr and whisparr READMEs. Keys the first three apps do not have:
 * - titleField: the item's name field (the artist or author has no `title`);
 * - counts: record field => statistics field, printed by `list`;
 * - wantedRow: wanted/missing returns albums or books, not library items;
 * - installer: false when the PMSS media-stack installer does not manage the app (no ~/.config/<app>
 *   lowercase folder, no --stop-app/--start-app: install-media-stack.sh:258-263);
 * - metadataProfile: `add` also needs a metadataProfileId (Lidarr ArtistController.cs:80,94);
 * - readOnly: only the read verbs (Readarr: upstream archived the project);
 * - noAdd: every library verb but lookup and add (Whisparr: whisparr/README.md says why);
 * - id: the unit name when the row key differs (whisparr2 is Whisparr v2, still the `whisparr` unit).
 */
class ArrAppsMore
{
    const APPS = [
        'lidarr' => [
            'name' => 'Lidarr', 'api' => 'v1', 'kind' => 'library', 'docs' => 'music artists', 'installer' => false,
            'item' => 'artist', 'ref' => 'artistId', 'titleField' => 'artistName',
            // SkyHookProxy.cs:433-436 resolves lidarr:, lidarrid: and mbid: (a MusicBrainz id) itself; mbid: is the one used here.
            'prefixes' => ['mbid' => 'foreignArtistId'], 'exists' => 'foreignArtistId',
            'metaHint' => 'mbid: ids are for `lookup` and `add`',
            'example' => 'lookup "artist name"   or   lookup mbid:<MusicBrainz artist id>',
            'counts' => ['albums' => 'albumCount', 'tracks' => 'trackFileCount', 'tracks_total' => 'trackCount'],
            'listBools' => [],
            'deleteBools' => ['deleteFiles' => false, 'addImportListExclusion' => false],
            'queueBools' => ['includeUnknownArtistItems' => false, 'includeArtist' => false, 'includeAlbum' => false],
            'wantedBools' => ['includeArtist' => false, 'monitored' => true],
            'cutoffBools' => ['includeArtist' => false, 'monitored' => true],
            'historyBools' => ['includeArtist' => false, 'includeAlbum' => false, 'includeTrack' => false],
            'wantedRow' => ['ref' => 'artistId', 'date' => 'releaseDate'],
            // ArtistSearchCommand.cs:7 takes one ArtistId: one command per artist.
            'search' => ['name' => 'ArtistSearch', 'field' => 'artistId', 'list' => false],
            'searchOption' => 'searchForMissingAlbums',
            'addOptions' => ['monitor' => 'all'],
            'addFields' => ['monitored' => true],
            'metadataProfile' => true,
            'commands' => ['ArtistSearch'],
        ],
        'readarr' => [
            'name' => 'Readarr', 'api' => 'v1', 'kind' => 'library', 'docs' => 'book authors', 'installer' => false,
            'readOnly' => true,
            'item' => 'author', 'ref' => 'authorId', 'titleField' => 'authorName',
            'prefixes' => [], 'exists' => 'foreignAuthorId',
            'metaHint' => 'Readarr ids are the numbers `list` prints',
            'counts' => ['books' => 'bookFileCount', 'books_total' => 'bookCount'],
            'listBools' => [],
            'queueBools' => ['includeUnknownAuthorItems' => false, 'includeAuthor' => false, 'includeBook' => false],
            'wantedBools' => ['includeAuthor' => false, 'monitored' => true],
            'cutoffBools' => ['includeAuthor' => false, 'monitored' => true],
            'historyBools' => ['includeAuthor' => false, 'includeBook' => false],
            'wantedRow' => ['ref' => 'authorId', 'date' => 'releaseDate'],
            'commands' => [],
        ],
        // Whisparr (whisparr/README.md): one unit, two upstream majors, both under /api/v3; the row is picked from
        // system/status `version` when a verb runs (whisparr/WhisparrRun.php). Both: no lookup/add (noAdd).
        // v3 (branch eros, Radarr-based): movies and scenes. Booleans: MovieController.cs:418, Wanted/*Controller.cs:40,44.
        'whisparr' => [
            'id' => 'whisparr', 'name' => 'Whisparr', 'api' => 'v3', 'kind' => 'library', 'docs' => 'movies and scenes',
            'installer' => false, 'noAdd' => true, 'item' => 'movie', 'ref' => 'movieId',
            'prefixes' => ['stash' => 'stashId', 'tmdb' => 'tmdbId'], 'exists' => 'stashId', // shown as meta_id only
            'metaHint' => 'Whisparr ids are the numbers `list` prints',
            'listBools' => ['excludeLocalCovers' => false],
            'deleteBools' => ['deleteFiles' => false, 'addImportExclusion' => false],
            'queueBools' => ['includeUnknownMovieItems' => false, 'includeMovie' => false],
            'wantedBools' => ['monitored' => true], 'cutoffBools' => ['monitored' => true],
            'historyBools' => ['includeMovie' => false],
            'search' => ['name' => 'MoviesSearch', 'field' => 'movieIds', 'list' => true], // MoviesSearchCommand.cs:8
            'commands' => ['MoviesSearch'],
        ],
        // v2 (branch v2-develop, Sonarr-based): sites (series) and their scenes (episodes); tvdbId holds the TPDB site id.
        'whisparr2' => [
            'id' => 'whisparr', 'name' => 'Whisparr', 'api' => 'v3', 'kind' => 'library', 'docs' => 'sites and scenes',
            'installer' => false, 'noAdd' => true, 'item' => 'series', 'ref' => 'seriesId',
            'prefixes' => ['tpdb' => 'tvdbId'], 'exists' => 'tvdbId', // SkyHookProxy.cs:100-117: tpdb:<n> is the tvdbId
            'metaHint' => 'Whisparr ids are the numbers `list` prints',
            'counts' => ['episodes' => 'episodeFileCount', 'episodes_total' => 'episodeCount'],
            'listBools' => ['includeSeasonImages' => false],
            'deleteBools' => ['deleteFiles' => false, 'addImportListExclusion' => false],
            'queueBools' => ['includeUnknownSeriesItems' => false, 'includeSeries' => false, 'includeEpisode' => false],
            'wantedBools' => ['includeSeries' => false, 'includeImages' => false, 'monitored' => true],
            'cutoffBools' => ['includeSeries' => false, 'includeEpisodeFile' => false, 'includeImages' => false, 'monitored' => true],
            'historyBools' => ['includeSeries' => false, 'includeEpisode' => false],
            'wantedRow' => ['ref' => 'seriesId', 'date' => 'releaseDate'],
            'search' => ['name' => 'SeriesSearch', 'field' => 'seriesId', 'list' => false], // SeriesSearchCommand.cs:7
            'commands' => ['SeriesSearch'],
        ],
    ];
}
