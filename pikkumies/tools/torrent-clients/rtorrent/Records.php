<?php
/**
 * RtorrentRecords: one d.multicall2 row of rTorrent as the shared torrent record
 * (tools/torrent-clients/README.md "Records"), with ruTorrent's reading of the state fields, and the
 * show, stats and traffic records built from single calls.
 */
class RtorrentRecords
{
    /** d.multicall2 columns, in the order row() reads them (command_download.cc and command_events.cc:273-303). */
    const FIELDS = ['d.hash=', 'd.name=', 'd.size_bytes=', 'd.completed_bytes=', 'd.complete=', 'd.state=', 'd.is_open=',
        'd.is_active=', 'd.hashing=', 'd.is_hash_checking=', 'd.ratio=', 'd.down.rate=', 'd.up.rate=', 'd.custom=addtime',
        'd.load_date=', 'd.custom1=', 'd.directory=', 'd.base_path=', 'd.is_multi_file=', 'd.message=', 'd.peers_connected=',
        'd.timestamp.finished=', 'd.is_private=', 'd.up.total='];

    /** The shared record (tools/torrent-clients/README.md "Records") from one d.multicall2 row. */
    public static function row(array $d)
    {
        $size = (int) $d['d.size_bytes='];
        $done = (int) $d['d.completed_bytes='];
        $dir = rtrim((string) $d['d.directory='], '/');
        $path = (string) $d['d.base_path='] !== '' ? (string) $d['d.base_path='] : ($dir === '' ? '' : ((int) $d['d.is_multi_file='] ? $dir : "$dir/" . $d['d.name=']));
        $added = (int) $d['d.custom=addtime']; // ruTorrent's seedingtime plugin stores it (PMSS etc/skel/www/rutorrent/plugins/seedingtime/init.php:6-7)
        return ['id' => strtolower((string) $d['d.hash=']), 'native' => (string) $d['d.hash='], 'name' => (string) $d['d.name='],
            'state' => self::state($d), 'progress' => $size > 0 ? round($done / $size, 4) : ((int) $d['d.complete='] ? 1 : 0),
            'size' => $size, 'done' => $done, 'ratio' => round((int) $d['d.ratio='] / 1000, 3), // d.ratio is in thousandths (command_download.cc:229-237)
            'down_rate' => (int) $d['d.down.rate='], 'up_rate' => (int) $d['d.up.rate='],
            'added_at' => $added > 0 ? $added : (int) $d['d.load_date='], 'label' => rawurldecode((string) $d['d.custom1=']), // ruTorrent URI-encodes labels (rtorrent.js:1100)
            'path' => $path, 'message' => (string) $d['d.message='],
            // finished: set once, when the download finishes or a hash check finds it complete (default hooks, main.cc:284-285),
            // 0 before. rTorrent keeps no seeding-time counter (no such command in 0.9.8), and "now minus finished" would
            // count stopped days as seeding, so null.
            'completed_at' => (int) $d['d.timestamp.finished='] > 0 ? (int) $d['d.timestamp.finished='] : null, 'seeding_time' => null,
            'private' => (bool) $d['d.is_private='], 'uploaded' => (int) $d['d.up.total=']];
    }

    /** ruTorrent's own reading of the same fields (PMSS etc/skel/www/rutorrent/js/rtorrent.js:1072-1083), mapped to the shared words. */
    public static function state(array $d)
    {
        if ((int) $d['d.hashing='] !== 0 || (int) $d['d.is_hash_checking='] !== 0) {
            return 'checking';
        }
        $msg = (string) $d['d.message='];
        if ($msg !== '' && $msg !== 'Tracker: [Tried all trackers.]') {
            return 'error';
        }
        if (!(int) $d['d.is_open='] || !(int) $d['d.state='] || !(int) $d['d.is_active=']) {
            return 'paused'; // closed, stopped, or started but not active
        }
        if ((int) $d['d.complete=']) {
            return 'seeding';
        }
        return ((int) $d['d.down.rate='] === 0 && (int) $d['d.peers_connected='] === 0) ? 'stalled' : 'downloading';
    }

    /** show: the list record plus details (command_download.cc); the trackers are `show --trackers` (in Extras.php). */
    public static function details(RtorrentClient $c, $native)
    {
        $r = $c->record($native);
        foreach (['created_at' => 'd.creation_date', 'started_at' => 'd.timestamp.started', 'finished_at' => 'd.timestamp.finished',
            'down_total' => 'd.down.total', 'up_total' => 'd.up.total', 'peers' => 'd.peers_connected', 'seeds' => 'd.peers_complete',
            'file_count' => 'd.size_files', 'private' => 'd.is_private', 'priority' => 'd.priority'] as $k => $m) {
            $r[$k] = $c->call($m, [$native]);
        }
        $r['private'] = (bool) $r['private'];
        return $r;
    }

    /** stats: versions, pid, uptime (PMSS rc only), listen port, the endpoint, counts by state, traffic. */
    public static function stats(RtorrentClient $c)
    {
        $now = (int) $c->call('system.time');
        $s = ['version' => $c->call('system.client_version'), 'library' => $c->call('system.library_version'),
            'pid' => (int) $c->call('system.pid'), 'uptime' => null, 'listen_port' => (int) $c->call('network.listen.port'),
            'endpoint' => str_replace($c->home, '~', preg_replace('#^(unix|tcp)://#', '', $c->address()))];
        try {
            $s['uptime'] = $now - (int) $c->call('system.startup_time'); // defined by the PMSS rc template (template.rtorrent.rc:55)
        } catch (CliError $e) {
            unset($s['uptime']); // not defined on this host's rc
        }
        $counts = array_fill_keys(Torrents::STATES, 0);
        foreach ($c->torrents() as $t) {
            $counts[$t['state']]++;
        }
        $s['torrents'] = array_sum($counts);
        $s['states'] = array_filter($counts);
        return $s + $c->traffic();
    }

    /** Rates, totals since start and limits (command_throttle.cc:208-215); limit 0 = none. */
    public static function traffic(RtorrentClient $c)
    {
        $t = [];
        foreach (['down_rate' => 'throttle.global_down.rate', 'up_rate' => 'throttle.global_up.rate', 'down_total' => 'throttle.global_down.total',
            'up_total' => 'throttle.global_up.total', 'down_limit' => 'throttle.global_down.max_rate', 'up_limit' => 'throttle.global_up.max_rate'] as $k => $m) {
            $t[$k] = (int) $c->call($m);
        }
        return $t;
    }
}
