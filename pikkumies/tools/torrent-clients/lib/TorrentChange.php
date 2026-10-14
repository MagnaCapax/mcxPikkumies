<?php
/**
 * TorrentChange: `recheck`, `reannounce` and `limit`, the same for every client. None of them deletes data,
 * so none asks the user (ADR-0020). Each reads the result back from the client after the one call it makes:
 * no retry and no loop, so a limit the provider sets again is reported, never fought.
 */
class TorrentChange
{
    /** The largest rate accepted: qBittorrent takes the limit as an int (sessionimpl-5.0.0.cpp:3359). */
    const MAX_RATE = 2147483647;

    /** The verbs recheck, reannounce and limit for Torrents::run; $c: fn() => TorrentClient. */
    public static function verbs(callable $c)
    {
        return [
            'recheck' => function ($args) use ($c) {
                return self::nudge($c(), $args, 'recheck');
            },
            'reannounce' => function ($args) use ($c) {
                return self::nudge($c(), $args, 'reannounce');
            },
            'limit' => function ($args, $opt) use ($c) {
                return self::limit($c(), $args, $opt);
            },
        ];
    }

    /** recheck or reannounce the named torrents (Torrents::resolve rules), then their state read back. */
    public static function nudge(TorrentClient $c, array $args, $verb)
    {
        $targets = Torrents::resolve($c->torrents(), $args, 8);
        if ($verb === 'recheck') {
            $c->recheck(Torrents::natives($targets));
        } else {
            $c->reannounce(Torrents::natives($targets));
        }
        $now = [];
        foreach ($c->torrents() as $r) {
            $now[$r['id']] = $r;
        }
        $out = [];
        foreach ($targets as $t) {
            $r = ['id' => $t['id'], 'name' => $t['name'], 'state' => isset($now[$t['id']]) ? $now[$t['id']]['state'] : 'gone',
                'result' => $verb === 'recheck' ? 'recheck requested' : 'announce requested'];
            if ($verb === 'recheck') {
                $r['done_before'] = $t['done']; // a recheck of data that is gone makes the torrent incomplete; it downloads again
            } else {
                $r['message'] = isset($now[$t['id']]) ? $now[$t['id']]['message'] : '';
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * No option: the client's global limits (the traffic fields down_limit, up_limit). With --down/--up: one set,
     * then the limits read back, with `was` (to undo) and `asked`. `verified` when each read-back is within the
     * clients' 1 KiB/s storage step of what was asked (all three keep KiB/s).
     */
    public static function limit(TorrentClient $c, array $args, array $opt)
    {
        if ($args) { // `limit 10MiB/s` must not read the limits and say ok, as if they were set
            throw new CliError('limit takes no arguments: nothing was changed', 'limit --down <rate> --up <rate>, e.g. limit --up 10MiB/s');
        }
        $down = self::rate($opt, 'down');
        $up = self::rate($opt, 'up');
        $keys = ['down_limit' => 1, 'up_limit' => 1];
        $was = array_intersect_key($c->traffic(), $keys);
        if ($down === null && $up === null) {
            return [$was];
        }
        $extra = (array) $c->setLimits($down, $up);
        $now = array_intersect_key($c->traffic(), $keys);
        $asked = array_filter(['down_limit' => $down, 'up_limit' => $up], function ($v) {
            return $v !== null;
        });
        $ok = true;
        foreach ($asked as $k => $v) {
            $got = isset($now[$k]) ? (int) $now[$k] : -1;
            $ok = $ok && ($v === 0 ? $got === 0 : ($got > 0 && abs($got - $v) < 1024));
        }
        return ['records' => [$now + ['was' => $was, 'asked' => $asked] + $extra], 'status' => ['verified' => $ok]];
    }

    /** --down/--up as bytes/s: "10MiB/s", "512KiB", "1048576"; 0 = no limit; null when the option is not given. */
    public static function rate(array $opt, $name)
    {
        if (!isset($opt[$name])) {
            return null;
        }
        $v = is_string($opt[$name]) ? trim($opt[$name]) : '';
        if (!preg_match('#^(\d+(?:\.\d+)?)\s*(B|KiB|MiB|GiB)?(?:/s)?$#i', $v, $m)) {
            throw new CliError("--$name " . ($v === '' ? '(no value)' : $v) . ': not a rate; nothing was changed',
                'bytes per second or a 1024-based size per second: 1048576, 512KiB/s, 10MiB/s; 0 = no limit (MB is ambiguous, use MiB)');
        }
        $mul = ['' => 1, 'b' => 1, 'kib' => 1024, 'mib' => 1048576, 'gib' => 1073741824];
        $bytes = round((float) $m[1] * $mul[strtolower(isset($m[2]) ? $m[2] : '')]);
        if ($bytes == 0 && (float) $m[1] > 0) { // 0 means no limit: a tiny cap must not turn into none
            throw new CliError("--$name $v is less than 1 byte/s; nothing was changed", 'use 0 for no limit, or a rate of at least 1KiB/s');
        }
        if ($bytes > self::MAX_RATE) {
            throw new CliError("--$name $v is more than " . self::MAX_RATE . ' bytes/s; nothing was changed', 'use 0 for no limit');
        }
        return (int) $bytes;
    }
}
