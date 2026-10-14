<?php
/**
 * CliHuman: the --human view of tool records (ADR-0017). Records arrive already cleaned by Cli.
 * One record prints as "key: value" lines, several as an aligned table. A call that mixes kinds of record (a torrent,
 * then its trackers, then its files) prints one block per run of the same kind, each with its own columns; the kind
 * is the first two field names (records list their identifying fields first). Units are converted by
 * field name: *size / *bytes -> KiB..TiB, *_rate -> per second, *_at / added / time -> local date,
 * progress -> percent. Lines are cut to the terminal width (COLUMNS, else 120).
 */
class CliHuman
{
    public static function render(array $records)
    {
        if (!$records) {
            return '';
        }
        $width = (int) getenv('COLUMNS') > 20 ? (int) getenv('COLUMNS') : 120;
        $blocks = [];
        $kind = null;
        foreach (array_map([__CLASS__, 'flatten'], array_values($records)) as $r) {
            $k = implode("\n", array_slice(array_keys($r), 0, 2));
            if ($k !== $kind || !$blocks) {
                $blocks[] = [];
                $kind = $k;
            }
            $blocks[count($blocks) - 1][] = $r;
        }
        return implode("\n", array_map(function ($b) use ($width) {
            return self::block($b, $width);
        }, $blocks));
    }

    /** One kind of record: "key: value" lines for one, an aligned table (the union of their fields) for several. */
    private static function block(array $records, $width)
    {
        if (count($records) === 1) {
            $out = '';
            foreach ($records[0] as $k => $v) {
                $out .= self::cut("$k: " . self::value($k, $v), $width) . "\n";
            }
            return $out;
        }
        $keys = [];
        foreach ($records as $r) {
            $keys = array_merge($keys, array_diff(array_keys($r), $keys));
        }
        $rows = [$keys];
        foreach ($records as $r) {
            $row = [];
            foreach ($keys as $k) {
                $row[] = array_key_exists($k, $r) ? self::value($k, $r[$k]) : '';
            }
            $rows[] = $row;
        }
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max(isset($widths[$i]) ? $widths[$i] : 0, self::len($cell));
            }
        }
        $out = '';
        foreach ($rows as $row) {
            $line = '';
            foreach ($row as $i => $cell) {
                $line .= $cell . str_repeat(' ', $widths[$i] - self::len($cell) + 2);
            }
            $out .= self::cut(rtrim($line), $width) . "\n";
        }
        return $out;
    }

    /** Nested objects become dotted keys (root.available: yes), so every leaf keeps its name and unit. */
    public static function flatten(array $r, $prefix = '')
    {
        $out = [];
        foreach ($r as $k => $v) {
            $key = $prefix . $k;
            if (is_array($v) && $v && array_keys($v) !== range(0, count($v) - 1)) {
                $out += self::flatten($v, "$key.");
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    public static function statusLine(array $s)
    {
        if (empty($s['ok'])) {
            return 'error';
        }
        $line = 'ok, ' . $s['count'] . ' shown';
        if (!empty($s['truncated'])) {
            $line .= ' of ' . $s['total'] . ' (use --limit to see more)';
        }
        foreach ($s as $k => $v) {
            if (!in_array($k, ['ok', 'count', 'truncated', 'total'], true)) {
                $line .= ", $k: " . self::value($k, $v);
            }
        }
        return $line;
    }

    public static function value($key, $v)
    {
        if (is_bool($v)) {
            return $v ? 'yes' : 'no';
        }
        if (is_array($v)) {
            return implode(', ', array_map(function ($x) use ($key) {
                return is_array($x) ? json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : self::value($key, $x);
            }, $v));
        }
        if ($v === null) {
            return '-';
        }
        $key = strtolower((string) $key);
        if (is_numeric($v)) {
            if (preg_match('/(size|bytes)$/', $key)) {
                return self::bytes((float) $v);
            }
            if (preg_match('/_rate$/', $key)) {
                return self::bytes((float) $v) . '/s';
            }
            if (preg_match('/(_at|^added|^time)$/', $key) && $v > 0) {
                return date('Y-m-d H:i', (int) $v);
            }
            if ($key === 'progress') {
                return round((float) $v * 100, 1) . '%';
            }
        }
        return (string) $v;
    }

    public static function bytes($n)
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $i = 0;
        while (abs($n) >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $n : number_format($n, 1)) . ' ' . $units[$i];
    }

    private static function len($s)
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    private static function cut($s, $width)
    {
        if (self::len($s) <= $width) {
            return $s;
        }
        return (function_exists('mb_substr') ? mb_substr($s, 0, $width - 1, 'UTF-8') : substr($s, 0, $width - 1)) . '…';
    }
}
