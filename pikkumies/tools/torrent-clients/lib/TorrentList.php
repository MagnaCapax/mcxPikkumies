<?php
/**
 * TorrentList: `list` with its filters and its sort, done here over the shared records so every client and
 * version behaves the same (the clients' own filters and sort keys differ: qBittorrent 4.x and 5.x filter
 * names, rTorrent has views only). Filters and sort run before --limit and --offset, so "the 20 biggest"
 * really are the 20 biggest.
 */
class TorrentList
{
    /** --sort keys. Numbers and times sort largest (newest) first, name and state A to Z; --reverse flips both. */
    const SORT = ['name', 'size', 'progress', 'ratio', 'added_at', 'completed_at', 'uploaded', 'state'];

    public static function run(array $all, array $opt)
    {
        $state = isset($opt['state']) && is_string($opt['state']) ? strtolower($opt['state']) : null;
        if ($state !== null && !in_array($state, Torrents::STATES, true)) {
            throw new CliError("unknown state: $state", 'states: ' . implode(', ', Torrents::STATES));
        }
        $sort = null;
        if (isset($opt['sort'])) {
            $sort = is_string($opt['sort']) ? strtolower($opt['sort']) : '';
            if (!in_array($sort, self::SORT, true)) {
                throw new CliError('unknown sort field: ' . ($sort === '' ? '(none given)' : $sort), 'sort by one of: ' . implode(', ',
                    self::SORT));
            }
        } elseif (!empty($opt['reverse'])) {
            throw new CliError('--reverse needs --sort <field>', 'sort by one of: ' . implode(', ', self::SORT));
        }
        $search = isset($opt['search']) && is_string($opt['search']) ? strtolower($opt['search']) : null;
        $rows = array_values(array_filter(array_map(['Torrents', 'printable'], $all), function ($r) use ($state, $search) {
            return ($state === null || $r['state'] === $state) && ($search === null || strpos(strtolower($r['name']), $search) !== false);
        }));
        return $sort === null ? $rows : self::sort($rows, $sort, !empty($opt['reverse']));
    }

    /**
     * $rows ordered by $key. A value the client does not give (null, e.g. completed_at of an unfinished torrent)
     * goes last in either direction; ties keep a fixed order by id (usort is not stable before PHP 8).
     */
    public static function sort(array $rows, $key, $reverse)
    {
        $text = in_array($key, ['name', 'state'], true);
        usort($rows, function ($a, $b) use ($key, $text, $reverse) {
            $x = isset($a[$key]) ? $a[$key] : null;
            $y = isset($b[$key]) ? $b[$key] : null;
            if ($x === null || $y === null) {
                return $x === $y ? strcmp($a['id'], $b['id']) : ($x === null ? 1 : -1);
            }
            $c = $text ? strcasecmp((string) $x, (string) $y) : (($y > $x) - ($y < $x));
            $c = $reverse ? -$c : $c;
            return $c !== 0 ? $c : strcmp($a['id'], $b['id']);
        });
        return $rows;
    }
}
