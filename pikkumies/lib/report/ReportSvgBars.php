<?php
/**
 * ReportSvgBars: bar (columns), hbar (bars, the most readable form for ranked categories), stacked (horizontal, parts
 * of a whole per category) and donut. Fills beyond the first series carry a texture as well as a colour (the print
 * case of the accessibility rule), every series is in a legend, values sit at the bar end or inside a segment only
 * when the text fits, and touching fills are separated by a 2px surface gap.
 */
class ReportSvgBars
{
    public static function render(array $c, $id)
    {
        $title = $c['title'] !== '' ? $c['title'] : ucfirst($c['kind']) . ' chart';
        if ($c['kind'] === 'donut') {
            return self::donut($c, $id, $title);
        }
        if (!$c['categories'] || !$c['series']) {
            return ReportSvg::open(640, 40, $id, $title, 'No data.') . ReportSvg::text(0, 24, 'No data', 12, ReportSvg::MUTED) . '</svg>';
        }
        return $c['kind'] === 'bar' ? self::columns($c, $id, $title) : self::rows($c, $id, $title);
    }

    /** <defs> with one texture per series slot after the first: 45 and 135 degree lines, then cross. */
    private static function defs($id, $n)
    {
        $out = '<defs>';
        for ($i = 1; $i < $n; $i++) {
            $rot = [1 => 45, 2 => 135, 3 => 45][($i - 1) % 3 + 1];
            $out .= '<pattern id="' . $id . '-p' . $i . '" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(' . $rot . ')">'
                . '<rect width="6" height="6" fill="' . ReportSvg::SERIES[$i % 8] . '"/><line x1="0" y1="0" x2="0" y2="6" stroke="#000" stroke-opacity="0.28" stroke-width="2"/>'
                . ($i % 3 === 0 ? '<line x1="0" y1="3" x2="6" y2="3" stroke="#000" stroke-opacity="0.28" stroke-width="1.5"/>' : '') . '</pattern>';
        }
        return $out . '</defs>';
    }

    private static function fill($id, $i)
    {
        return $i === 0 ? ReportSvg::SERIES[0] : 'url(#' . $id . '-p' . $i . ')';
    }

    /** A bar with a 4px rounded data end and a square base; $dir 'up' (column) or 'right' (bar). */
    private static function bar($x, $y, $w, $h, $fill, $dir)
    {
        $n = 'ReportSvg::n';
        $r = min(4, $dir === 'up' ? $w / 2 : $h / 2, $dir === 'up' ? $h : $w);
        if ($w <= 0 || $h <= 0) {
            return '';
        }
        if ($dir === 'up') {
            $d = 'M' . $n($x) . ',' . $n($y + $h) . 'V' . $n($y + $r) . 'Q' . $n($x) . ',' . $n($y) . ' ' . $n($x + $r) . ',' . $n($y) . 'H' . $n($x + $w - $r)
                . 'Q' . $n($x + $w) . ',' . $n($y) . ' ' . $n($x + $w) . ',' . $n($y + $r) . 'V' . $n($y + $h) . 'Z';
        } else {
            $d = 'M' . $n($x) . ',' . $n($y) . 'H' . $n($x + $w - $r) . 'Q' . $n($x + $w) . ',' . $n($y) . ' ' . $n($x + $w) . ',' . $n($y + $r) . 'V' . $n($y + $h - $r)
                . 'Q' . $n($x + $w) . ',' . $n($y + $h) . ' ' . $n($x + $w - $r) . ',' . $n($y + $h) . 'H' . $n($x) . 'Z';
        }
        return '<path d="' . $d . '" fill="' . $fill . '"/>';
    }

    private static function legend(array $series, $id, $x, $y)
    {
        if (count($series) < 2) {
            return '';
        }
        $out = '';
        foreach ($series as $i => $s) {
            $out .= '<rect x="' . ReportSvg::n($x) . '" y="' . ($y - 9) . '" width="12" height="12" rx="2" fill="' . self::fill($id, $i) . '"/>';
            $out .= ReportSvg::text($x + 17, $y + 1, $s['label'], 11, ReportSvg::INK2);
            $x += 32 + ReportSvg::width($s['label'], 11);
        }
        return $out;
    }

    private static function columns(array $c, $id, $title)
    {
        $W = 640;
        $H = 240;
        $L = 52;
        $T = count($c['series']) > 1 ? 40 : 22;
        $B = 34;
        $all = [0.0];
        foreach ($c['series'] as $s) {
            $all = array_merge($all, $s['values']);
        }
        list($div, $unit) = ReportSvg::axisUnit(max($all), $c['unit']);
        list($lo, $hi, $step, $yt) = ReportSvg::ticks(min($all) / $div, max($all) / $div, 4);
        $ySpan = $hi - $lo > 0 ? $hi - $lo : 1.0;
        $py = function ($v) use ($lo, $ySpan, $T, $H, $B, $div) {
            return $T + (1 - ($v / $div - $lo) / $ySpan) * ($H - $T - $B);
        };
        $svg = self::defs($id, count($c['series']));
        foreach ($yt as $v) {
            $y = $T + (1 - ($v - $lo) / $ySpan) * ($H - $T - $B);
            $svg .= '<line x1="' . $L . '" x2="' . $W . '" y1="' . ReportSvg::n($y) . '" y2="' . ReportSvg::n($y) . '" stroke="' . ($v == 0 ? ReportSvg::AXIS : ReportSvg::GRID) . '"/>'
                . ReportSvg::text($L - 8, $y + 4, ReportFormat::number($v, ReportSvg::decimals($step)), 10.5, ReportSvg::MUTED, 'end');
        }
        if ($c['y_label'] !== '' || $unit !== '') {
            $svg .= ReportSvg::text(8, 12, $c['y_label'] !== '' ? $c['y_label'] : $unit, 10, ReportSvg::MUTED);
        }
        $band = ($W - $L) / count($c['categories']);
        $ns = count($c['series']);
        $bw = min(24, ($band * 0.72 - 2 * ($ns - 1)) / $ns);
        $desc = [];
        $only = null; // many columns: label the highest, the lowest and the last only
        if ($ns === 1 && count($c['categories']) > 8) {
            $v = $c['series'][0]['values'];
            $only = [array_search(max($v), $v, true), array_search(min($v), $v, true), count($v) - 1];
        }
        foreach ($c['categories'] as $k => $cat) {
            $x = $L + $k * $band + ($band - ($bw * $ns + 2 * ($ns - 1))) / 2;
            foreach ($c['series'] as $i => $s) {
                $v = $s['values'][$k];
                $y0 = $py(max(0, $lo * $div));
                $y = $py($v);
                $svg .= self::bar($x, min($y, $y0), $bw, abs($y0 - $y), self::fill($id, $i), 'up');
                if (($ns === 1 && ($only === null || in_array($k, $only, true))) || ($ns > 1 && $bw >= 18)) {
                    $svg .= ReportSvg::text($x + $bw / 2, min($y, $y0) - 5, ReportSvg::value($v, $c['unit'], $c['decimals']), $ns === 1 ? 10.5 : 9, ReportSvg::INK, 'middle', ' font-weight="600"');
                }
                $desc[] = $cat . ($ns > 1 ? ' ' . $s['label'] : '') . ' ' . ReportSvg::value($v, $c['unit'], $c['decimals']);
                $x += $bw + 2;
            }
            $svg .= ReportSvg::text($L + ($k + 0.5) * $band, $H - $B + 16, self::cut($cat, (int) max(4, $band / 6.2)), 10.5, ReportSvg::INK2, 'middle');
        }
        $svg .= self::legend($c['series'], $id, $L, 16);
        return ReportSvg::open($W, $H, $id, $title, 'Column chart: ' . implode('; ', $desc) . '.') . $svg . '</svg>';
    }

    /** hbar (grouped bars per row) and stacked (one bar of parts per row), labels in a left column. */
    private static function rows(array $c, $id, $title)
    {
        $stacked = $c['kind'] === 'stacked';
        $ns = count($c['series']);
        $rowH = $stacked || $ns === 1 ? 30 : 12 + 16 * $ns;
        $W = 640;
        $T = $ns > 1 ? 30 : 6;
        $H = $T + $rowH * count($c['categories']) + 8;
        $labelW = min(190, 16 + max(array_map(function ($s) {
            return ReportSvg::width($s, 11.5);
        }, $c['categories'])));
        $L = $labelW + 10;
        $R = 92;
        $max = 0.0;
        foreach ($c['categories'] as $k => $cat) {
            $vals = array_column($c['series'], 'values');
            $row = array_map(function ($v) use ($k) {
                return $v[$k];
            }, $vals);
            $max = max($max, $stacked ? array_sum($row) : max($row));
        }
        $max = $max > 0 ? $max : 1;
        $px = function ($v) use ($max, $L, $W, $R) {
            return max(0, $v) / $max * ($W - $L - $R);
        };
        $svg = self::defs($id, $ns);
        $desc = [];
        foreach ($c['categories'] as $k => $cat) {
            $y = $T + $k * $rowH;
            $svg .= ReportSvg::text($labelW, $y + $rowH / 2 + 4, self::cut($cat, (int) ($labelW / 6.4)), 11.5, ReportSvg::INK, 'end');
            if ($stacked) {
                $x = $L;
                $sum = 0;
                foreach ($c['series'] as $i => $s) {
                    $v = $s['values'][$k];
                    $w = $px($v);
                    $sum += $v;
                    $last = $i === $ns - 1;
                    $seg = $last ? self::bar($x, $y + 6, max(0, $w), $rowH - 12, self::fill($id, $i), 'right')
                        : '<rect x="' . ReportSvg::n($x) . '" y="' . ($y + 6) . '" width="' . ReportSvg::n(max(0, $w - 2)) . '" height="' . ($rowH - 12) . '" fill="' . self::fill($id, $i) . '"/>';
                    $svg .= $w > 0 ? $seg : '';
                    $label = ReportSvg::value($v, $c['unit'], $c['decimals']);
                    if ($w - 2 > ReportSvg::width($label, 10) + 10) {
                        $svg .= '<rect x="' . ReportSvg::n($x + ($w - 2) / 2 - ReportSvg::width($label, 10) / 2 - 3) . '" y="' . ($y + $rowH / 2 - 7) . '" width="'
                            . ReportSvg::n(ReportSvg::width($label, 10) + 6) . '" height="13" rx="2" fill="#ffffff" fill-opacity="0.85"/>'
                            . ReportSvg::text($x + ($w - 2) / 2, $y + $rowH / 2 + 3.5, $label, 10, ReportSvg::INK, 'middle');
                    }
                    $desc[] = "$cat {$s['label']} $label";
                    $x += $w;
                }
                $svg .= ReportSvg::text($x + 7, $y + $rowH / 2 + 4, ReportSvg::value($sum, $c['unit'], $c['decimals']), 11, ReportSvg::INK, 'start', ' font-weight="600"');
                continue;
            }
            foreach ($c['series'] as $i => $s) {
                $v = $s['values'][$k];
                $bh = $ns === 1 ? min(18, $rowH - 12) : 12;
                $by = $ns === 1 ? $y + ($rowH - $bh) / 2 : $y + 6 + $i * 16;
                $svg .= self::bar($L, $by, max(1, $px($v)), $bh, self::fill($id, $i), 'right');
                $svg .= ReportSvg::text($L + $px($v) + 6, $by + $bh / 2 + 4, ReportSvg::value($v, $c['unit'], $c['decimals']), 10.5, ReportSvg::INK, 'start', ' font-weight="600"');
                $desc[] = $cat . ($ns > 1 ? ' ' . $s['label'] : '') . ' ' . ReportSvg::value($v, $c['unit'], $c['decimals']);
            }
        }
        $svg .= '<line x1="' . $L . '" x2="' . $L . '" y1="' . ($T - 2) . '" y2="' . ($H - 6) . '" stroke="' . ReportSvg::AXIS . '"/>';
        $svg .= self::legend($c['series'], $id, $L, 16);
        return ReportSvg::open($W, $H, $id, $title, ($stacked ? 'Stacked bar chart: ' : 'Bar chart: ') . implode('; ', $desc) . '.') . $svg . '</svg>';
    }

    private static function donut(array $c, $id, $title)
    {
        $W = 640;
        $slices = array_values(array_filter($c['slices'], function ($s) {
            return $s['value'] > 0;
        }));
        $H = max(190, 36 + 26 * count($slices));
        $sum = array_sum(array_column($slices, 'value'));
        if ($sum <= 0) {
            return ReportSvg::open($W, 40, $id, $title, 'No data.') . ReportSvg::text(0, 24, 'No data', 12, ReportSvg::MUTED) . '</svg>';
        }
        $cx = 95;
        $cy = $H / 2;
        $r = 78;
        $svg = self::defs($id, count($slices));
        $a = -M_PI / 2;
        $desc = [];
        foreach ($slices as $i => $s) {
            $frac = $s['value'] / $sum;
            $b = $a + $frac * 2 * M_PI;
            $large = $frac > 0.5 ? 1 : 0;
            if ($frac >= 0.9999) {
                $svg .= '<circle cx="' . $cx . '" cy="' . ReportSvg::n($cy) . '" r="' . ($r - 13) . '" fill="none" stroke="' . self::fill($id, $i) . '" stroke-width="26"/>';
            } else {
                $p = function ($ang, $rad) use ($cx, $cy) {
                    return ReportSvg::n($cx + $rad * cos($ang)) . ',' . ReportSvg::n($cy + $rad * sin($ang));
                };
                $svg .= '<path d="M' . $p($a, $r) . 'A' . $r . ',' . $r . ' 0 ' . $large . ' 1 ' . $p($b, $r) . 'L' . $p($b, $r - 26) . 'A' . ($r - 26) . ',' . ($r - 26)
                    . ' 0 ' . $large . ' 0 ' . $p($a, $r - 26) . 'Z" fill="' . self::fill($id, $i) . '" stroke="' . ReportSvg::SURFACE . '" stroke-width="2" stroke-linejoin="round"/>';
            }
            $a = $b;
            $y = 28 + $i * 26 + ($H - 36 - 26 * count($slices)) / 2;
            $pct = ReportFormat::number(round(100 * $frac, 1), $frac < 0.1 ? 1 : 0) . '%';
            $val = ReportSvg::value($s['value'], $c['unit'], $c['decimals']);
            $svg .= '<rect x="220" y="' . ReportSvg::n($y - 10) . '" width="13" height="13" rx="2" fill="' . self::fill($id, $i) . '"/>'
                . ReportSvg::text(242, $y + 1, self::cut($s['label'], 38), 11.5, ReportSvg::INK)
                . ReportSvg::text(560, $y + 1, $val, 11.5, ReportSvg::INK, 'end', ' font-variant-numeric="tabular-nums"')
                . ReportSvg::text(630, $y + 1, $pct, 11.5, ReportSvg::INK2, 'end', ' font-weight="600" font-variant-numeric="tabular-nums"');
            $desc[] = "{$s['label']} $val ($pct)";
        }
        $total = $c['total'] !== null && $c['total']['value'] !== null ? ReportFormat::figure($c['total']) : ReportSvg::value($sum, $c['unit'], $c['decimals']);
        $svg .= ReportSvg::text($cx, $cy + 2, $total, 15, ReportSvg::INK, 'middle', ' font-weight="700"') . ReportSvg::text($cx, $cy + 18, 'total', 10, ReportSvg::MUTED, 'middle');
        return ReportSvg::open($W, $H, $id, $title, "Donut chart, total $total: " . implode('; ', $desc) . '.') . $svg . '</svg>';
    }

    private static function cut($s, $n)
    {
        return preg_match_all('/./us', (string) $s) <= $n ? (string) $s : (preg_match('/^.{0,' . max(1, $n - 1) . '}/us', (string) $s, $m) ? $m[0] : '') . '…';
    }
}
