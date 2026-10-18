<?php
/**
 * ArrGrab: Prowlarr's `grab <handle> [--client ...] [--via-prowlarr]` (docs/adr/0046 §2).
 * Path A posts the release to Prowlarr's own configured download client, the route its own UI uses
 * (SearchController.cs GrabRelease: POST search {indexerId, guid}; 404 the handle's cache expired
 * inside Prowlarr too, 409 the indexer fetch itself failed). Path B is the common PMSS case, where
 * Prowlarr has no download client of its own (Sonarr/Radarr hold that configuration instead): it
 * fetches the release itself, pinned to this account's own Prowlarr (the link carries its apikey),
 * and hands it to the user's own torrent client. Prowlarr's download proxy
 * (NewznabController.cs GetDownload, reached at GET {urlBase}/{indexerId}/download?apikey=&link=&file=,
 * the same path ArrClient::redact() already treats as this account's own port) answers a magnet
 * release with a 301 redirect whose Location is the literal magnet link (no bytes are fetched), and
 * a torrent release with the file itself, 200, `application/x-bittorrent`.
 */
class ArrGrab
{
    const MAX_FETCH = 10485760; // 10 MiB (docs/adr/0046 §2)

    /** $account: [home, uid] (Arr::account()). */
    public static function grab(ArrClient $c, array $account, array $args, array $opt)
    {
        if (count($args) !== 1) {
            throw new CliError('grab needs exactly one handle', 'grab <handle> (the handle `releases` printed)');
        }
        if (isset($opt['client']) && !empty($opt['via-prowlarr'])) {
            throw new CliError('grab takes --client or --via-prowlarr, not both', 'pick one path');
        }
        list($home, $uid) = $account;
        if ($home === '') {
            throw new CliError('no home folder of this user found (HOME, or the passwd entry)', 'run the tool as the seedbox user');
        }
        $entry = ArrReleases::take($args[0], 'prowlarr');
        if (isset($opt['client']) && !is_string($opt['client'])) {
            throw new CliError('--client needs a name', '--client rtorrent|qbittorrent|deluge');
        }
        $wantClient = isset($opt['client']) ? $opt['client'] : null;
        $viaProwlarr = !empty($opt['via-prowlarr']) || ($wantClient === null && self::prowlarrHasClient($c));
        $r = $viaProwlarr ? self::viaProwlarr($c, $entry) : self::inProcess($c, $entry, $wantClient, $home, $uid);
        ArrReleases::forget($args[0]);
        return $r;
    }

    private static function prowlarrHasClient(ArrClient $c)
    {
        foreach ((array) $c->call('GET', 'downloadclient') as $row) {
            if (is_array($row) && !empty($row['enable'])) {
                return true;
            }
        }
        return false;
    }

    private static function viaProwlarr(ArrClient $c, array $entry)
    {
        $c->call('POST', 'search', [], ['indexerId' => (int) $entry['indexerId'], 'guid' => $entry['guid']],
            ['missing' => 'no such result; search again']); // 404; a 409 (indexer fetch failed) falls into ArrClient's generic >=400 branch
        return ['result' => 'sent', 'path' => 'prowlarr client', 'title' => $entry['title'], 'size' => (int) $entry['size']];
    }

    private static function inProcess(ArrClient $c, array $entry, $wantClient, $home, $uid)
    {
        $id = ArrGrabClient::pick($wantClient, $home, $uid);
        $client = ArrGrabClient::make($id, $home, $uid);
        $item = self::resolve($c, $entry);
        $file = isset($item['file']) ? $item['file'] : null;
        try {
            self::addOne($client, $file !== null ? $file : $item['magnet']);
        } finally {
            if ($file !== null) {
                @unlink($file);
            }
        }
        return ['result' => 'added', 'path' => $id, 'title' => $entry['title'], 'size' => (int) $entry['size']];
    }

    /** ['magnet' => uri] or ['file' => path]: the stored magnetUrl itself, or the fetched downloadUrl. */
    private static function resolve(ArrClient $c, array $entry)
    {
        $magnet = (string) $entry['magnetUrl'];
        if (strpos($magnet, 'magnet:?') === 0) {
            return ['magnet' => $magnet];
        }
        // A magnet-only release: Prowlarr stores its own proxy link in magnetUrl, fetched through the same pin.
        $url = (string) $entry['downloadUrl'] !== '' ? (string) $entry['downloadUrl'] : $magnet;
        if ($url === '') {
            throw new CliError('this release has no download link stored', 'search again');
        }
        return self::fetch($c, $url);
    }

    private static function fetch(ArrClient $c, $url)
    {
        if (!self::pinned($c, $url)) {
            throw new CliError("refusing to fetch a release link that is not this account's own Prowlarr", 'search again; the stored link looks wrong');
        }
        $r = Http::request('GET', $url, ['timeout' => 60, 'max' => self::MAX_FETCH]);
        if (in_array($r['code'], [301, 302, 307, 308], true)) {
            $loc = isset($r['headers']['location'][0]) ? (string) $r['headers']['location'][0] : '';
            if (strpos($loc, 'magnet:?') === 0) {
                return ['magnet' => $loc];
            }
            throw new CliError('Prowlarr redirected to something that is not a magnet link; not following it', 'try --via-prowlarr instead');
        }
        if ($r['code'] !== 200 || $r['error'] !== '') {
            throw new CliError('could not fetch the release from Prowlarr (' . ($r['error'] !== '' ? $r['error'] : "HTTP {$r['code']}") . ')', 'try again, or --via-prowlarr');
        }
        if (!isset(Bencode::dict($r['body'], 0)['info'])) {
            throw new CliError('Prowlarr did not return a valid .torrent file', 'try again, or --via-prowlarr');
        }
        return ['file' => self::tempFile($r['body'])];
    }

    /** $url's scheme, host and port match this account's own, configured Prowlarr (the link carries its apikey). */
    private static function pinned(ArrClient $c, $url)
    {
        $u = parse_url((string) $url);
        return is_array($u) && isset($u['scheme'], $u['host'], $u['port'])
            && strtolower($u['scheme']) === 'http' && in_array(strtolower($u['host']), ['127.0.0.1', 'localhost'], true)
            && (int) $u['port'] === (int) $c->where()['port'];
    }

    private static function tempFile($data)
    {
        $env = getenv('PIKKUMIES_ARR_TMP_DIR'); // test seam: never this install's own local/tmp/
        $dir = $env !== false && $env !== '' ? $env : Config::localDir() . '/tmp';
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new CliError("cannot create $dir", 'check that pikkumies/local is writable by this account');
        }
        @chmod($dir, 0700);
        $file = $dir . '/grab' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.torrent';
        $old = umask(0077);
        $ok = @file_put_contents($file, $data) !== false;
        umask($old);
        if (!$ok || !@chmod($file, 0600)) {
            @unlink($file);
            throw new CliError('cannot write the downloaded release to a temp file', "check that $dir is writable");
        }
        return $file;
    }

    /** Adds $item (a magnet or a .torrent path) and refuses only when the client itself reports an error. */
    private static function addOne(TorrentClient $client, $item)
    {
        $r = $client->add([$item], ['paused' => false, 'dir' => null]);
        $rows = isset($r['records']) ? $r['records'] : $r;
        $row = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];
        $result = isset($row['result']) ? (string) $row['result'] : '';
        if (strncmp($result, 'error', 5) === 0) {
            throw new CliError("the torrent client refused the release: $result", 'try a different --client, or --via-prowlarr');
        }
    }
}
