<?php
/**
 * ArrReleases: the handle cache shared by `releases`/`grab` on Prowlarr, Sonarr and Radarr
 * (docs/adr/0046). A handle is 6 random lowercase hex characters, never derived from the guid or
 * title, so printing one teaches nothing about the release's indexer link. The cache lives in
 * local/secrets/releases.json: some indexers put the tracker passkey in the guid or download link,
 * and Prowlarr's own download link carries its apikey, so the agent's shell guard blocks commands
 * on local/secrets (pikkumies/hooks/guard/guard-destructive) the same as every other stored secret.
 * save() replaces the whole file (one search's results at a time); take()/forget() never rewrite it
 * on a mere read, only forget() after a successful grab removes the one entry that was used.
 */
class ArrReleases
{
    // Prowlarr and Sonarr/Radarr cache a release for 30 minutes before a grab 404s (docs/adr/0046):
    // Prowlarr SearchController.cs MapReleases -> _remoteReleaseCache.Set(..., TimeSpan.FromMinutes(30));
    // Sonarr/Radarr ReleaseController(Base).MapDecision -> _remoteEpisodeCache/_remoteMovieCache.Set(..., TimeSpan.FromMinutes(30)).
    const TTL = 1800;
    const FIELDS = ['app', 'indexerId', 'indexer', 'guid', 'downloadUrl', 'magnetUrl', 'title', 'size', 'seeders', 'protocol'];

    /** Test seam (in-process tests); PIKKUMIES_ARR_RELEASES_FILE is the same seam across a proc_open child. */
    public static $file = null;

    private static function file()
    {
        if (self::$file !== null) {
            return self::$file;
        }
        $env = getenv('PIKKUMIES_ARR_RELEASES_FILE');
        return $env !== false && $env !== '' ? $env : Config::localDir() . '/secrets/releases.json';
    }

    /**
     * Stores $rows (replacing the previous set) under app $app, each with a fresh random handle.
     * Returns the same rows in the same order with a `handle` column and no guid/downloadUrl/magnetUrl,
     * so a caller can print the result straight from what this returns.
     */
    public static function save(array $rows, $app)
    {
        $entries = [];
        $out = [];
        foreach ($rows as $r) {
            do {
                $handle = bin2hex(random_bytes(3)); // 6 lowercase hex characters
            } while (isset($entries[$handle]));
            $entries[$handle] = array_intersect_key($r, array_flip(self::FIELDS)) + ['app' => $app, 'savedAt' => time()];
            $row = $r;
            unset($row['guid'], $row['downloadUrl'], $row['magnetUrl']);
            $out[] = ['handle' => $handle] + $row;
        }
        SecretFile::write(self::file(), $entries, 'release cache');
        return $out;
    }

    /** The stored release for $handle if it belongs to $app and is under 30 minutes old; else a CliError. */
    public static function take($handle, $app)
    {
        $entry = self::fresh($handle);
        if ($entry === null) {
            throw new CliError('no such result; search again', 'the handle is wrong, or more than 30 minutes old');
        }
        if ($entry['app'] !== $app) {
            throw new CliError("that handle is from $entry[app], not $app", "run grab on $entry[app], or search again here");
        }
        return $entry + array_fill_keys(self::FIELDS, '');
    }

    /** Removes one entry after its grab has succeeded; never throws (the handle is already known good). */
    public static function forget($handle)
    {
        try {
            $all = self::read();
            unset($all[$handle]);
            SecretFile::write(self::file(), $all, 'release cache');
        } catch (CliError $e) {
            // The release was already sent; a failed cleanup must not read as a failed grab (it would invite a second one).
        }
    }

    /** $handle's entry, or null when it is missing or older than TTL (an expired entry is treated as gone). */
    private static function fresh($handle)
    {
        $all = self::read();
        $e = isset($all[$handle]) && is_array($all[$handle]) ? $all[$handle] : null;
        return $e !== null && isset($e['savedAt']) && time() - (int) $e['savedAt'] < self::TTL ? $e : null;
    }

    private static function read()
    {
        $raw = SecretFile::read(self::file(), 'run `releases` again');
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) ? $d : [];
    }
}
