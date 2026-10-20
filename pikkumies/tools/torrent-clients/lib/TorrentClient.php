<?php
/**
 * TorrentClient: what each torrent-client unit implements (rtorrent, qbittorrent, deluge, transmission).
 * Records follow tools/torrent-clients/README.md: `id` (40 hex, lower case) first, plus `native`,
 * the client's own id string (kept internal, used for every call back to the client).
 * Failures throw CliError with a hint the agent can act on; a client that does not answer in
 * time is "busy (unknown)", never "down".
 */
interface TorrentClient
{
    /**
     * Every torrent as a record: id, native, name, state, progress, size, done, ratio, down_rate, up_rate, added_at, label, path,
     * message, completed_at (unix time or null), seeding_time (seconds or null), private (bool or null), uploaded (bytes or null).
     * null means the client does not give that field.
     */
    public function torrents();

    /** Extra fields for one torrent (peers count, times...), merged over its list record. */
    public function details($native);

    /**
     * The torrent's files: list of [absolute path, bytes], plus a third element, the reason, for a file
     * the unit will not delete with the torrent (DeletePlan lists it as `skip` and leaves it out of the totals).
     */
    public function files($native);

    /**
     * The files of other torrents for the shared-file check (DeletePlan), in as few client calls as the client
     * allows: [native => list of [absolute path, bytes]], null for a torrent whose files could not be listed.
     */
    public function filesOf(array $records);

    /** Adds magnets, URLs or .torrent files; opt: paused (bool), dir (string|null). Returns one record per item: id, name, result. */
    public function add(array $items, array $opt);

    /**
     * Removes torrents; with $withData the client deletes the files too (or, for a client without
     * that command, the unit deletes exactly the torrent's own files). Returns one record per id:
     * id, name, disposal (kept, deleted, trashed, partial), errors (non-empty ends the call with ok:false).
     */
    public function remove(array $natives, $withData);

    /** One record per id: id, name, state after the call. */
    public function pause(array $natives);

    public function resume(array $natives);

    /** One record: version, pid, uptime, listen port, counts by state, plus the traffic fields (Torrents adds the quota). */
    public function stats();

    /** One record: down_rate, up_rate, down_total, up_total, down_limit, up_limit (bytes, bytes/s; 0 = no limit). */
    public function traffic();

    /** Restarts the client the way the provider does it and checks it answers. One record: how, answered (bool), version. */
    public function restart();

    /** Asks the client to hash-check the torrents' data again (deletes nothing; data that is gone downloads again). */
    public function recheck(array $natives);

    /** Asks the client to contact the torrents' trackers now. */
    public function reannounce(array $natives);

    /** Sets the global limits in bytes/s (null = leave it, 0 = no limit); returns extra record fields (persists, alt_speed...). */
    public function setLimits($down, $up);

    /**
     * The torrent's trackers: list of [url, tier, status (a TorrentTrackers::STATUS word or null), message, seeds, leechers],
     * null where the client gives no such value. The URL holds the passkey: only TorrentTrackers::host() may read it.
     */
    public function trackers($native);
}
