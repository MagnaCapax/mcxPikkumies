<?php
/**
 * MediaServer: what each media-server unit implements (jellyfin, emby, plex). The shared runner
 * (MediaServers.php) owns the rules every unit follows: which library or session an argument means,
 * "never all", viewer addresses only on request, bounded and redacted logs.
 * Failures throw CliError with a hint; a server that does not answer in time is "busy (unknown)".
 */
interface MediaServer
{
    /** One record (README.md of this category, `stats`). */
    public function stats();

    /** One record per library: id, name, type, paths, items (or null), scanning (bool), progress (0..1 or null). */
    public function libraries();

    /** Starts a scan of each library record given (from libraries()); one record each: id, name, result. */
    public function scan(array $libraries);

    /**
     * One record per playing stream: id, user, client, device, title, progress, paused, play (direct|transcode),
     * transcode_reasons, hardware, bitrate, remote, and `address` (the runner drops it unless --addresses).
     */
    public function sessions();

    /** Ends the playback of each session record given (from sessions()); one record each: id, result. */
    public function endStream(array $sessions, $reason);

    /**
     * The $limit most recently added items: title, type, library, added_at (unix time). Either that list, or
     * ['items' => the list, 'total' => the server's count of every recently added item, null when unknown].
     */
    public function recent($limit);

    /** ['file' => where the lines came from, 'lines' => the last $lines lines, raw]. */
    public function logs($lines);

    /** Restarts the server the way this host runs it and waits for an answer (MediaRestart). */
    public function restart();

    /** Literal credential values to remove from any text before it is printed. */
    public function secrets();
}
