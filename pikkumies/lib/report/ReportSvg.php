<?php
/**
 * ReportSvg: charts as inline SVG, written in plain PHP (no GD, no JavaScript). Each chart has a <title> and a <desc>
 * that states what it shows in words (range, last value, extremes), and identity never rests on colour alone: lines
 * also differ by dash pattern and carry a direct label at their end, fills differ by texture and every part is named in
 * a legend or a label. Marks follow one spec: 2px lines, bars at most 24 units thick with a 4px rounded data end, a 2px
 * surface gap between touching fills, hairline grids. Colours are the validated categorical order (slots 1-3 are the
 * safe set for overlapping marks) and are written as attributes, so a chart is also a standalone SVG file.
 * Line, area, sparkline and bullet live here; bar, hbar, stacked and donut in ReportSvgBars.
 */
class ReportSvg
{
    const SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
    const DASH = ['', '7 4', '2 3', '10 3 2 3'];
    const INK = '#161a22';
    const INK2 = '#4a5262';
    const MUTED = '#767d8c';
    const GRID = '#e7e9ee';
    const AXIS = '#b9bec8';
    const SURFACE = '#ffffff';
    const STATUS = ['good' => '#1f8a3a', 'warn' => '#c98500', 'crit' => '#c8323a', 'info' => '#2a78d6'];
    const FONT = 'system-ui, -apple-system, &quot;Segoe UI&quot;, &quot;Liberation Sans&quot;, &quot;DejaVu Sans&quot;, Arial, sans-serif';

    /** SVG markup for a normalized chart block; $id makes element ids unique within the page. */
    public static function render(array $c, $id)
    {
        $id = preg_replace('/[^A-Za-z0-9-]/', '', (string) $id);
        if ($c['kind'] === 'line' || $c['kind'] === 'area') {
            return self::line($c, $id);
        }
        if ($c['kind'] === 'sparkline') {
            return self::sparkline($c['values'], $id, $c['title']);
        }
        if ($c['kind'] === 'bullet') {
            return self::bullet($c, $id);
        }
        return ReportSvgBars::render($c, $id);
    }

    public static function open($w, $h, $id, $title, $desc, $class = 'rk-svg')
    {
        $e = 'ReportFormat::esc';
        return '<svg xmlns="http://www.w3.org/2000/svg" class="' . $class . '" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h
            . '" role="img" aria-labelledby="' . $id . '-t ' . $id . '-d" font-family="' . self::FONT . '">'
            . '<title id="' . $id . '-t">' . call_user_func($e, $title) . '</title><desc id="' . $id . '-d">' . call_user_func($e, $desc) . '</desc>';
    }

    /** A text element; $anchor start|middle|end. */
    public static function text($x, $y, $s, $size = 11, $fill = self::INK2, $anchor = 'start', $extra = '')
    {
        return '<text x="' . self::n($x) . '" y="' . self::n($y) . '" font-size="' . $size . '" fill="' . $fill . '"'
            . ($anchor !== 'start' ? ' text-anchor="' . $anchor . '"' : '') . $extra . '>' . ReportFormat::esc($s) . '</text>';
    }

    public static function n($v)
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }

    /** [low, high, step, ticks] covering $min..$max with about $count clean steps (1, 2, 2.5, 5 x 10^n). */
    public static function ticks($min, $max, $count = 5)
    {
        if ($max <= $min) {
            $max = $min + ($min == 0 ? 1 : abs($min) * 0.1);
        }
        $raw = ($max - $min) / max(1, $count);
        $mag = pow(10, floor(log10($raw)));
        $step = $mag;
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $mag >= $raw) {
                $step = $m * $mag;
                break;
            }
        }
        $lo = floor($min / $step) * $step;
        $hi = ceil($max / $step) * $step;
        $out = [];
        for ($v = $lo; $v <= $hi + $step / 1000; $v += $step) {
            $out[] = round($v, 10);
        }
        return [$lo, $hi, $step, $out];
    }

    /** Decimals that show a tick step exactly: 0 for 5 or 10, 1 for 2.5, 2 for 0.25. */
    public static function decimals($step)
    {
        for ($d = 0; $d < 6; $d++) {
            if (abs(round($step, $d) - $step) < 1e-9) {
                return $d;
            }
        }
        return 6;
    }

    /** [divisor, unit label] so axis numbers stay short: bytes become the binary unit of the largest value. */
    public static function axisUnit($max, $unit)
    {
        if ($unit !== 'bytes') {
            return [1, $unit];
        }
        $i = 0;
        while (abs($max) >= 1024 && $i < count(ReportFormat::BYTE_UNITS) - 1) {
            $max /= 1024;
            $i++;
        }
        return [pow(1024, $i), ReportFormat::BYTE_UNITS[$i]];
    }

    public static function value($v, $unit, $dec = null)
    {
        return ReportFormat::figure(['value' => $v, 'unit' => $unit, 'decimals' => $dec]);
    }

    /** Approximate rendered width of $s at $size: enough to decide whether a label fits inside a mark. */
    public static function width($s, $size)
    {
        return preg_match_all('/./us', (string) $s) * $size * 0.56;
    }

    private static function line(array $c, $id)
    {
        $W = 640;
        $H = 236;
        $L = 56;
        $R = 132;
        $T = count($c['series']) > 1 ? 36 : 26;
        $B = 30;
        $xs = [];
        $ys = [0.0];
        foreach ($c['series'] as $s) {
            foreach ($s['points'] as $p) {
                $xs[] = $p[0];
                $ys[] = $p[1];
            }
            foreach ($s['band'] as $p) {
                $ys[] = $p[1];
                $ys[] = $p[2];
            }
        }
        $title = $c['title'] !== '' ? $c['title'] : 'Time series';
        if (!$xs) {
            return self::open($W, 60, $id, $title, 'No data points.') . self::text($L, 34, 'No data points', 12, self::MUTED) . '</svg>';
        }
        list($div, $unit) = self::axisUnit(max($ys), $c['unit']);
        list($lo, $hi, $step, $yt) = self::ticks(min($ys) / $div, max($ys) / $div, 4);
        $x0 = min($xs);
        $x1 = max($xs) - $x0 > 0 ? max($xs) : $x0 + max(1.0, abs($x0) * 1e-9); // $x0 + 1 == $x0 near 1e18
        $ySpan = $hi - $lo > 0 ? $hi - $lo : 1.0;
        $px = function ($x) use ($x0, $x1, $L, $W, $R) {
            return $L + ($x - $x0) / ($x1 - $x0) * ($W - $L - $R);
        };
        $py = function ($y) use ($lo, $ySpan, $T, $H, $B, $div) {
            return $T + (1 - ($y / $div - $lo) / $ySpan) * ($H - $T - $B);
        };
        $desc = [];
        $svg = '';
        foreach ($yt as $v) {
            $y = $T + (1 - ($v - $lo) / $ySpan) * ($H - $T - $B);
            $svg .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . self::n($y) . '" y2="' . self::n($y) . '" stroke="' . ($v == 0 ? self::AXIS : self::GRID) . '" stroke-width="1"/>';
            $svg .= self::text($L - 8, $y + 4, ReportFormat::number($v, self::decimals($step)), 10.5, self::MUTED, 'end', ' font-variant-numeric="tabular-nums"');
        }
        $yLabel = $c['y_label'] !== '' ? $c['y_label'] : $unit;
        if ($yLabel !== '') {
            $svg .= self::text(8, $T - ($T > 30 ? 24 : 14), $yLabel, 10, self::MUTED);
        }
        foreach (self::xTicks($x0, $x1, $c['time']) as $t) {
            $x = $px($t[0]);
            $svg .= '<line x1="' . self::n($x) . '" x2="' . self::n($x) . '" y1="' . ($H - $B) . '" y2="' . ($H - $B + 4) . '" stroke="' . self::AXIS . '"/>';
            $svg .= self::text($x, $H - $B + 16, $t[1], 10.5, self::MUTED, 'middle');
        }
        $ends = [];
        foreach ($c['series'] as $i => $s) {
            $col = self::SERIES[$i % 8];
            if ($s['band']) {
                $top = $bot = [];
                foreach ($s['band'] as $p) {
                    $top[] = self::n($px($p[0])) . ',' . self::n($py($p[2]));
                    $bot[] = self::n($px($p[0])) . ',' . self::n($py($p[1]));
                }
                $svg .= '<polygon points="' . implode(' ', array_merge($top, array_reverse($bot))) . '" fill="' . $col . '" fill-opacity="0.13"/>';
            }
            if (!$s['points']) {
                continue;
            }
            $pts = array_map(function ($p) use ($px, $py) {
                return self::n($px($p[0])) . ',' . self::n($py($p[1]));
            }, $s['points']);
            if ($c['kind'] === 'area') {
                $base = self::n($py(max(0, $lo * $div)));
                $first = $s['points'][0];
                $last = $s['points'][count($s['points']) - 1];
                $svg .= '<polygon points="' . self::n($px($first[0])) . ',' . $base . ' ' . implode(' ', $pts) . ' ' . self::n($px($last[0])) . ',' . $base
                    . '" fill="' . $col . '" fill-opacity="' . ($i === 0 ? '0.12' : '0.07') . '"/>';
            }
            $dash = self::DASH[$i % 4];
            $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $col . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"'
                . ($dash !== '' ? ' stroke-dasharray="' . $dash . '"' : '') . '/>';
            $last = $s['points'][count($s['points']) - 1];
            $vals = array_column($s['points'], 1);
            $label = ($s['label'] !== '' ? $s['label'] . ' ' : '') . self::value($last[1], $c['unit'], $c['decimals']);
            $ends[] = ['x' => $px($last[0]), 'y' => $py($last[1]), 'label' => $label, 'col' => $col];
            $desc[] = ($s['label'] !== '' ? $s['label'] . ': ' : '') . 'last ' . self::value($last[1], $c['unit'], $c['decimals']) . ', low '
                . self::value(min($vals), $c['unit'], $c['decimals']) . ', high ' . self::value(max($vals), $c['unit'], $c['decimals']);
        }
        $svg .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . ($H - $B) . '" y2="' . ($H - $B) . '" stroke="' . self::AXIS . '"/>';
        usort($ends, function ($a, $b) {
            return $a['y'] < $b['y'] ? -1 : 1;
        });
        $prev = -100;
        foreach ($ends as $e) {
            $ly = max($e['y'] + 4, $prev + 14);
            $prev = $ly;
            $svg .= '<circle cx="' . self::n($e['x']) . '" cy="' . self::n($e['y']) . '" r="4" fill="' . $e['col'] . '" stroke="' . self::SURFACE . '" stroke-width="2"/>';
            $svg .= self::text($e['x'] + 9, $ly, $e['label'], 11, self::INK, 'start', ' font-weight="600"');
        }
        if (count($c['series']) > 1) {
            $svg .= self::lineLegend($c['series'], $L, 14);
        }
        $span = $c['time'] ? ReportFormat::dateTime((int) $x0) . ' to ' . ReportFormat::dateTime((int) $x1) : self::n($x0) . ' to ' . self::n($x1);
        $head = ucfirst($c['kind']) . ' chart, ' . count($c['series']) . ' series, ' . $span . '. ';
        return self::open($W, $H, $id, $title, $head . implode('; ', $desc) . '.') . $svg . '</svg>';
    }

    private static function lineLegend(array $series, $x, $y)
    {
        $out = '';
        foreach ($series as $i => $s) {
            $dash = self::DASH[$i % 4];
            $out .= '<line x1="' . self::n($x) . '" x2="' . self::n($x + 22) . '" y1="' . ($y - 4) . '" y2="' . ($y - 4) . '" stroke="' . self::SERIES[$i % 8]
                . '" stroke-width="2.5"' . ($dash !== '' ? ' stroke-dasharray="' . $dash . '"' : '') . '/>';
            $out .= self::text($x + 28, $y, $s['label'], 11, self::INK2);
            $x += 40 + self::width($s['label'], 11);
        }
        return $out;
    }

    /** [[x, label]] ticks for the x axis: whole hours or days for time (UTC), clean numbers otherwise. */
    public static function xTicks($x0, $x1, $time)
    {
        if (!$time) {
            list(, , , $t) = self::ticks($x0, $x1, 5);
            return array_map(function ($v) {
                return [$v, ReportFormat::number($v)];
            }, array_values(array_filter($t, function ($v) use ($x0, $x1) {
                return $v >= $x0 && $v <= $x1;
            })));
        }
        $span = $x1 - $x0;
        $step = 86400 * 30;
        foreach ([3600, 10800, 21600, 43200, 86400, 172800, 604800, 1209600, 2592000] as $s) {
            if ($span / $s <= 6) {
                $step = $s;
                break;
            }
        }
        $out = [];
        for ($t = ceil($x0 / $step) * $step; $t <= $x1 && count($out) < 12; $t += $step) {
            $out[] = [$t, $step < 86400 && gmdate('H:i', (int) $t) !== '00:00' ? gmdate('H:i', (int) $t) : gmdate('j M', (int) $t)];
        }
        return $out;
    }

    public static function sparkline(array $vals, $id, $title = 'Trend')
    {
        $W = 120;
        $H = 28;
        $title = $title !== '' ? $title : 'Trend';
        if (count($vals) < 2) {
            return self::open($W, $H, $id, $title, 'Not enough points for a trend.', 'rk-spark') . '</svg>';
        }
        $min = min($vals);
        $max = max($vals) > $min ? max($vals) : $min + 1;
        $pts = [];
        foreach (array_values($vals) as $i => $v) {
            $pts[] = [2 + $i / (count($vals) - 1) * ($W - 8), 3 + (1 - ($v - $min) / ($max - $min)) * ($H - 6)];
        }
        $last = $pts[count($pts) - 1];
        $desc = 'Trend of ' . count($vals) . ' points: first ' . ReportFormat::number($vals[0]) . ', last ' . ReportFormat::number(end($vals))
            . ', low ' . ReportFormat::number(min($vals)) . ', high ' . ReportFormat::number(max($vals)) . '.';
        return self::open($W, $H, $id, $title, $desc, 'rk-spark') . '<polyline points="' . implode(' ', array_map(function ($p) {
            return self::n($p[0]) . ',' . self::n($p[1]);
        }, $pts)) . '" fill="none" stroke="' . self::SERIES[0] . '" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/>'
            . '<circle cx="' . self::n($last[0]) . '" cy="' . self::n($last[1]) . '" r="2.6" fill="' . self::SERIES[0] . '"/></svg>';
    }

    /** Used against a limit: qualitative bands (ranges, percent of max), the value bar, the target tick, words. */
    private static function bullet(array $c, $id)
    {
        $W = 640;
        $H = 70;
        $L = 0;
        $title = $c['title'] !== '' ? $c['title'] : 'Used against limit';
        $v = isset($c['value']['value']) ? (float) $c['value']['value'] : null;
        $max = isset($c['max']['value']) && $c['max']['value'] > 0 ? (float) $c['max']['value'] : null;
        if ($v === null || $max === null) {
            return self::open($W, 40, $id, $title, 'No value or no limit to compare.') . self::text(0, 24, 'No value or no limit given', 12, self::MUTED) . '</svg>';
        }
        $ranges = $c['ranges'] ?: [70, 90];
        sort($ranges);
        $pct = 100 * $v / $max;
        $tone = $pct >= end($ranges) ? 'crit' : ($pct >= $ranges[0] ? 'warn' : 'good');
        $word = ['good' => 'within range', 'warn' => 'above ' . ReportFormat::number($ranges[0]) . '%', 'crit' => 'above ' . ReportFormat::number(end($ranges)) . '%'][$tone];
        $scale = max($max, $v, isset($c['target']['value']) ? (float) $c['target']['value'] : 0);
        $px = function ($x) use ($scale, $W, $L) {
            return $L + $x / $scale * ($W - $L - 2);
        };
        $svg = '';
        $prev = 0;
        $greys = ['#eef0f3', '#e1e4ea', '#d3d7df', '#c6cbd4'];
        foreach (array_merge($ranges, [100]) as $i => $r) {
            $to = min($scale, $max * $r / 100);
            $svg .= '<rect x="' . self::n($px($prev)) . '" y="26" width="' . self::n(max(0, $px($to) - $px($prev))) . '" height="22" fill="' . $greys[min(3, $i)] . '"/>';
            $prev = $to;
        }
        $svg .= '<rect x="' . self::n($px(0)) . '" y="32" width="' . self::n(max(2, $px($v) - $px(0))) . '" height="10" rx="2" fill="' . self::STATUS[$tone] . '"/>';
        if (isset($c['target']['value'])) {
            $tx = $px((float) $c['target']['value']);
            $svg .= '<line x1="' . self::n($tx) . '" x2="' . self::n($tx) . '" y1="22" y2="52" stroke="' . self::INK . '" stroke-width="2"/>';
        }
        $text = self::value($v, $c['value']['unit'], $c['value']['decimals']) . ' of ' . self::value($max, $c['max']['unit'], $c['max']['decimals'])
            . ' (' . ReportFormat::number(round($pct, 1)) . '%), ' . $word;
        $svg .= self::text(0, 16, $text, 12, self::INK, 'start', ' font-weight="600"');
        foreach ([0, 25, 50, 75, 100] as $p) {
            $svg .= self::text(min($W - 2, max(0, $px($max * $p / 100))), 64, $p . '%', 9.5, self::MUTED, $p === 0 ? 'start' : ($p === 100 ? 'end' : 'middle'));
        }
        return self::open($W, $H, $id, $title, "Bullet chart: $text. Bands at " . implode('%, ', $ranges) . '% of the limit.') . $svg . '</svg>';
    }
}
