<?php
/**
 * ReportHtml: one self-contained HTML document per report, made to be printed to PDF by a headless Chromium.
 * Order: cover (verdict, 3-6 KPI cards, report facts, how to read it), contents when there are more than three
 * sections, the sections, the Review page (every data check and what it found) and the appendix "Where each number
 * comes from". The page loads nothing: a Content-Security-Policy allows only inline styles and data: images and fonts,
 * there is no script, and every value is escaped.
 */
class ReportHtml
{
    const CSP = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:";

    public static function render(array $doc, array $lint)
    {
        $prov = ReportProvenance::build($doc);
        ReportHtmlBlocks::$index = $prov['index'];
        ReportHtmlBlocks::$seq = 0;
        ReportHtmlBlocks::$figures = $doc['figures'];
        $e = 'ReportFormat::esc';
        $body = self::cover($doc, $lint, $prov);
        if (count($doc['sections']) > 3) {
            $body .= self::toc($doc);
        }
        foreach ($doc['sections'] as $i => $s) {
            $body .= '<section class="rk-section' . ($s['break'] ? ' brk' : '') . '" id="rk-s' . ($i + 1) . '"><header class="rk-sec-head"><span class="n">'
                . sprintf('%02d', $i + 1) . '</span><h2>' . call_user_func($e, $s['title']) . '</h2></header>'
                . ($s['lead'] !== '' ? '<p class="rk-lead">' . ReportHtmlBlocks::text($s['lead'], $doc['figures']) . '</p>' : '');
            foreach ($s['blocks'] as $b) {
                $body .= ReportHtmlBlocks::block($b, $doc);
            }
            $body .= '</section>';
        }
        $body .= ReportHtmlBack::review($lint) . ReportHtmlBack::sources($doc, $prov) . ReportHtmlBack::end($doc);
        return "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\">"
            . '<meta http-equiv="Content-Security-Policy" content="' . self::CSP . '">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
            . '<meta name="generator" content="report kit"><title>' . call_user_func($e, $doc['title']) . '</title>'
            . '<style>' . ReportCss::sheet($doc) . "</style></head>\n<body>" . $body . "</body></html>\n";
    }

    private static function cover(array $doc, array $lint, array $prov)
    {
        $e = 'ReportFormat::esc';
        $out = '<section class="rk-cover"><div class="rk-band"><span class="rk-eyebrow">' . call_user_func($e, $doc['eyebrow'] !== '' ? $doc['eyebrow'] : 'Report')
            . '</span><span class="rk-date">' . call_user_func($e, ReportFormat::date($doc['as_of'])) . '</span></div>'
            . '<h1>' . call_user_func($e, $doc['title']) . '</h1>'
            . ($doc['subtitle'] !== '' ? '<p class="rk-standfirst">' . ReportFormat::inline($doc['subtitle']) . '</p>' : '');
        if ($doc['verdict'] !== null) {
            $v = $doc['verdict'];
            $out .= '<div class="rk-verdict ' . $v['tone'] . '">' . ReportHtmlBlocks::chip($v['tone'])
                . ($v['headline'] !== '' ? '<h2>' . ReportHtmlBlocks::text($v['headline'], $doc['figures']) . '</h2>' : '')
                . ($v['text'] !== '' ? '<p>' . ReportHtmlBlocks::text($v['text'], $doc['figures']) . '</p>' : '') . '</div>';
        }
        $out .= ReportHtmlBlocks::kpis(array_slice($doc['kpis'], 0, 6));
        $review = $lint['errors'] > 0 ? ReportHtmlBlocks::chip('crit', ReportLint::summary($lint))
            : ($lint['warnings'] > 0 ? ReportHtmlBlocks::chip('warn', ReportLint::summary($lint)) : ReportHtmlBlocks::chip('good', 'All checks passed'));
        $facts = [['Data as of', ReportFormat::dateTime($doc['as_of'])], ['Generated', ReportFormat::dateTime($doc['generated'])],
            ['Sources', (string) count($prov['sources'])]];
        foreach ($doc['meta'] as $m) {
            $facts[] = [$m[0], $m[1]];
        }
        $out .= '<dl class="rk-meta">';
        foreach ($facts as $f) {
            $out .= '<div><dt>' . call_user_func($e, $f[0]) . '</dt><dd>' . call_user_func($e, $f[1]) . '</dd></div>';
        }
        $out .= '<div class="wide"><dt>Data review</dt><dd><a href="#rk-review">' . $review . '</a></dd></div></dl>';
        return $out . '<p class="rk-howto">Small numbers after a figure name its source in <a href="#rk-sources"><b>Where each number comes from</b></a>; '
            . 'a red † marks a figure without one. The <a href="#rk-review"><b>Data review</b></a> page lists every check this report went through.</p></section>';
    }

    private static function toc(array $doc)
    {
        $out = '<nav class="rk-toc"><h2>Contents</h2><ol>';
        foreach ($doc['sections'] as $i => $s) {
            $text = ReportHtmlBlocks::plain($s['lead'], $doc['figures']);
            $lead = $text !== '' ? '<span class="l">' . ReportFormat::esc(preg_match('/^.{0,110}/us', $text, $m) ? $m[0] . (strlen($m[0]) < strlen($text) ? '…' : '') : '') . '</span>' : '';
            $out .= '<li><span class="n">' . sprintf('%02d', $i + 1) . '</span><a href="#rk-s' . ($i + 1) . '"><span class="t">' . ReportFormat::esc($s['title']) . "</span>$lead</a></li>";
        }
        return $out . '<li><span class="n">R</span><a href="#rk-review"><span class="t">Data review</span></a></li>'
            . '<li><span class="n">A</span><a href="#rk-sources"><span class="t">Where each number comes from</span></a></li></ol></nav>';
    }
}
