<?php
/**
 * QbittorrentClient: the shared torrent verbs (tools/torrent-clients/README.md) over the WebUI API v2.
 * Field names and states: qbt-wiki-5.md "Get torrent list" (:1202-1338); 5.0 renamed pausedUP/pausedDL
 * to stoppedUP/stoppedDL (research §2.3). Deleting: torrents/delete with deleteFiles (tc-5.0.0.cpp:1099-1110);
 * qBittorrent 5 moves the files to the trash unless torrent_content_remove_option says otherwise
 * (appc.cpp:354, sessionimpl-5.0.0.cpp:529 default MoveToTrash, tcr.cpp:42).
 */
class QbittorrentClient implements TorrentClient
{
    use QbitExtras; // recheck, reannounce, limits, trackers, the newer list fields

    const STATES = [
        'downloading' => 'downloading', 'forcedDL' => 'downloading', 'metaDL' => 'downloading', 'forcedMetaDL' => 'downloading', 'allocating' => 'downloading',
        'uploading' => 'seeding', 'forcedUP' => 'seeding', 'stalledUP' => 'seeding',
        'pausedUP' => 'paused', 'pausedDL' => 'paused', 'stoppedUP' => 'paused', 'stoppedDL' => 'paused',
        'checkingUP' => 'checking', 'checkingDL' => 'checking', 'checkingResumeData' => 'checking',
        'queuedUP' => 'queued', 'queuedDL' => 'queued', 'stalledDL' => 'stalled', 'error' => 'error', 'missingFiles' => 'error',
    ];

    public $api;
    public $restart;
    /** Rows per torrents/info page: a few KiB each, so a page stays far below lib/Http.php's 5 MiB answer limit. */
    public $page = 500;

    public function __construct(QbitApi $api, QbitRestart $restart)
    {
        $this->api = $api;
        $this->restart = $restart;
    }

    /**
     * The client's own rows (torrents/info), optionally only the given hashes. The whole list is read
     * in pages sorted by hash (sort, limit, offset: qbt-wiki-5.md:1213-1216); a row seen twice counts once.
     */
    public function rows(array $natives = [])
    {
        if ($natives) {
            return (array) $this->api->json('torrents/info', ['hashes' => self::hashes($natives)]);
        }
        $rows = [];
        $size = max(1, (int) $this->page);
        for ($offset = 0; ; $offset += $size) {
            $page = (array) $this->api->json('torrents/info', ['sort' => 'hash', 'limit' => $size, 'offset' => $offset]);
            $before = count($rows);
            foreach ($page as $r) {
                $rows[isset($r['hash']) ? (string) $r['hash'] : count($rows)] = $r;
            }
            if (count($page) < $size || count($rows) === $before) {
                return array_values($rows); // the last page, or a client that ignores offset
            }
        }
    }

    public function torrents(array $natives = [])
    {
        return array_map([__CLASS__, 'record'], $this->rows($natives));
    }

    public static function record(array $t)
    {
        $g = function ($k) use ($t) {
            return isset($t[$k]) ? $t[$k] : null;
        };
        $raw = (string) $g('state');
        return ['id' => strtolower((string) $g('hash')), 'native' => (string) $g('hash'), 'name' => (string) $g('name'),
            'state' => array_key_exists($raw, self::STATES) ? self::STATES[$raw] : 'unknown', 'progress' => (float) $g('progress'),
            'size' => (int) $g('size'), 'done' => (int) $g('completed'), 'ratio' => round((float) $g('ratio'), 3),
            'down_rate' => (int) $g('dlspeed'), 'up_rate' => (int) $g('upspeed'), 'added_at' => (int) $g('added_on'),
            'label' => (string) $g('category'), 'path' => (string) ($g('content_path') ?: $g('save_path')),
            'message' => $raw === 'missingFiles' ? 'files missing' : ($raw === 'error' ? 'error (see the qBittorrent log)' : '')]
            + self::listFields($t);
    }

    public function details($native)
    {
        return QbitDetails::details($this, $native);
    }

    public function files($native)
    {
        return QbitDetails::files($this, $native);
    }

    public function filesOf(array $records)
    {
        return QbitDetails::many($this, $records);
    }

    public function add(array $items, array $opt)
    {
        return (new QbitAdd($this))->add($items, $opt);
    }

    public function remove(array $natives, $withData)
    {
        return (new QbitChange($this))->remove($natives, $withData);
    }

    public function pause(array $natives)
    {
        return (new QbitChange($this))->toggle($natives, true);
    }

    public function resume(array $natives)
    {
        return (new QbitChange($this))->toggle($natives, false);
    }

    public function stats()
    {
        return (new QbitStats($this))->stats();
    }

    public function traffic()
    {
        return (new QbitStats($this))->traffic();
    }

    public function restart()
    {
        return $this->restart->run($this->api);
    }

    public function byNative(array $records)
    {
        $out = [];
        foreach ($records as $r) {
            $out[$r['native']] = $r;
        }
        return $out;
    }

    /** "H1|H2", checked: never "all" and never empty (README: the client's "all" value is never sent). */
    public static function hashes(array $natives)
    {
        if (!$natives || count(preg_grep('/^[0-9a-fA-F]{40}$/', $natives)) !== count($natives)) {
            throw new CliError('refusing to send an empty id list or an id that is not 40 hex characters', 'ids come from `list`');
        }
        return implode('|', $natives);
    }
}
