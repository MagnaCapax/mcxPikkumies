<?php
/**
 * QbitStats: `stats` and `traffic`. Sources: transfer/info fields (qbt-wiki-5.md:1028-1086, bytes and
 * bytes/s, "this session" totals), app/version and app/webapiVersion (qbt-wiki-5.md:185-215;
 * appc.cpp:72-80), app/preferences listen_port and save_path (appc.cpp:158,206;
 * torrent_content_remove_option :354).
 */
class QbitStats
{
    private $c;

    public function __construct(QbittorrentClient $c)
    {
        $this->c = $c;
    }

    public function stats()
    {
        $api = $this->c->api->open();
        $prefs = (array) $api->json('app/preferences');
        $counts = array_fill_keys(Torrents::STATES, 0);
        $all = $this->c->torrents();
        foreach ($all as $t) {
            $counts[$t['state']]++;
        }
        $save = isset($prefs['save_path']) ? (string) $prefs['save_path'] : '';
        $proc = $this->c->restart->proc;
        $pids = $proc->pids();
        $r = ['client' => 'qbittorrent', 'version' => $api->text('app/version'), 'webapi' => $api->api,
            'pid' => $pids ? $pids[0] : null, 'uptime' => $pids ? $proc->uptime($pids[0]) : null, // null: not found, unknown
            'host' => $api->ep->family, 'webui_port' => $api->ep->port,
            'listen_port' => isset($prefs['listen_port']) ? (int) $prefs['listen_port'] : 0,
            'torrents' => count($all), 'by_state' => array_filter($counts),
            'save_path' => $save,
            'delete_mode' => isset($prefs['torrent_content_remove_option']) ? ($prefs['torrent_content_remove_option'] === 'MoveToTrash' ? 'trash' : 'delete') : 'delete'];
        if (!$api->localAuth) {
            $r['warning'] = 'the WebUI answers without a login from 127.0.0.1 (localhost bypass is on): any account on this server can control this client. Turn off "Bypass authentication for clients on localhost" in Tools > Options > WebUI.';
        }
        return $r + $this->traffic();
    }

    public function traffic()
    {
        $t = (array) $this->c->api->json('transfer/info');
        $n = function ($k) use ($t) {
            return isset($t[$k]) ? max(0, (int) $t[$k]) : 0; // limits: 0 = no limit
        };
        return ['down_rate' => $n('dl_info_speed'), 'up_rate' => $n('up_info_speed'), 'down_total' => $n('dl_info_data'), 'up_total' => $n('up_info_data'),
            'down_limit' => $n('dl_rate_limit'), 'up_limit' => $n('up_rate_limit'), 'totals_since' => 'client start',
            'connection' => isset($t['connection_status']) ? (string) $t['connection_status'] : '', 'dht_nodes' => $n('dht_nodes')];
    }
}
