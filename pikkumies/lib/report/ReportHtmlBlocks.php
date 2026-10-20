<?php
/**
 * ReportHtmlBlocks: the pieces of a report page. Every value is escaped (ReportFormat::esc / inline); figures print
 * with a source number linking to the "Where each number comes from" appendix, or a red dagger when they have none.
 */
class ReportHtmlBlocks
{
    const WORDS = ['good' => 'Good', 'warn' => 'Attention', 'crit' => 'Critical', 'info' => 'Note'];
    /** 12x12 icons, one shape per tone, so the state reads in greyscale too. */
    const ICONS = [
        'good' => '<circle cx="6" cy="6" r="6" fill="#1f8a3a"/><path d="M3.3 6.2l1.8 1.8 3.6-3.8" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
        'warn' => '<path d="M6 .6l5.6 10.2H.4z" fill="#c98500"/><path d="M6 4.2v3.2" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/><circle cx="6" cy="9.1" r=".85" fill="#fff"/>',
        'crit' => '<path d="M3.5 0h5L12 3.5v5L8.5 12h-5L0 8.5v-5z" fill="#c8323a"/><path d="M4 4l4 4M8 4l-4 4" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>',
        'info' => '<circle cx="6" cy="6" r="6" fill="#2a78d6"/><path d="M6 5.3v3.6" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/><circle cx="6" cy="3.4" r=".9" fill="#fff"/>',
    ];

    /** @var array source => number (ReportProvenance::build()['index']) */
    public static $index = [];
    public static $seq = 0;
    /** @var array the document's declared figures, for {fig:id} in KPI notes */
    public static $figures = [];

    public static function icon($tone)
    {
        return '<svg viewBox="0 0 12 12" aria-hidden="true">' . self::ICONS[isset(self::ICONS[$tone]) ? $tone : 'info'] . '</svg>';
    }

    public static function chip($tone, $word = null)
    {
        $tone = isset(self::WORDS[$tone]) ? $tone : 'info';
        return '<span class="rk-chip ' . $tone . '">' . self::icon($tone) . ReportFormat::esc($word !== null ? $word : self::WORDS[$tone]) . '</span>';
    }

    /** The source mark of a figure: its source number, or a dagger when it has none. */
    public static function ref(array $f)
    {
        if ($f['source'] === '' || !isset(self::$index[$f['source']])) {
            return '<sup class="rk-ref none" title="no source">†</sup>';
        }
        $n = self::$index[$f['source']];
        return '<sup class="rk-ref"><a href="#rk-src-' . $n . '">' . $n . '</a></sup>';
    }

    /** "812 GiB" plus its source mark; $mark false leaves the mark to the table or chart caption. */
    public static function fig(array $f, $mark = true)
    {
        if ($f['value'] === null) {
            return '<span class="rk-muted">–</span>' . ($mark ? self::ref($f) : '');
        }
        return ReportFormat::esc(ReportFormat::figure($f)) . ($mark ? self::ref($f) : '');
    }

    /** Prose with {fig:id} replaced by the formatted, source-marked figure. */
    public static function text($s, array $figures)
    {
        $html = ReportFormat::inline($s);
        return (string) preg_replace_callback('/\{fig:([A-Za-z0-9_.-]+)\}/', function ($m) use ($figures) {
            return isset($figures[$m[1]]) ? '<span class="num">' . self::fig($figures[$m[1]]) . '</span>' : '<span class="rk-chip crit">fig:' . ReportFormat::esc($m[1]) . ' missing</span>';
        }, $html);
    }

    /** Plain text: {fig:id} as its formatted value, ** and ` marks dropped (for the contents list). */
    public static function plain($s, array $figures)
    {
        $s = (string) preg_replace_callback('/\{fig:([A-Za-z0-9_.-]+)\}/', function ($m) use ($figures) {
            return isset($figures[$m[1]]) && $figures[$m[1]]['value'] !== null ? ReportFormat::figure($figures[$m[1]]) : '–';
        }, (string) $s);
        return str_replace(['**', '`'], '', $s);
    }

    public static function kpis(array $items)
    {
        if (!$items) {
            return '';
        }
        $n = count($items);
        $out = '<div class="rk-kpis n' . ($n <= 2 || $n === 4 ? $n : 3) . '">';
        foreach ($items as $k) {
            $out .= self::kpi($k);
        }
        return $out . '</div>';
    }

    private static function kpi(array $k)
    {
        $v = $k['value'];
        list($num, $unit) = $v['value'] !== null ? ReportFormat::parts($v) : ['–', ''];
        $pct = null;
        $of = '';
        if ($k['of'] !== null && $k['of']['value'] > 0 && $v['value'] !== null) {
            $pct = 100 * $v['value'] / $k['of']['value'];
            $of = '<div class="rk-kpi-of">of ' . self::fig($k['of']) . ' · <b class="num">' . ReportFormat::number(round($pct, 1), $pct < 10 ? 1 : 0) . '%</b></div>';
        }
        $tone = $k['tone'];
        $out = '<div class="rk-kpi"><div class="rk-kpi-top"><span class="rk-kpi-label">' . ReportFormat::esc($k['label']) . '</span>'
            . ($tone !== null ? self::chip($tone) : '') . '</div>'
            . '<div class="rk-kpi-value">' . ReportFormat::esc($num) . ($unit !== '' ? '<span class="u">' . ReportFormat::esc($unit) . '</span>' : '') . self::ref($v) . '</div>' . $of;
        if ($pct !== null) {
            $out .= '<div class="rk-meter' . ($tone !== null ? ' ' . $tone : '') . '" role="img" aria-label="' . ReportFormat::esc(ReportFormat::number(round($pct)) . '% used') . '"><span style="width:'
                . ReportSvg::n(max(1.5, min(100, $pct))) . '%"></span></div>';
        }
        if (count($k['spark']) >= 2) {
            $out .= ReportSvg::sparkline($k['spark'], 'rk-sp' . (++self::$seq), 'Trend of ' . $k['label']);
        }
        return $out . ($k['note'] !== '' ? '<div class="rk-kpi-note">' . self::text($k['note'], self::$figures) . '</div>' : '') . '</div>';
    }

    public static function block(array $b, array $doc)
    {
        if ($b['type'] === 'prose') {
            $out = '';
            foreach (preg_split('/\n\s*\n/', $b['text']) as $para) {
                $out .= trim($para) !== '' ? '<p>' . self::text(trim($para), $doc['figures']) . '</p>' : '';
            }
            return $out;
        }
        if ($b['type'] === 'list') {
            $tag = $b['ordered'] ? 'ol' : 'ul';
            $items = implode('', array_map(function ($i) use ($doc) {
                return '<li>' . self::text($i, $doc['figures']) . '</li>';
            }, $b['items']));
            return ($b['title'] !== '' ? '<h4 class="rk-list-title">' . ReportFormat::esc($b['title']) . '</h4>' : '') . "<$tag class=\"rk-list\">$items</$tag>";
        }
        if ($b['type'] === 'callout') {
            return '<div class="rk-callout ' . $b['tone'] . '">' . self::icon($b['tone']) . '<div>'
                . '<h4>' . ReportFormat::esc($b['title'] !== '' ? $b['title'] : self::WORDS[$b['tone']]) . '</h4><p>' . self::text($b['text'], $doc['figures']) . '</p></div></div>';
        }
        if ($b['type'] === 'kpis') {
            return self::kpis($b['items']);
        }
        if ($b['type'] === 'table') {
            return self::table($b);
        }
        return self::chart($b);
    }

    /**
     * Caption line: title with the block's source mark, and "Source · date" in words on the right. A chart needs its
     * own source ($required); a table without one leaves the marks to its cells.
     */
    private static function caption($title, $source, $asOf, $required)
    {
        if ($source === '' && !$required) {
            return '<div class="rk-caption"><b>' . ReportFormat::esc($title) . '</b><span></span></div>';
        }
        $right = $source !== '' ? ReportFormat::esc($source) . ($asOf !== null ? ' · ' . ReportFormat::esc(ReportFormat::date($asOf)) : '') : '<b class="rk-ref none">no source</b>';
        return '<div class="rk-caption"><b>' . ReportFormat::esc($title) . self::ref(['source' => $source]) . "</b><span>$right</span></div>";
    }

    public static function table(array $b)
    {
        $out = '<div class="rk-tablewrap">' . ($b['caption'] !== '' || $b['source'] !== '' ? self::caption($b['caption'], $b['source'], $b['as_of'], false) : '')
            . '<table class="rk-table"><thead><tr>';
        foreach ($b['columns'] as $c) {
            $out .= '<th class="' . ($c['align'] === 'num' ? 'num' : '') . '">' . ReportFormat::esc($c['label'])
                . ($c['unit'] !== '' && $c['unit'] !== 'bytes' ? ' <span class="rk-muted">(' . ReportFormat::esc($c['unit']) . ')</span>' : '') . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        $last = count($b['rows']) - 1;
        foreach ($b['rows'] as $r => $row) {
            $out .= '<tr' . ($b['totals'] && $r === $last && $r > 0 ? ' class="total"' : '') . '>';
            foreach ($row as $i => $cell) {
                $num = isset($b['columns'][$i]) && $b['columns'][$i]['align'] === 'num';
                if (isset($cell['fig'])) {
                    $own = $cell['fig']['source'] !== $b['source']; // a cell with its own source shows its own mark
                    $out .= '<td class="num">' . self::fig($cell['fig'], $own || $b['source'] === '') . '</td>';
                } elseif ($cell['tone'] !== null) {
                    $out .= '<td>' . self::chip($cell['tone'], $cell['text'] !== '' ? $cell['text'] : null) . '</td>';
                } else {
                    $out .= '<td class="' . ($num ? 'num' : '') . '">' . ReportFormat::inline($cell['text']) . '</td>';
                }
            }
            $out .= '</tr>';
        }
        return $out . '</tbody></table>' . ($b['note'] !== '' ? '<p class="rk-note">' . ReportFormat::inline($b['note']) . '</p>' : '') . '</div>';
    }

    public static function chart(array $c)
    {
        $id = 'rk-c' . (++self::$seq);
        $title = $c['title'] !== '' ? $c['title'] : ucfirst($c['kind']) . ' chart';
        $out = '<figure class="rk-figure">' . self::caption($title, $c['source'], $c['as_of'], true) . ReportSvg::render($c, $id);
        $out .= $c['caption'] !== '' ? '<figcaption>' . ReportFormat::inline($c['caption']) . '</figcaption>' : '';
        if ($c['table'] && in_array($c['kind'], ['bar', 'hbar', 'stacked'], true)) {
            $out .= self::dataTable($c);
        }
        return $out . '</figure>';
    }

    /** The numbers behind a bar chart as a compact table (the chart's "table view"). */
    private static function dataTable(array $c)
    {
        $out = '<table class="rk-table compact"><thead><tr><th></th>';
        foreach ($c['series'] as $s) {
            $out .= '<th class="num">' . ReportFormat::esc($s['label'] !== '' ? $s['label'] : 'Value') . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($c['categories'] as $k => $cat) {
            $out .= '<tr><td>' . ReportFormat::esc($cat) . '</td>';
            foreach ($c['series'] as $s) {
                $out .= '<td class="num">' . ReportFormat::esc(ReportSvg::value($s['values'][$k], $c['unit'], $c['decimals'])) . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</tbody></table>';
    }
}
