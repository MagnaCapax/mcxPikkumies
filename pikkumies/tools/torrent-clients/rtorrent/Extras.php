<?php
/**
 * RtorrentExtras: the part of RtorrentClient behind pause and resume, recheck, reannounce, limit and `show --trackers`
 * (rTorrent 0.9.8 source; file:line in README.md). Every call goes through RtorrentClient::call, so only a socket or
 * port of this account is used.
 */
trait RtorrentExtras
{
    public function pause(array $natives)
    {
        return $this->toggle('d.stop', $natives); // ruTorrent's pause (rtorrent.js:407-414; command_download.cc:706)
    }

    public function resume(array $natives)
    {
        return $this->toggle('d.start', $natives); // ruTorrent's resume (rtorrent.js:417-424; command_download.cc:705)
    }

    public function recheck(array $natives)
    {
        $this->each('d.check_hash', $natives); // command_download.cc:732; ruTorrent rtorrent.js:329-337
    }

    public function reannounce(array $natives)
    {
        $this->each('d.tracker_announce', $natives); // the non-forced one (command_download.cc:875), as ruTorrent (rtorrent.js:397-405)
    }

    /**
     * throttle.global_{down,up}.max_rate.set with "" as the target and bytes/s, 0 = no limit (command_throttle.cc:211, 216;
     * PMSS sends the same shape, scripts/lib/tests/integration/checkRtorrentWatchdogTest.php:230-233). rTorrent keeps
     * whole KiB/s: set_*_throttle_i64 shifts the bytes right by 10 (root.h:88-89) and the throttle is set to that times
     * 1024 (root.cc:181, 211), so 1..1023 B/s would become 0, no limit: refused before anything is sent. The running
     * rTorrent changes only; the rc file does not.
     */
    public function setLimits($down, $up)
    {
        foreach (['--down' => $down, '--up' => $up] as $name => $v) {
            if ($v !== null && $v > 0 && $v < 1024) {
                throw new CliError("$name $v B/s: rTorrent keeps whole KiB/s and would read it as 0, no limit; nothing was changed",
                    'give at least 1024 (1KiB/s), or 0 for no limit');
            }
        }
        foreach (['down' => $down, 'up' => $up] as $dir => $v) {
            if ($v !== null) {
                $this->call("throttle.global_$dir.max_rate.set", ['', (int) $v]);
            }
        }
        $note = 'set in the running rTorrent only, in whole KiB/s: a restart brings back the limits of the rc file';
        if ($this->family === 'pmss') {
            // PMSS writes the account's upload cap into the rc file (template.rtorrent.rc:49, scripts/lib/rtorrent/configRender.php:15-18),
            // and its watchdog sets it again on every healthy check (scripts/lib/rtorrent/watchdogProcessFlow.php:150, watchdog.php:83-96).
            $note .= '. On PMSS an upload limit can be the account\'s upload cap: the rc file sets it at start and the PMSS'
                . ' watchdog sets it again, so an up limit set here can be replaced within minutes';
        }
        return ['persists' => false, 'note' => $note];
    }

    /**
     * t.multicall over the torrent's trackers (command_tracker.cc:115-155). rTorrent has no message per tracker (its text
     * is d.message, the torrent's `message`); the status comes from the success and failure counters and times:
     * disabled, not contacted (no answer and no failure yet), failing (the last event was a failure), working.
     * seeds/leechers are null until the tracker reported counts: libtorrent sets t.scrape_time_last whenever an announce or
     * scrape brings them (libtorrent 0.13.8 tracker_http.cc:338-341, tracker_udp.cc:388-390, tracker_list.cc:367), 0 before.
     */
    public function trackers($native)
    {
        $cols = ['t.url=', 't.group=', 't.is_enabled=', 't.success_counter=', 't.failed_counter=', 't.success_time_last=',
            't.failed_time_last=', 't.scrape_complete=', 't.scrape_incomplete=', 't.scrape_time_last='];
        $out = [];
        foreach ((array) $this->call('t.multicall', array_merge([$native, ''], $cols)) as $t) {
            if (!is_array($t) || count($t) !== count($cols)) {
                continue;
            }
            list($url, $group, $on, $ok, $bad, $okAt, $badAt, $seeds, $leechers, $scrapedAt) = $t;
            $status = (int) $badAt > (int) $okAt ? 'failing' : 'working';
            if (!$on || ((int) $ok === 0 && (int) $bad === 0)) {
                $status = !$on ? 'disabled' : 'not contacted';
            }
            $out[] = ['url' => (string) $url, 'tier' => (int) $group, 'status' => $status, 'message' => null,
                'seeds' => (int) $scrapedAt > 0 ? $seeds : null, 'leechers' => (int) $scrapedAt > 0 ? $leechers : null];
        }
        return $out;
    }

    /** One call per hash (d.check_hash, d.tracker_announce, d.stop, d.start), as ruTorrent sends them. */
    private function each($method, array $natives)
    {
        if (!$natives) {
            throw new CliError('no torrent selected', 'name at least one id');
        }
        foreach ($natives as $n) {
            $this->call($method, [$n]);
        }
    }

    /** pause/resume: $method on each id, then the states read back from a fresh list. */
    private function toggle($method, array $natives)
    {
        $this->each($method, $natives);
        $now = [];
        foreach ($this->torrents() as $r) {
            $now[$r['native']] = $r;
        }
        $out = [];
        foreach ($natives as $n) {
            $r = isset($now[$n]) ? $now[$n] : ['id' => strtolower($n), 'name' => '', 'state' => 'unknown'];
            $out[] = ['id' => $r['id'], 'name' => $r['name'], 'state' => $r['state']];
        }
        return $out;
    }
}
