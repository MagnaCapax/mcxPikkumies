<?php
/**
 * TransmissionRecords: the per-torrent field mapping for `list` (TransmissionClient::torrents(), split
 * out to keep that file within this category's line-length check) and the shared `files` path builder
 * used by both `files()` and `filesOf()`. Field names from rpc-spec.md on the 4.0.6 tag (see
 * TransmissionRpc.php for the full citation).
 */
class TransmissionRecords
{
    /** `status` 0..6 -> the shared state words (section 3.3). Overridden by `error` and `isStalled`:
     * neither has its own status number, both are separate tr_stat fields. */
    const STATUS = [0 => 'paused', 1 => 'queued', 2 => 'checking', 3 => 'queued', 4 => 'downloading', 5 => 'queued', 6 => 'seeding'];

    /** One `torrent-get` entry -> the shared `list`/`show` record (hash/native already extracted by the caller). */
    public static function one($hash, array $t, $name)
    {
        $error = (int) self::v($t, 'error');
        $status = (int) self::v($t, 'status');
        if ($error !== 0) {
            $state = 'error'; // tr_stat `error` nonzero: a tracker or local error, surfaced via errorString below
        } elseif (!empty($t['isStalled'])) {
            $state = 'stalled';
        } else {
            $state = isset(self::STATUS[$status]) ? self::STATUS[$status] : 'unknown';
        }
        $done = max(0, (int) self::v($t, 'sizeWhenDone') - (int) self::v($t, 'leftUntilDone'));
        $doneAt = (int) self::v($t, 'doneDate'); // 0 = not finished (tr_stat)
        return ['id' => strtolower($hash), 'native' => $hash, 'name' => $name, 'state' => $state,
            'progress' => round((float) self::v($t, 'percentDone'), 4), 'size' => (int) self::v($t, 'totalSize'),
            'done' => $done,
            'ratio' => max(0.0, round((float) self::v($t, 'uploadRatio'), 3)), // negative = not applicable/infinite; 0 is the honest floor
            'down_rate' => (int) self::v($t, 'rateDownload'), 'up_rate' => (int) self::v($t, 'rateUpload'),
            'added_at' => (int) self::v($t, 'addedDate'),
            'label' => implode(',', (array) (isset($t['labels']) ? $t['labels'] : [])),
            'path' => rtrim((string) self::v($t, 'downloadDir'), '/'),
            'message' => $error !== 0 ? (string) self::v($t, 'errorString') : '',
            'completed_at' => $doneAt > 0 ? $doneAt : null,
            'seeding_time' => (int) self::v($t, 'secondsSeeding'),
            'private' => (bool) self::v($t, 'isPrivate'),
            'uploaded' => (int) self::v($t, 'uploadedEver')];
    }

    /** $t's `files` (section 3.3, "files"), each `name` relative to `downloadDir`, as absolute paths. */
    public static function paths($native, array $t)
    {
        $root = rtrim((string) self::v($t, 'downloadDir'), '/');
        $out = [];
        foreach ((array) (isset($t['files']) ? $t['files'] : []) as $f) {
            $f = (array) $f;
            $name = isset($f['name']) ? (string) $f['name'] : '';
            if ($root === '' || $name === '' || strpos('/' . $name . '/', '/../') !== false) {
                throw new CliError("Transmission reported a file of $native without a usable path",
                    'nothing was changed; check the torrent in its own client');
            }
            $out[] = [$root . '/' . ltrim($name, '/'), isset($f['length']) ? (int) $f['length'] : 0];
        }
        return $out;
    }

    public static function v(array $s, $k)
    {
        return isset($s[$k]) ? $s[$k] : null;
    }

    /** One torrent-get call for every other torrent's files (TransmissionClient::filesOf, split out to
     * keep that file within this category's line-length check; same efficiency as Deluge's single
     * core.get_torrents_status call -- torrent-get takes an `ids` array of several torrents too). */
    public static function filesOf(TransmissionRpc $rpc, array $records)
    {
        if (!$records) {
            return [];
        }
        $args = $rpc->call('torrent-get', ['ids' => array_column($records, 'native'),
            'fields' => ['hashString', 'downloadDir', 'files']]);
        $byHash = [];
        foreach ((array) (isset($args['torrents']) ? $args['torrents'] : []) as $t) {
            $t = (array) $t;
            if (isset($t['hashString'])) {
                $byHash[(string) $t['hashString']] = $t;
            }
        }
        $out = [];
        foreach ($records as $r) {
            $n = (string) $r['native'];
            if (!isset($byHash[$n]) || !isset($byHash[$n]['files'])) {
                $out[$n] = null;
                continue;
            }
            try {
                $out[$n] = self::paths($n, $byHash[$n]);
            } catch (CliError $e) {
                $out[$n] = null;
            }
        }
        return $out;
    }
}
