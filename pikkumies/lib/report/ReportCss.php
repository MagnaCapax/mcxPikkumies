<?php
/**
 * ReportCss: the one stylesheet of every report. Light print theme with tokens on :root, system font stacks only
 * (the CSP allows no font downloads): a serif for headings, a sans for reading, a mono for labels and figures in
 * tables. A4 pages with running header and footer in CSS page-margin boxes (Chromium prints them since version 131:
 * title and date on top, the report's footer line and "Page X of Y" at the bottom; the cover page has none).
 * Breaks: sections may start anywhere, but a heading never ends a page, and a card, figure, callout or table row is
 * never split. Backgrounds print (print-color-adjust: exact) because chips and cards depend on them.
 */
class ReportCss
{
    public static function sheet(array $doc)
    {
        $title = ReportFormat::cssString(preg_match('/^.{0,90}/us', $doc['title'], $m) ? $m[0] : $doc['title']);
        $asOf = ReportFormat::cssString('As of ' . ReportFormat::date($doc['as_of']));
        $foot = ReportFormat::cssString($doc['footer'] !== '' ? $doc['footer'] : 'Generated ' . ReportFormat::dateTime($doc['generated']));
        $box = 'font-family:var(--sans);font-size:7.5pt;color:#6c7484;';
        return '@page{size:A4;margin:17mm 15mm 17mm 15mm;'
            . "@top-left{content:$title;{$box}vertical-align:bottom;padding-bottom:3mm}"
            . "@top-right{content:$asOf;{$box}vertical-align:bottom;padding-bottom:3mm}"
            . "@bottom-left{content:$foot;{$box}vertical-align:top;padding-top:3mm}"
            . "@bottom-right{content:\"Page \" counter(page) \" of \" counter(pages);{$box}vertical-align:top;padding-top:3mm}}"
            . '@page:first{@top-left{content:none}@top-right{content:none}}' . "\n" . self::BASE;
    }

    const BASE = <<<'CSS'
:root{
  --ink:#151922;--ink-2:#424a59;--ink-3:#6c7484;--rule:#d8dce4;--rule-2:#eceef2;--paper:#ffffff;--tint:#f6f7f9;
  --accent:#1d4f91;--accent-2:#2a78d6;--accent-soft:#e8eff9;
  --good:#1c6b3f;--good-soft:#e6f2ea;--good-mark:#1f8a3a;
  --warn:#7f5000;--warn-soft:#fcf1dc;--warn-mark:#c98500;
  --crit:#a3242c;--crit-soft:#f9e6e7;--crit-mark:#c8323a;
  --info:#2d4a72;--info-soft:#eaeff7;--info-mark:#2a78d6;
  --serif:Charter,"Bitstream Charter","Sitka Text",Cambria,"Iowan Old Style","Palatino Linotype",P052,"URW Palladio L",Georgia,"DejaVu Serif",serif;
  --sans:system-ui,-apple-system,"Segoe UI",Inter,"Noto Sans","Liberation Sans","Nimbus Sans","DejaVu Sans",Arial,sans-serif;
  --mono:ui-monospace,"SF Mono","Cascadia Mono","DejaVu Sans Mono","Liberation Mono",Menlo,Consolas,monospace;
  --measure:36em;
}
*{box-sizing:border-box}
html{-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{margin:0;background:var(--paper);color:var(--ink);font-family:var(--sans);font-size:9.6pt;line-height:1.5;font-kerning:normal;text-rendering:optimizeLegibility;hyphens:manual}
h1,h2,h3,h4{font-family:var(--serif);font-weight:700;color:var(--ink);margin:0;break-after:avoid;text-wrap:balance}
p{margin:0 0 .7em;max-width:var(--measure);orphans:3;widows:3}
a{color:inherit;text-decoration:none}
code{font-family:var(--mono);font-size:.88em;background:var(--rule-2);padding:.08em .32em;border-radius:2px}
strong{font-weight:650}
.num,.rk-table td.num,.rk-table th.num{font-variant-numeric:tabular-nums lining-nums}
.rk-eyebrow{font-family:var(--mono);font-size:7.4pt;letter-spacing:.16em;text-transform:uppercase;color:var(--accent)}
.rk-muted{color:var(--ink-3)}

/* cover */
.rk-cover{min-height:258mm;display:flex;flex-direction:column;break-after:page}
.rk-band{display:flex;justify-content:space-between;align-items:baseline;border-top:3pt solid var(--ink);padding-top:3mm;margin-bottom:16mm}
.rk-band .rk-date{font-family:var(--mono);font-size:7.4pt;color:var(--ink-3);letter-spacing:.06em}
.rk-cover h1{font-size:30pt;line-height:1.06;letter-spacing:-.015em;max-width:15em;margin-bottom:4mm}
.rk-standfirst{font-size:12.5pt;line-height:1.42;color:var(--ink-2);max-width:31em;margin:0 0 10mm}
.rk-verdict{position:relative;background:var(--tint);border-left:4pt solid var(--accent);padding:5mm 6mm 5mm 6mm;margin-bottom:8mm;break-inside:avoid}
.rk-verdict h2{font-size:15pt;line-height:1.25;margin:2.2mm 0 1.6mm;max-width:28em}
.rk-verdict p{font-size:10.4pt;color:var(--ink-2);margin:0;max-width:44em}
.rk-verdict.good{background:var(--good-soft);border-color:var(--good-mark)}
.rk-verdict.warn{background:var(--warn-soft);border-color:var(--warn-mark)}
.rk-verdict.crit{background:var(--crit-soft);border-color:var(--crit-mark)}
.rk-verdict.info{background:var(--info-soft);border-color:var(--info-mark)}
.rk-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:3.2mm;margin:0 0 6mm}
.rk-kpis.n1{grid-template-columns:1fr}.rk-kpis.n2,.rk-kpis.n4{grid-template-columns:repeat(2,1fr)}
.rk-kpi{border:0.75pt solid var(--rule);border-radius:2.5mm;padding:3.6mm 4mm 3.4mm;background:var(--paper);break-inside:avoid;display:flex;flex-direction:column;gap:1.2mm}
.rk-kpi-top{display:flex;justify-content:space-between;align-items:flex-start;gap:2mm}
.rk-kpi-label{font-family:var(--mono);font-size:6.9pt;letter-spacing:.12em;text-transform:uppercase;color:var(--ink-3);line-height:1.35}
.rk-kpi-value{font-size:21pt;font-weight:650;line-height:1.05;letter-spacing:-.01em;font-variant-numeric:lining-nums;white-space:nowrap}
.rk-kpi-value .u{font-size:10.5pt;font-weight:500;color:var(--ink-2);margin-left:.18em;letter-spacing:0}
.rk-kpi-of{font-size:8.4pt;color:var(--ink-2)}
.rk-meter{height:1.6mm;background:var(--rule-2);border-radius:1mm;overflow:hidden}
.rk-meter span{display:block;height:100%;background:var(--accent-2);border-radius:1mm}
.rk-meter.good span{background:var(--good-mark)}.rk-meter.warn span{background:var(--warn-mark)}.rk-meter.crit span{background:var(--crit-mark)}
.rk-kpi-value .rk-ref{font-size:6.6pt;vertical-align:1.6em;font-weight:500}
.rk-kpi-value .rk-ref.none,.rk-table .rk-ref.none{font-size:9pt;font-weight:700;vertical-align:.9em}
.rk-kpi-note{font-size:7.8pt;color:var(--ink-3);line-height:1.4}
.rk-spark{width:30mm;height:7mm;display:block}
.rk-meta{margin-top:auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(30mm,1fr));gap:0 5mm;border-top:0.75pt solid var(--ink);padding-top:3mm}
.rk-meta div{padding:1mm 0;min-width:0}
.rk-meta div.wide{grid-column:span 2}
.rk-meta .rk-chip{white-space:normal;max-width:100%;line-height:1.35}
.rk-meta dt{font-family:var(--mono);font-size:6.6pt;letter-spacing:.12em;text-transform:uppercase;color:var(--ink-3);margin:0}
.rk-meta dd{margin:.6mm 0 0;font-size:8.6pt;color:var(--ink)}
.rk-howto{font-size:7.6pt;color:var(--ink-3);margin-top:3mm;max-width:none}

/* chips and icons: a word always comes with the colour */
.rk-chip{display:inline-flex;align-items:center;gap:1.2mm;font-family:var(--mono);font-size:6.8pt;letter-spacing:.1em;text-transform:uppercase;font-weight:600;padding:.7mm 2mm .6mm 1.5mm;border-radius:1mm;white-space:nowrap;line-height:1.3;vertical-align:.08em}
.rk-chip svg{width:2.6mm;height:2.6mm;flex:none}
.rk-chip.good{background:var(--good-soft);color:var(--good)}.rk-chip.warn{background:var(--warn-soft);color:var(--warn)}
.rk-chip.crit{background:var(--crit-soft);color:var(--crit)}.rk-chip.info{background:var(--info-soft);color:var(--info)}
.rk-ref{font-size:.56em;line-height:0;vertical-align:.7em;margin-left:.14em;font-weight:600;color:#5b7fb0;font-family:var(--sans);letter-spacing:0;text-transform:none}
.rk-ref.none{color:var(--crit)}

/* contents */
.rk-toc{margin:0 0 9mm;break-inside:avoid}
.rk-toc h2{font-size:13pt;margin-bottom:3mm}
.rk-toc ol{list-style:none;margin:0;padding:0;border-top:0.75pt solid var(--ink)}
.rk-toc li{display:grid;grid-template-columns:11mm 1fr;padding:2mm 0;border-bottom:0.5pt solid var(--rule)}
.rk-toc .n{font-family:var(--mono);font-size:8pt;color:var(--accent);padding-top:.5mm}
.rk-toc .t{font-family:var(--serif);font-weight:700;font-size:10.5pt}
.rk-toc .l{display:block;font-size:8.2pt;color:var(--ink-3);margin-top:.4mm}

/* sections */
.rk-section{margin:0 0 9mm}
.rk-section.brk{break-before:page}
.rk-sec-head{break-after:avoid;break-inside:avoid;margin-bottom:4mm;padding-bottom:2.4mm;border-bottom:0.75pt solid var(--ink)}
.rk-sec-head .n{font-family:var(--mono);font-size:7.4pt;letter-spacing:.16em;color:var(--accent);display:block;margin-bottom:1mm}
.rk-sec-head h2{font-size:17pt;line-height:1.15;letter-spacing:-.01em}
.rk-lead{font-size:11pt;line-height:1.45;color:var(--ink-2);margin:0 0 5mm;max-width:34em;break-after:avoid}
.rk-block{margin:0 0 5mm}
.rk-list{margin:0 0 5mm;padding-left:5mm;max-width:var(--measure)}
.rk-list li{margin:0 0 1.4mm;padding-left:1mm}
.rk-list-title{font-family:var(--serif);font-weight:700;font-size:10.5pt;margin:0 0 2mm}
.rk-callout{display:grid;grid-template-columns:5mm 1fr;gap:0 2.4mm;padding:3.4mm 4mm;border-radius:1.5mm;margin:0 0 5mm;break-inside:avoid;background:var(--info-soft)}
.rk-callout svg{width:4mm;height:4mm;margin-top:.3mm}
.rk-callout h4{font-family:var(--sans);font-size:9.6pt;font-weight:700;margin:0 0 .8mm}
.rk-callout p{margin:0;font-size:9.2pt;max-width:none}
.rk-callout.good{background:var(--good-soft)}.rk-callout.warn{background:var(--warn-soft)}.rk-callout.crit{background:var(--crit-soft)}

/* tables */
.rk-tablewrap{margin:0 0 5mm}
.rk-caption{display:flex;justify-content:space-between;align-items:baseline;gap:4mm;margin-bottom:1.6mm;break-after:avoid}
.rk-caption b{font-family:var(--serif);font-size:10.5pt}
.rk-caption span{font-size:7.6pt;color:var(--ink-3)}
.rk-table{width:100%;border-collapse:collapse;font-size:8.8pt}
.rk-table thead{display:table-header-group}
.rk-table th{font-family:var(--mono);font-size:6.8pt;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-3);font-weight:600;text-align:left;padding:1.6mm 2.4mm 1.4mm;border-bottom:0.9pt solid var(--ink);vertical-align:bottom}
.rk-table td{padding:1.7mm 2.4mm;border-bottom:0.5pt solid var(--rule);vertical-align:top}
.rk-table tr{break-inside:avoid}
.rk-table td.num,.rk-table th.num{text-align:right;white-space:nowrap}
.rk-table td.num{font-family:var(--mono);font-size:8.3pt}
.rk-table tr.total td{border-top:0.9pt solid var(--ink);border-bottom:none;font-weight:700}
.rk-table td.dim{color:var(--ink-3)}
.rk-table td.nw{white-space:nowrap}
.rk-table.compact{font-size:7.8pt}.rk-table.compact td{padding:1mm 2mm}
.rk-note{font-size:7.6pt;color:var(--ink-3);margin-top:1.4mm;max-width:none}

/* figures (charts) */
.rk-figure{margin:0 0 6mm;break-inside:avoid}
.rk-figure .rk-svg{width:100%;height:auto;display:block}
.rk-figure figcaption{font-size:7.6pt;color:var(--ink-3);margin-top:1.6mm}
.rk-figure .rk-caption{margin-bottom:2.4mm}

/* review and appendix */
.rk-back{break-before:page}
.rk-tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:3.2mm;margin:0 0 6mm}
.rk-tile{border-radius:2mm;padding:3.4mm 4mm;background:var(--tint)}
.rk-tile b{display:block;font-size:20pt;line-height:1.1;font-weight:650}
.rk-tile span{font-family:var(--mono);font-size:6.8pt;letter-spacing:.12em;text-transform:uppercase;color:var(--ink-2)}
.rk-tile.good{background:var(--good-soft)}.rk-tile.warn{background:var(--warn-soft)}.rk-tile.crit{background:var(--crit-soft)}
.rk-sources{list-style:none;margin:0 0 6mm;padding:0;columns:2;column-gap:8mm}
.rk-sources li{break-inside:avoid;padding:1.4mm 0;border-bottom:0.5pt solid var(--rule);display:grid;grid-template-columns:7mm 1fr;font-size:8.6pt}
.rk-sources .n{font-weight:700;color:var(--accent)}
.rk-sources small{display:block;color:var(--ink-3);font-size:7.4pt}
.rk-end{margin-top:10mm;padding-top:3mm;border-top:0.75pt solid var(--ink);font-size:8pt;color:var(--ink-3);break-inside:avoid}
.rk-end p{max-width:none;margin:0 0 1.2mm}
.rk-end .sig{font-family:var(--serif);font-size:10pt;color:var(--ink)}
CSS;
}
