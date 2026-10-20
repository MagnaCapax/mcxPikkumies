<?php
/**
 * ReportHtmlBack: the pages at the back. "Data review" shows the lint: counts, every check with its result (a passed
 * check is shown too, so the reader sees what was checked, not only what failed), then each finding. "Where each
 * number comes from" lists the sources by number and every figure with its value, source, date and age.
 */
class ReportHtmlBack
{
    public static function review(array $lint)
    {
        $e = 'ReportFormat::esc';
        $passed = count(array_filter($lint['checks'], function ($c) {
            return $c['result'] === 'pass';
        }));
        $out = '<section class="rk-section rk-back" id="rk-review"><header class="rk-sec-head"><span class="n">R</span><h2>Data review</h2></header>'
            . '<p class="rk-lead">Run on the report data before rendering, by fixed rules: the same data always gives the same findings. '
            . 'Errors are claims the data cannot back; warnings make a figure harder to trust or the text harder to read.</p>'
            . '<div class="rk-tiles"><div class="rk-tile ' . ($lint['errors'] ? 'crit' : 'good') . '"><b class="num">' . $lint['errors'] . '</b><span>Errors</span></div>'
            . '<div class="rk-tile ' . ($lint['warnings'] ? 'warn' : 'good') . '"><b class="num">' . $lint['warnings'] . '</b><span>Warnings</span></div>'
            . '<div class="rk-tile"><b class="num">' . $passed . ' / ' . count($lint['checks']) . '</b><span>Checks passed</span></div></div>'
            . '<div class="rk-tablewrap"><div class="rk-caption"><b>Checks</b><span>every rule, also the ones that passed</span></div>'
            . '<table class="rk-table"><thead><tr><th>Check</th><th>Kind</th><th>Result</th><th class="num">Findings</th></tr></thead><tbody>';
        foreach ($lint['checks'] as $c) {
            $chip = $c['result'] === 'pass' ? ReportHtmlBlocks::chip('good', 'Pass') : ($c['result'] === 'fail' ? ReportHtmlBlocks::chip('crit', 'Fail') : ReportHtmlBlocks::chip('warn', 'Warn'));
            $out .= '<tr><td>' . call_user_func($e, $c['label']) . ' <span class="rk-muted"><code>' . call_user_func($e, $c['code']) . '</code></span></td><td class="dim">'
                . ($c['level'] === 'error' ? 'error' : 'warning') . "</td><td>$chip</td><td class=\"num\">{$c['count']}</td></tr>";
        }
        $out .= '</tbody></table></div>';
        if (!$lint['items']) {
            return $out . '<div class="rk-callout good">' . ReportHtmlBlocks::icon('good') . '<div><h4>Nothing to report</h4><p>Every number has a source and a date, totals add up and the text reads cleanly.</p></div></div></section>';
        }
        $out .= '<div class="rk-tablewrap"><div class="rk-caption"><b>Findings</b><span>' . call_user_func($e, ReportLint::summary($lint)) . '</span></div>'
            . '<table class="rk-table"><thead><tr><th>Level</th><th>Where</th><th>Finding</th></tr></thead><tbody>';
        foreach ($lint['items'] as $i) {
            $out .= '<tr><td>' . ($i['level'] === 'error' ? ReportHtmlBlocks::chip('crit', 'Error') : ReportHtmlBlocks::chip('warn', 'Warning')) . '</td><td class="dim">'
                . call_user_func($e, $i['where']) . '</td><td>' . call_user_func($e, $i['message']) . '</td></tr>';
        }
        return $out . '</tbody></table></div></section>';
    }

    public static function sources(array $doc, array $prov)
    {
        $e = 'ReportFormat::esc';
        $out = '<section class="rk-section rk-back" id="rk-sources"><header class="rk-sec-head"><span class="n">A</span><h2>Where each number comes from</h2></header>'
            . '<p class="rk-lead">Each source has a number; the same small number follows every figure taken from it. Ages are counted back from the report date, '
            . call_user_func($e, ReportFormat::date($doc['as_of'])) . '.</p>';
        if ($prov['sources']) {
            $out .= '<ol class="rk-sources">';
            foreach ($prov['sources'] as $s) {
                $out .= '<li id="rk-src-' . $s['n'] . '"><span class="n">' . $s['n'] . '</span><span>' . call_user_func($e, $s['label']) . '<small>' . $s['count'] . ' figure'
                    . ($s['count'] === 1 ? '' : 's') . ($s['latest'] !== null ? ' · latest ' . call_user_func($e, ReportFormat::dateTime($s['latest'])) : ' · no date') . '</small></span></li>';
            }
            $out .= '</ol>';
        }
        $out .= '<table class="rk-table compact"><thead><tr><th>Figure</th><th class="num">Value</th><th class="num">Source</th><th>As of</th><th class="num">Age</th><th>Where</th></tr></thead><tbody>';
        foreach ($prov['rows'] as $r) {
            $f = $r['fig'];
            $value = $r['kind'] === 'chart' ? $f['chart'] . ($f['chart'] === 1 ? ' point' : ' points') : ($f['value'] !== null ? ReportFormat::figure($f) : '–');
            $stale = $f['as_of'] !== null && $doc['stale_days'] > 0 && $f['as_of'] < $doc['as_of'] - $doc['stale_days'] * 86400;
            $out .= '<tr><td>' . call_user_func($e, $r['label']) . '</td><td class="num">' . call_user_func($e, $value) . '</td><td class="num">'
                . ($r['n'] !== null ? '<a href="#rk-src-' . $r['n'] . '">' . $r['n'] . '</a>' : ReportHtmlBlocks::chip('crit', 'None')) . '</td><td class="dim nw">'
                . call_user_func($e, $f['as_of'] !== null ? ReportFormat::date($f['as_of']) : '–') . '</td><td class="num">'
                . ($stale ? ReportHtmlBlocks::chip('warn', ReportFormat::age($f['as_of'], $doc['as_of'])) : call_user_func($e, ReportFormat::age($f['as_of'], $doc['as_of']) !== '' ? ReportFormat::age($f['as_of'], $doc['as_of']) : '–'))
                . '</td><td class="dim">' . call_user_func($e, $r['where']) . '</td></tr>';
        }
        return $out . '</tbody></table></section>';
    }

    public static function end(array $doc)
    {
        if ($doc['author'] === '' && $doc['disclaimer'] === '') {
            return '';
        }
        $e = 'ReportFormat::esc';
        return '<footer class="rk-end">' . ($doc['author'] !== '' ? '<p class="sig">' . call_user_func($e, $doc['author']) . '</p>' : '')
            . ($doc['disclaimer'] !== '' ? '<p>' . ReportFormat::inline($doc['disclaimer']) . '</p>' : '') . '</footer>';
    }
}
