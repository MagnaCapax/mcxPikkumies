<?php
/**
 * ReportModelTable: normalizes the two data blocks, table and chart (ReportModel holds the document and figures).
 *
 * table: {caption, source, as_of, note, totals, columns: [label | {label, unit, align: num|text, decimals}], rows: [[cell]]}
 *   A cell is text, a number or figure (column unit, table source/as_of), or {text, tone} for a labelled status chip.
 *   totals: true says the last row is the declared total of the rows above it (ReportLint adds them up).
 * chart: {kind, title, caption, unit, y_label, x_label, source, as_of, decimals, table, ...data}; data by kind:
 *   line/area: series [{label, points: [[x, y]], band: [[x, min, max]]}]; x is a time (ISO or epoch) or a number.
 *   bar/hbar/stacked: categories [..], series [{label, values: [..]}] (or bars: [{label, value}] for one series).
 *   donut: slices [{label, value}], total; sparkline: values [..]; bullet: value, max, target, ranges [percent, ...].
 */
class ReportModelTable
{
    /** A report holds figures, not raw data: larger blocks are refused (they would cost memory and Chromium time, not clarity). */
    const MAX_ROWS = 2000;
    const MAX_POINTS = 2000;
    const MAX_CATEGORIES = 500;
    const MAX_SERIES = 12;

    private static function cap($n, $max, $what, $where)
    {
        if ($n > $max) {
            throw new InvalidArgumentException("$where: $n $what, at most $max: summarise first (group, bucket or keep the top rows)");
        }
    }

    public static function table(array $b, array $figures, $where)
    {
        $asOf = ReportFormat::ts(isset($b['as_of']) ? $b['as_of'] : null);
        $cols = [];
        foreach ((isset($b['columns']) && is_array($b['columns']) ? $b['columns'] : []) as $c) {
            $c = is_array($c) ? $c : ['label' => is_scalar($c) ? (string) $c : ''];
            $cols[] = ['label' => ReportModel::str($c, 'label'), 'unit' => ReportModel::str($c, 'unit'),
                'align' => ReportModel::str($c, 'align'), 'decimals' => isset($c['decimals']) && is_int($c['decimals']) ? $c['decimals'] : null];
        }
        $rows = [];
        self::cap(isset($b['rows']) && is_array($b['rows']) ? count($b['rows']) : 0, self::MAX_ROWS, 'rows', $where);
        foreach ((isset($b['rows']) && is_array($b['rows']) ? $b['rows'] : []) as $r => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException("$where: row " . ($r + 1) . ' is not a list of cells');
            }
            $cells = [];
            foreach (array_values($row) as $i => $cell) {
                $col = isset($cols[$i]) ? $cols[$i] : ['label' => '', 'unit' => '', 'align' => '', 'decimals' => null];
                $cells[] = self::cell($cell, $figures, ['unit' => $col['unit'], 'source' => ReportModel::str($b, 'source'), 'as_of' => $asOf,
                    'decimals' => $col['decimals']]);
            }
            $rows[] = $cells;
        }
        foreach ($cols as $i => $c) { // a column of figures is right-aligned with tabular numbers unless it says otherwise
            if ($c['align'] === '') {
                $num = 0;
                foreach ($rows as $row) {
                    $num += isset($row[$i]['fig']) ? 1 : 0;
                }
                $cols[$i]['align'] = $rows && $num * 2 >= count($rows) ? 'num' : 'text';
            }
        }
        return ['type' => 'table', 'caption' => ReportModel::str($b, 'caption'), 'note' => ReportModel::str($b, 'note'),
            'source' => ReportModel::str($b, 'source'), 'as_of' => $asOf, 'totals' => !empty($b['totals']), 'columns' => $cols, 'rows' => $rows];
    }

    private static function cell($cell, array $figures, array $defaults)
    {
        if (is_array($cell) && array_key_exists('text', $cell)) {
            return ['text' => ReportModel::str($cell, 'text'), 'tone' => isset($cell['tone']) ? ReportModel::tone($cell) : null];
        }
        if ($cell === null || (is_string($cell) && strpos($cell, 'fig:') !== 0)) {
            return ['text' => ReportModel::text($cell), 'tone' => null];
        }
        if (is_bool($cell)) {
            return ['text' => $cell ? 'yes' : 'no', 'tone' => null];
        }
        return ['fig' => ReportModel::fig($cell, $figures, $defaults)];
    }

    public static function chart(array $b, array $figures, $where)
    {
        $kind = ReportModel::str($b, 'kind');
        if (!in_array($kind, ReportModel::CHARTS, true)) {
            throw new InvalidArgumentException("$where: unknown chart kind '$kind' (one of " . implode(', ', ReportModel::CHARTS) . ')');
        }
        $asOf = ReportFormat::ts(isset($b['as_of']) ? $b['as_of'] : null);
        $c = ['type' => 'chart', 'kind' => $kind, 'title' => ReportModel::str($b, 'title'), 'caption' => ReportModel::str($b, 'caption'),
            'unit' => ReportModel::str($b, 'unit'), 'y_label' => ReportModel::str($b, 'y_label'), 'x_label' => ReportModel::str($b, 'x_label'),
            'source' => ReportModel::str($b, 'source'), 'as_of' => $asOf, 'table' => !empty($b['table']),
            'decimals' => isset($b['decimals']) && is_int($b['decimals']) ? $b['decimals'] : null, 'bad' => 0];
        $d = ['unit' => $c['unit'], 'source' => $c['source'], 'as_of' => $asOf, 'decimals' => $c['decimals']];
        self::cap(count(self::listOf($b, 'series')), self::MAX_SERIES, 'series', $where);
        self::cap(max(count(self::listOf($b, 'categories')), count(self::listOf($b, 'bars')), count(self::listOf($b, 'slices'))), self::MAX_CATEGORIES, 'categories', $where);
        foreach (self::listOf($b, 'series') as $s) {
            self::cap(max(count(self::listOf($s, 'points')), count(self::listOf($s, 'band')), count(self::listOf($s, 'values'))), self::MAX_POINTS, 'points in a series', $where);
        }
        self::cap(count(self::listOf($b, 'values')), self::MAX_POINTS, 'points in a series', $where);
        if ($kind === 'line' || $kind === 'area') {
            $c['series'] = [];
            $c['time'] = true;
            foreach (self::listOf($b, 'series') as $s) {
                $pts = [];
                foreach (self::listOf($s, 'points') as $p) {
                    $x = self::x(isset($p[0]) ? $p[0] : null, $c['time']);
                    $y = isset($p[1]) && (is_int($p[1]) || is_float($p[1])) ? (float) $p[1] : null;
                    $c['bad'] += $x === null || $y === null ? 1 : 0;
                    if ($x !== null && $y !== null) {
                        $pts[] = [$x, $y];
                    }
                }
                $band = [];
                foreach (self::listOf($s, 'band') as $p) {
                    if (isset($p[0], $p[1], $p[2]) && is_numeric($p[1]) && is_numeric($p[2]) && ($x = self::x($p[0], $c['time'])) !== null) {
                        $band[] = [$x, (float) $p[1], (float) $p[2]];
                    }
                }
                usort($pts, function ($a, $b) {
                    return $a[0] < $b[0] ? -1 : ($a[0] > $b[0] ? 1 : 0);
                });
                $c['series'][] = ['label' => ReportModel::str($s, 'label'), 'points' => $pts, 'band' => $band];
            }
        } elseif (in_array($kind, ['bar', 'hbar', 'stacked'], true)) {
            $series = self::listOf($b, 'series');
            $cats = array_values(array_map('strval', array_filter(self::listOf($b, 'categories'), 'is_scalar')));
            if (!$series && isset($b['bars'])) {
                $cats = array_map(function ($r) {
                    return ReportModel::str($r, 'label');
                }, self::listOf($b, 'bars'));
                $series = [['label' => $c['title'], 'values' => array_map(function ($r) {
                    return isset($r['value']) ? $r['value'] : null;
                }, self::listOf($b, 'bars'))]];
            }
            $c['categories'] = $cats;
            $c['series'] = [];
            foreach ($series as $s) {
                $vals = [];
                foreach (array_slice(self::listOf($s, 'values'), 0, count($cats)) as $v) {
                    $ok = is_int($v) || is_float($v);
                    $c['bad'] += $ok ? 0 : 1;
                    $vals[] = $ok ? (float) $v : 0.0;
                }
                $c['series'][] = ['label' => ReportModel::str($s, 'label'), 'values' => array_pad($vals, count($cats), 0.0)];
            }
        } elseif ($kind === 'donut') {
            $c['slices'] = [];
            foreach (self::listOf($b, 'slices') as $s) {
                $v = isset($s['value']) ? $s['value'] : null;
                $c['bad'] += is_int($v) || is_float($v) ? 0 : 1;
                $c['slices'][] = ['label' => ReportModel::str($s, 'label'), 'value' => is_int($v) || is_float($v) ? max(0.0, (float) $v) : 0.0];
            }
            $c['total'] = isset($b['total']) ? ReportModel::fig($b['total'], $figures, $d) : null;
        } elseif ($kind === 'sparkline') {
            $c['values'] = array_map('floatval', array_values(array_filter(self::listOf($b, 'values'), 'is_numeric')));
        } else {
            foreach (['value', 'max', 'target'] as $k) {
                $c[$k] = isset($b[$k]) ? ReportModel::fig($b[$k], $figures, $d) : null;
                if ($c[$k] !== null && $c[$k]['label'] === '') {
                    $c[$k]['label'] = $c['title'] . ($k === 'value' ? '' : " ($k)");
                }
            }
            $c['ranges'] = array_map('floatval', array_values(array_filter(self::listOf($b, 'ranges'), 'is_numeric')));
        }
        return $c;
    }

    /** x of a time series point: a time when every x so far was one (ISO string or epoch), else a plain number. */
    private static function x($v, &$time)
    {
        if (is_string($v) && !is_numeric($v)) {
            $t = ReportFormat::ts($v);
            return $t === null ? null : (float) $t;
        }
        if (is_int($v) || is_float($v) || is_numeric($v)) {
            if ((float) $v < 100000000) {
                $time = false;
            }
            return (float) $v;
        }
        return null;
    }

    private static function listOf($a, $k)
    {
        return is_array($a) && isset($a[$k]) && is_array($a[$k]) ? array_values($a[$k]) : [];
    }
}
