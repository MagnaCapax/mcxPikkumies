<?php
/**
 * ReportTelemetry: reads the telemetry history (local/state/telemetry/<source>/YYYYMMDD.jsonl, one {ts, field: number}
 * line per run, ADR of the telemetry unit) for the account-health template. Only numbers are read; a line that is not
 * a JSON object, a field that is not a number and a file name that is not a date are skipped. Series are bucketed
 * (hours when the span is at most three days, else days): the mean is the line, the bucket's low and high the band.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class ReportTelemetry
{
    const MAX_LINES = 200000;
    /** Fields charted first when present, in this order: [source, field, title]. */
    const PREFERRED = [['system-account', 'disk_used', 'Disk used'], ['storage', 'quota_used_bytes', 'Disk used'],
        ['system-account', 'traffic_upload_30d', 'Upload, rolling 30 days'], ['system-account', 'mem_used', 'Memory used']];

    public static function dir($local)
    {
        return "$local/state/telemetry";
    }

    /** source => field => [[ts, value], ...] for the last $days days (UTC files), oldest first. */
    public static function read($dir, $days, $now)
    {
        $out = [];
        $from = $now - max(1, (int) $days) * 86400;
        $lines = 0;
        foreach ((array) glob("$dir/*", GLOB_ONLYDIR) as $srcDir) {
            $source = PathName::base($srcDir);
            if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $source) || is_link($srcDir)) {
                continue;
            }
            foreach ((array) glob("$srcDir/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9].jsonl") as $file) {
                $day = strtotime(PathName::base($file, '.jsonl') . ' 00:00:00 UTC');
                if ($day === false || $day + 86400 < $from || is_link($file)) {
                    continue;
                }
                foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                    if (++$lines > self::MAX_LINES) {
                        break 3;
                    }
                    $r = json_decode($line, true);
                    $ts = is_array($r) && isset($r['ts']) ? ReportFormat::ts($r['ts']) : null;
                    if ($ts === null || $ts < $from || $ts > $now + 3600) {
                        continue;
                    }
                    foreach ($r as $k => $v) {
                        if ($k !== 'ts' && (is_int($v) || is_float($v)) && preg_match('/^[a-z][a-z0-9_]{0,31}$/', (string) $k)) {
                            $out[$source][$k][] = [$ts, $v + 0];
                        }
                    }
                }
            }
        }
        foreach ($out as $s => $fields) {
            foreach ($fields as $k => $pts) {
                usort($pts, function ($a, $b) {
                    return $a[0] - $b[0];
                });
                $out[$s][$k] = $pts;
            }
        }
        ksort($out);
        return $out;
    }

    /** Unit of a field by its name: bytes, percent, seconds or none. */
    public static function unit($field)
    {
        if (preg_match('/(_pct|_percent)$/', $field)) {
            return '%';
        }
        if (preg_match('/_bytes$|^(disk|mem|traffic)_(?!.*_pct$)/', $field)) {
            return 'bytes';
        }
        return preg_match('/_s$/', $field) ? 's' : '';
    }

    /** [points, band] bucketed for a chart. */
    public static function bucket(array $pts)
    {
        if (count($pts) < 2) {
            return [array_map(function ($p) {
                return [gmdate('c', $p[0]), $p[1]];
            }, $pts), []];
        }
        $size = $pts[count($pts) - 1][0] - $pts[0][0] <= 3 * 86400 ? 3600 : 86400;
        $b = [];
        foreach ($pts as $p) {
            $b[intdiv($p[0], $size) * $size][] = $p[1];
        }
        $points = $band = [];
        foreach ($b as $t => $vals) {
            $points[] = [gmdate('c', $t), round(array_sum($vals) / count($vals), 4)];
            if (count($vals) > 1) {
                $band[] = [gmdate('c', $t), min($vals), max($vals)];
            }
        }
        return [$points, $band];
    }

    /** Chart blocks: the preferred fields first, then the rest, at most 6 charts. */
    public static function charts(array $tele)
    {
        $picked = [];
        foreach (self::PREFERRED as $p) {
            if (isset($tele[$p[0]][$p[1]]) && count($tele[$p[0]][$p[1]]) >= 2 && !in_array($p[2], array_column($picked, 2), true)) {
                $picked[] = $p;
            }
        }
        foreach ($tele as $source => $fields) {
            foreach ($fields as $field => $pts) {
                if (count($picked) < 6 && count($pts) >= 2 && !in_array([$source, $field], array_map(function ($x) {
                    return [$x[0], $x[1]];
                }, $picked), true)) {
                    $picked[] = [$source, $field, str_replace('_', ' ', ucfirst($field)) . " ($source)"];
                }
            }
        }
        $out = [];
        foreach (array_slice($picked, 0, 6) as $p) {
            $pts = $tele[$p[0]][$p[1]];
            list($points, $band) = self::bucket($pts);
            $out[] = ['type' => 'chart', 'kind' => 'area', 'title' => $p[2], 'unit' => self::unit($p[1]), 'source' => "telemetry {$p[0]}",
                'as_of' => gmdate('c', $pts[count($pts) - 1][0]), 'series' => [['label' => 'Recorded', 'points' => $points, 'band' => $band]]];
        }
        return $out;
    }

    /** One table row per recorded field: latest, low, high, readings. */
    public static function table(array $tele)
    {
        $rows = [];
        foreach ($tele as $source => $fields) {
            foreach ($fields as $field => $pts) {
                $vals = array_column($pts, 1);
                $last = $pts[count($pts) - 1];
                $f = function ($v) use ($field, $source, $last) {
                    return ['value' => $v, 'unit' => self::unit($field), 'source' => "telemetry $source", 'as_of' => gmdate('c', $last[0])];
                };
                $rows[] = ["$source.$field", $f($last[1]), $f(min($vals)), $f(max($vals)), ['value' => count($pts), 'source' => "telemetry $source", 'as_of' => gmdate('c', $last[0])]];
            }
        }
        return ['type' => 'table', 'caption' => 'Recorded measurements', 'columns' => ['Measurement', 'Latest', 'Low', 'High', 'Readings'], 'rows' => $rows,
            'note' => 'Read from the telemetry history files, one source per recording tool.'];
    }
}
