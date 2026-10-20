<?php
/**
 * TransmissionExtras: `show`'s extra fields, recheck, reannounce, speed limits and `show --trackers`,
 * through the connected TransmissionRpc. Sources: rpc-spec.md on the 4.0.6 tag (fetched 2026-10-20;
 * see TransmissionRpc for the full citation and why the pre-4.1 protocol is the one used).
 */
trait TransmissionExtras
{
    /**
     * `peers` has no single "connected seeds" count of its own (section 3.3): a peer reporting
     * `progress` 1.0 is counted as a connected seed, the same distinction Deluge's num_seeds/num_peers
     * draws from its own peer list. `trackerStats`' `seederCount`/`leecherCount` (section 3.3) give the
     * tracker-reported swarm totals per tracker; the largest non-negative value across trackers is used
     * (a negative value means the tracker has not reported one, mirroring Deluge's -1 "no scrape yet").
     */
    public function details($native)
    {
        $args = $this->rpc()->call('torrent-get', ['ids' => [$native], 'fields' => ['peers', 'eta',
            'uploadedEver', 'totalSize', 'file-count', 'trackerStats']]);
        $t = isset($args['torrents'][0]) ? (array) $args['torrents'][0] : [];
        $seeds = 0;
        $peers = 0;
        foreach ((array) (isset($t['peers']) ? $t['peers'] : []) as $p) {
            $p = (array) $p;
            if (isset($p['progress']) && (float) $p['progress'] >= 1.0) {
                $seeds++;
            } else {
                $peers++;
            }
        }
        $swarmSeeds = null;
        $swarmPeers = null;
        foreach ((array) (isset($t['trackerStats']) ? $t['trackerStats'] : []) as $tr) {
            $tr = (array) $tr;
            if (isset($tr['seederCount']) && (int) $tr['seederCount'] >= 0) {
                $swarmSeeds = max((int) $tr['seederCount'], $swarmSeeds === null ? 0 : $swarmSeeds);
            }
            if (isset($tr['leecherCount']) && (int) $tr['leecherCount'] >= 0) {
                $swarmPeers = max((int) $tr['leecherCount'], $swarmPeers === null ? 0 : $swarmPeers);
            }
        }
        $eta = isset($t['eta']) ? (int) $t['eta'] : -1; // -1/-2 = unknown or not applicable (tr_stat)
        return ['seeds' => $seeds, 'peers' => $peers, 'swarm_seeds' => $swarmSeeds, 'swarm_peers' => $swarmPeers,
            'eta' => $eta >= 0 ? $eta : null, 'uploaded_bytes' => isset($t['uploadedEver']) ? (int) $t['uploadedEver'] : 0,
            'total_size' => isset($t['totalSize']) ? (int) $t['totalSize'] : 0,
            'file_count' => isset($t['file-count']) ? (int) $t['file-count'] : 0];
    }

    /** `torrent-verify`: hash-check (rpc-spec.md 3.1); deletes nothing. */
    public function recheck(array $natives)
    {
        $this->rpc()->call('torrent-verify', ['ids' => self::ids($natives)]);
    }

    /** `torrent-reannounce` (rpc-spec.md 3.1, "ask tracker for more peers"). */
    public function reannounce(array $natives)
    {
        $this->rpc()->call('torrent-reannounce', ['ids' => self::ids($natives)]);
    }

    /**
     * `session-set` with `speed-limit-down(-enabled)` / `speed-limit-up(-enabled)` in KBps (rpc-spec.md
     * 4.1, 4.1.1): Transmission gates the limit with an *-enabled boolean rather than a sentinel value
     * (Deluge's -1.0), so 0/no-limit is sent as *-enabled: false and a positive limit also turns the
     * flag on.
     */
    public function setLimits($down, $up)
    {
        $args = [];
        foreach (['down' => $down, 'up' => $up] as $dir => $v) {
            if ($v === null) {
                continue;
            }
            $args["speed-limit-$dir-enabled"] = $v > 0;
            if ($v > 0) {
                $args["speed-limit-$dir"] = (int) round($v / 1024);
            }
        }
        $this->rpc()->call('session-set', $args);
        return [];
    }

    /**
     * `trackerStats` per torrent (rpc-spec.md 3.3, "trackerStats"): the shared status words are
     * approximated from `isBackup`, `hasAnnounced` and `lastAnnounceSucceeded`, since the spec documents
     * `announceState`'s numbers no more precisely than the field table above. [UNVERIFIED]: the exact
     * announceState enum (libtransmission tr-torrent-announcer.h is not in this fetch) is not read here;
     * this mapping is a best-effort approximation, not a verified one-to-one translation.
     */
    public function trackers($native)
    {
        $args = $this->rpc()->call('torrent-get', ['ids' => [$native], 'fields' => ['trackerStats']]);
        $t = isset($args['torrents'][0]) ? (array) $args['torrents'][0] : [];
        $out = [];
        foreach ((array) (isset($t['trackerStats']) ? $t['trackerStats'] : []) as $tr) {
            $tr = (array) $tr;
            if (!empty($tr['isBackup'])) {
                $status = 'disabled';
            } elseif (empty($tr['hasAnnounced'])) {
                $status = 'not contacted';
            } elseif (!empty($tr['lastAnnounceSucceeded'])) {
                $status = 'working';
            } else {
                $status = 'failing';
            }
            $result = isset($tr['lastAnnounceResult']) ? (string) $tr['lastAnnounceResult'] : '';
            $message = !empty($tr['lastAnnounceSucceeded']) || $result === '' || strcasecmp($result, 'Success') === 0 ? null : $result;
            $url = isset($tr['announce']) ? (string) $tr['announce'] : '';
            $tier = isset($tr['tier']) ? (int) $tr['tier'] : null;
            $seeds = isset($tr['seederCount']) && (int) $tr['seederCount'] >= 0 ? (int) $tr['seederCount'] : null;
            $leechers = isset($tr['leecherCount']) && (int) $tr['leecherCount'] >= 0 ? (int) $tr['leecherCount'] : null;
            $out[] = ['url' => $url, 'tier' => $tier, 'status' => $status, 'message' => $message,
                'seeds' => $seeds, 'leechers' => $leechers];
        }
        return $out;
    }

    private static function ids(array $natives)
    {
        if (!$natives) {
            throw new CliError('no torrent selected', 'name at least one id');
        }
        return array_values($natives);
    }
}
