<?php
/**
 * QbitChange: remove, pause and resume, each read back from the torrent list afterwards.
 * torrents/delete takes hashes and deleteFiles (tc-5.0.0.cpp:1099-1110); with deleteFiles qBittorrent 5
 * moves the files to the trash when torrent_content_remove_option is MoveToTrash, its default
 * (appc.cpp:354, sessionimpl-5.0.0.cpp:529, tcr.cpp:42), and deletes them otherwise.
 * pause/resume became stop/start in API 2.11 (tc-5.0.0.cpp:928-942; research torrent-clients.md §2.3).
 */
class QbitChange
{
    private $c;

    public function __construct(QbittorrentClient $c)
    {
        $this->c = $c;
    }

    public function remove(array $natives, $withData)
    {
        $disposal = 'kept';
        if ($withData) {
            $disposal = $this->c->disposal();
        }
        $before = $this->c->byNative($this->c->torrents($natives));
        $this->c->api->post('torrents/delete', ['hashes' => QbittorrentClient::hashes($natives), 'deleteFiles' => $withData ? 'true' : 'false']);
        $left = $this->settle($natives, function () {
            return false; // done when none of them is listed any more
        });
        $out = [];
        foreach ($natives as $n) {
            $r = ['id' => strtolower($n), 'name' => isset($before[$n]) ? $before[$n]['name'] : '', 'disposal' => isset($left[$n]) ? 'kept' : $disposal, 'errors' => []];
            if (isset($left[$n])) {
                $r['errors'][] = 'still in the client after the delete request';
            } elseif ($disposal === 'trashed') {
                $r['note'] = 'qBittorrent moved the files to the trash: no disk space is freed until the trash is emptied';
            }
            $out[] = $r;
        }
        return $out;
    }

    public function toggle(array $natives, $pause)
    {
        $v5 = $this->c->api->open()->stopped();
        $path = $pause ? ($v5 ? 'torrents/stop' : 'torrents/pause') : ($v5 ? 'torrents/start' : 'torrents/resume');
        $this->c->api->post($path, ['hashes' => QbittorrentClient::hashes($natives)]);
        $isDone = function ($r) use ($pause) {
            return ($r['state'] === 'paused') === $pause;
        };
        $now = $this->settle($natives, $isDone);
        $out = [];
        foreach ($natives as $n) {
            $r = isset($now[$n]) ? $now[$n] : null;
            $out[] = ['id' => strtolower($n), 'name' => $r ? $r['name'] : '', 'state' => $r ? $r['state'] : 'gone',
                'result' => ($r && $isDone($r)) ? ($pause ? 'paused' : 'resumed') : 'not changed yet'];
        }
        return ['records' => $out, 'status' => ['verified' => count(array_filter($now, $isDone)) === count($natives)]];
    }

    /** Polls the given torrents (up to 5 s) until $done holds for every one listed; returns them by native id. */
    public function settle(array $natives, callable $done)
    {
        for ($i = 0; $i < 25; $i++) {
            $now = $this->c->byNative($this->c->torrents($natives));
            if (count(array_filter($now, $done)) === count($now)) {
                break;
            }
            usleep(200000);
        }
        return $now;
    }
}
