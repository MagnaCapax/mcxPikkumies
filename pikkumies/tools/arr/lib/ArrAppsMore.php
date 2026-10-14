<?php
/**
 * ArrAppsMore: the per-app table of Lidarr and Readarr, read through ArrApps::get() like the others.
 * Paths and every boolean query parameter come from the OpenAPI files at the commits named in the
 * lidarr and readarr READMEs. Keys the first three apps do not have:
 * - titleField: the item's name field (the artist or author has no `title`);
 * - counts: record field => statistics field, printed by `list`;
 * - wantedRow: wanted/missing returns albums or books, not library items;
 * - installer: false when the PMSS media-stack installer does not manage the app (no ~/.config/<app>
 *   lowercase folder, no --stop-app/--start-app: install-media-stack.sh:258-263);
 * - metadataProfile: `add` also needs a metadataProfileId (Lidarr ArtistController.cs:80,94);
 * - readOnly: only the read verbs (Readarr: upstream archived the project).
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
    ];
}
