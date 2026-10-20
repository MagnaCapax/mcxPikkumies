<?php
/**
 * The report kit (pikkumies/lib/report, ADR-0091): the data review finds each kind of problem and stays quiet on a
 * clean report, every value is escaped, every chart is well-formed SVG with a title and a description, and the page
 * has the structure the stylesheet and the reader rely on.
 */
require_once dirname(__DIR__) . '/pikkumies/lib/report/ReportKit.php';

/** A minimal clean report around $now with $sections; figures and kpis may be added by the caller. */
function rkDoc(array $extra = [], $now = 1760950000)
{
    $at = gmdate('c', $now - 60);
    return $extra + ['title' => 'Kit test', 'as_of' => gmdate('c', $now), 'figures' => [
        'a' => ['value' => 40, 'unit' => 'GiB', 'source' => 'probe', 'as_of' => $at],
        'b' => ['value' => 60, 'unit' => 'GiB', 'source' => 'probe', 'as_of' => $at],
        'sum' => ['value' => 100, 'unit' => 'GiB', 'source' => 'probe', 'as_of' => $at, 'sum_of' => ['a', 'b']],
    ], 'sections' => [['title' => 'One', 'blocks' => [['type' => 'prose', 'text' => 'Used {fig:a} of {fig:sum}.']]]]];
}

function rkLint(array $in, $now = 1760950000)
{
    $lint = ReportKit::lint(ReportModel::normalize($in, $now));
    return array_count_values(array_column($lint['items'], 'code')) + ['_errors' => $lint['errors'], '_warnings' => $lint['warnings']];
}

return [
    'report kit: a clean report passes every check and the example document is clean too' => function () {
        $c = rkLint(rkDoc());
        check($c['_errors'] === 0 && $c['_warnings'] === 0, 'findings: ' . json_encode($c));
        $lint = ReportKit::lint(ReportModel::normalize(ReportKit::example(1760950000), 1760950000));
        check($lint['errors'] === 0 && $lint['warnings'] === 0, 'example findings: ' . json_encode($lint['items']));
        check(count($lint['checks']) === 13 && !array_filter($lint['checks'], function ($x) {
            return $x['result'] !== 'pass';
        }), 'checks: ' . json_encode($lint['checks']));
    },
    'report kit lint: unsourced numbers, unknown references and non-numbers are errors' => function () {
        $in = rkDoc(['kpis' => [['label' => 'Bare', 'value' => 12], ['label' => 'Ghost', 'value' => 'fig:nope'], ['label' => 'Text', 'value' => ['value' => 'many', 'source' => 's']]]]);
        $in['sections'][0]['blocks'][] = ['type' => 'prose', 'text' => 'See {fig:missing}.'];
        $in['sections'][0]['blocks'][] = ['type' => 'chart', 'kind' => 'bar', 'title' => 'No source', 'categories' => ['x'], 'series' => [['label' => 'v', 'values' => [1]]]];
        $c = rkLint($in);
        check(isset($c['unsourced']) && $c['unsourced'] === 2, 'unsourced (kpi + chart): ' . json_encode($c));
        check(isset($c['unknown-figure']) && $c['unknown-figure'] === 2, 'unknown figures (kpi + prose): ' . json_encode($c));
        check(isset($c['invalid-value']) && $c['invalid-value'] === 1, 'invalid: ' . json_encode($c));
    },
    'report kit lint: stale and future dates, and a sourced number without a date' => function () {
        $now = 1760950000;
        $in = rkDoc(['stale_days' => 7, 'kpis' => [
            ['label' => 'Old', 'value' => ['value' => 1, 'source' => 's', 'as_of' => gmdate('c', $now - 10 * 86400)]],
            ['label' => 'Ahead', 'value' => ['value' => 2, 'source' => 's', 'as_of' => gmdate('c', $now + 3 * 86400)]],
            ['label' => 'Undated', 'value' => ['value' => 3, 'source' => 's']],
            ['label' => 'Fresh', 'value' => ['value' => 4, 'source' => 's', 'as_of' => gmdate('c', $now - 6 * 86400)]]]]);
        $c = rkLint($in, $now);
        check(isset($c['stale'], $c['future'], $c['no-date']) && $c['stale'] === 1 && $c['future'] === 1 && $c['no-date'] === 1, json_encode($c));
    },
    'report kit lint: declared sums that do not add up, in a table total row, a donut and sum_of' => function () {
        $in = rkDoc();
        $in['figures']['sum']['value'] = 120; // 40 + 60 is not 120
        $in['sections'][0]['blocks'][] = ['type' => 'table', 'source' => 's', 'as_of' => $in['as_of'], 'totals' => true, 'columns' => ['Name', 'Size'],
            'rows' => [['a', 1.5], ['b', 2.25], ['Total', 3.75]]]; // adds up exactly
        $in['sections'][0]['blocks'][] = ['type' => 'table', 'source' => 's', 'as_of' => $in['as_of'], 'totals' => true, 'columns' => ['Name', 'Size'],
            'rows' => [['c', 10], ['d', 20], ['All', 35]]];
        $in['sections'][0]['blocks'][] = ['type' => 'chart', 'kind' => 'donut', 'source' => 's', 'as_of' => $in['as_of'], 'title' => 'D',
            'slices' => [['label' => 'x', 'value' => 3], ['label' => 'y', 'value' => 4]], 'total' => 8];
        $c = rkLint($in);
        check(isset($c['sum']) && $c['sum'] === 3, 'sum findings: ' . json_encode($c));
        $ok = rkDoc();
        $ok['sections'][0]['blocks'][] = ['type' => 'table', 'source' => 's', 'as_of' => $ok['as_of'], 'totals' => true, 'columns' => ['Name', 'Share'],
            'rows' => [['a', 33.3], ['b', 33.3], ['c', 33.3], ['Total', 100.0]]]; // rounding of the parts is allowed
        check(!isset(rkLint($ok)['sum']), 'rounded parts flagged: ' . json_encode(rkLint($ok)));
    },
    'report kit lint: a share over 100% is an error unless it is a change; one label with two values is a conflict' => function () {
        $in = rkDoc(['kpis' => [['label' => 'Full', 'value' => ['value' => 104, 'unit' => '%', 'source' => 's', 'as_of' => gmdate('c', 1760950000)]],
            ['label' => 'Growth', 'value' => ['value' => 250, 'unit' => '%', 'change' => true, 'source' => 's', 'as_of' => gmdate('c', 1760950000)]],
            ['label' => 'Disk', 'value' => ['value' => 5, 'unit' => 'GiB', 'source' => 's', 'as_of' => gmdate('c', 1760950000)]],
            ['label' => 'disk ', 'value' => ['value' => 6, 'unit' => 'GiB', 'source' => 's', 'as_of' => gmdate('c', 1760950000)]]]]);
        $c = rkLint($in);
        check(isset($c['percent']) && $c['percent'] === 1, 'percent: ' . json_encode($c));
        check(isset($c['label-conflict']) && $c['label-conflict'] === 1 && $c['_errors'] === 2, 'conflict: ' . json_encode($c));
    },
    'report kit lint: empty sections, long sentences and numbers typed into the text' => function () {
        $in = rkDoc();
        $in['sections'][] = ['title' => 'Empty', 'blocks' => [['type' => 'list', 'items' => []]]];
        $in['sections'][0]['blocks'][] = ['type' => 'prose', 'text' => 'This sentence keeps going on and on with many words so that it is clearly longer than the reading limit of twenty five words allows here.'];
        $in['sections'][0]['blocks'][] = ['type' => 'prose', 'text' => 'Disk grew 37% to 812 GiB on 2026-10-01 at 10:00, see ADR-0091 and v2.5; 3 apps run since 2024.'];
        $c = rkLint($in);
        check(isset($c['empty-section']) && $c['empty-section'] === 1, json_encode($c));
        check(isset($c['long-sentence']) && $c['long-sentence'] === 1, json_encode($c));
        check(ReportLint::typedNumbers('Disk grew 37% to 812 GiB on 2026-10-01 at 10:00, see ADR-0091 and v2.5; 3 apps run since 2024.') === ['37%', '812 GiB'],
            json_encode(ReportLint::typedNumbers('Disk grew 37% to 812 GiB on 2026-10-01 at 10:00, see ADR-0091 and v2.5; 3 apps run since 2024.')));
        check(isset($c['typed-number']) && $c['typed-number'] === 2, json_encode($c));
    },
    'report kit lint: a chart whose values differ by less than 3% of its axis span is a flat-chart warning (bar axes stay at zero)' => function () {
        $at = gmdate('c', 1760950000);
        $in = rkDoc();
        $in['sections'][0]['blocks'][] = ['type' => 'chart', 'kind' => 'bar', 'title' => 'Uptime', 'unit' => '%', 'source' => 's', 'as_of' => $at,
            'categories' => ['a', 'b'], 'series' => [['label' => 'Uptime', 'values' => [99.6, 99.4]]]];
        $in['sections'][0]['blocks'][] = ['type' => 'chart', 'kind' => 'line', 'title' => 'Flat line', 'source' => 's', 'as_of' => $at,
            'series' => [['label' => 'v', 'points' => [[$at, 1000], [gmdate('c', 1760950000 + 60), 1010]]]]];
        $in['sections'][0]['blocks'][] = ['type' => 'chart', 'kind' => 'hbar', 'title' => 'Clear', 'source' => 's', 'as_of' => $at,
            'categories' => ['a', 'b'], 'series' => [['label' => 'v', 'values' => [40, 60]]]];
        $in['sections'][0]['blocks'][] = ['type' => 'chart', 'kind' => 'bar', 'title' => 'Equal', 'source' => 's', 'as_of' => $at,
            'categories' => ['a', 'b'], 'series' => [['label' => 'v', 'values' => [5, 5]]]];
        $lint = ReportKit::lint(ReportModel::normalize($in, 1760950000));
        $flat = array_values(array_filter($lint['items'], function ($i) {
            return $i['code'] === 'flat-chart';
        }));
        check(count($flat) === 2 && $flat[0]['level'] === 'warn' && strpos($flat[0]['message'], 'Uptime') !== false
            && strpos($flat[0]['message'], 'use a table, a bullet chart, or state the gap in words') !== false, json_encode($lint['items']));
        $svg = ReportSvg::render(ReportModel::normalize($in, 1760950000)['sections'][0]['blocks'][1], 'z');
        check(strpos($svg, '>0</text>') !== false, 'the bar axis no longer starts at zero');
    },
    'report kit review fix P-3: oversized tables, series and categories are refused with a summarise-first reason' => function () {
        $rows = array_fill(0, ReportModelTable::MAX_ROWS + 1, ['x', 1]);
        $cases = [
            [['type' => 'table', 'columns' => ['a', 'b'], 'rows' => $rows], 'rows'],
            [['type' => 'chart', 'kind' => 'bar', 'categories' => array_map('strval', range(1, ReportModelTable::MAX_CATEGORIES + 1)), 'series' => []], 'categories'],
            [['type' => 'chart', 'kind' => 'line', 'series' => [['label' => 'v', 'points' => array_fill(0, ReportModelTable::MAX_POINTS + 1, [1760950000, 1])]]], 'points'],
        ];
        foreach ($cases as $c) {
            try {
                ReportModel::normalize(['title' => 't', 'sections' => [['title' => 's', 'blocks' => [$c[0]]]]]);
                check(false, "accepted too many {$c[1]}");
            } catch (InvalidArgumentException $e) {
                check(strpos($e->getMessage(), $c[1]) !== false && strpos($e->getMessage(), 'summarise') !== false, $e->getMessage());
            }
        }
        try {
            ReportModel::normalize(['title' => 't', 'sections' => [['title' => 's', 'blocks' => [['type' => 'prose', 'text' => str_repeat('word 12345 ', 7000)]]]]]);
            check(false, 'a 77 KB prose block was accepted');
        } catch (InvalidArgumentException $e) {
            check(strpos($e->getMessage(), 'summarise') !== false, $e->getMessage());
        }
        $many = 'Values ' . implode(' ', range(100, 3100)) . '.'; // 3001 numbers, of which 1900-2099 read as years
        $lint = ReportKit::lint(ReportModel::normalize(rkDoc(['subtitle' => $many])));
        check(count($lint['items']) <= ReportLint::MAX_PER_CHECK + 2 && $lint['warnings'] >= 2800, 'findings not capped or count lost: ' . count($lint['items']) . ' / ' . $lint['warnings']);
    },
    'report kit review fix P-4: a line chart whose x or y values collapse to one float still renders' => function () {
        $at = gmdate('c', 1760950000);
        foreach ([[[1e18, 5], [1e18 + 1, 6]], [[1, 1e300], [2, 1e300]]] as $pts) {
            $doc = ReportModel::normalize(['title' => 't', 'sections' => [['title' => 's', 'blocks' => [['type' => 'chart', 'kind' => 'line', 'source' => 's', 'as_of' => $at,
                'series' => [['label' => 'v', 'points' => $pts]]]]]]]);
            $svg = ReportSvg::render($doc['sections'][0]['blocks'][0], 'z');
            check(strpos($svg, '<polyline') !== false && strpos($svg, 'NAN') === false && strpos($svg, 'INF') === false, 'bad svg: ' . substr($svg, 0, 200));
        }
    },
    'report kit review fix P-5: typed numbers are found in titles, table text, captions, notes and meta too' => function () {
        $in = rkDoc(['title' => 'Top 25 boxes', 'meta' => ['Plan' => '4 TB plan'], 'kpis' => [['label' => 'K', 'value' => 'fig:a', 'note' => 'of 431 loaded']]]);
        $in['sections'][0]['title'] = 'Week 42 review';
        $in['sections'][0]['blocks'][] = ['type' => 'table', 'caption' => 'Largest 15', 'columns' => ['Name', 'Note'], 'rows' => [['a', 'grew 300 GiB']]];
        $c = rkLint($in);
        check(isset($c['typed-number']) && $c['typed-number'] === 6, json_encode($c));
    },
    'report kit html: every value is escaped and the page loads nothing (CSP, no script, no external URL)' => function () {
        $evil = '<script>alert(1)</script>"\'&</style>';
        $in = rkDoc(['title' => $evil, 'subtitle' => $evil, 'footer' => 'x"; } body { color: red', 'meta' => [$evil => $evil],
            'verdict' => ['tone' => 'crit', 'headline' => $evil, 'text' => '**<img src=x onerror=alert(1)>**'],
            'kpis' => [['label' => $evil, 'value' => ['value' => 1, 'unit' => $evil, 'source' => $evil, 'as_of' => gmdate('c', 1760950000)], 'note' => $evil]]]);
        $in['sections'][0]['blocks'][] = ['type' => 'table', 'caption' => $evil, 'source' => $evil, 'as_of' => gmdate('c', 1760950000),
            'columns' => [$evil, 'n'], 'rows' => [[$evil, 1], [['text' => $evil, 'tone' => 'warn'], 2]]];
        $in['sections'][0]['blocks'][] = ['type' => 'chart', 'kind' => 'hbar', 'title' => $evil, 'source' => $evil, 'as_of' => gmdate('c', 1760950000),
            'categories' => [$evil], 'series' => [['label' => $evil, 'values' => [3]]]];
        $html = ReportKit::html(ReportModel::normalize($in, 1760950000));
        check(stripos($html, '<script') === false && stripos($html, '<img') === false, 'a tag from the data reached the page');
        check(substr_count($html, '</style>') === 1, 'a value closed the style element');
        check(strpos($html, "Content-Security-Policy\" content=\"default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:\"") !== false, 'CSP meta missing');
        check(!preg_match('#(src|href)="(https?:)?//#i', $html) && strpos($html, '@import') === false && strpos($html, 'url(http') === false, 'an external resource is referenced');
        check(strpos($html, 'x\22 ; } body { color: red') !== false, 'the footer text is not CSS-escaped in the page box');
    },
    'report kit svg: every chart kind is well-formed SVG with a title, a description and its words' => function () {
        if (!class_exists('DOMDocument')) {
            skip('the dom extension is not loaded');
        }
        $doc = ReportModel::normalize(ReportKit::example(1760950000), 1760950000);
        $kinds = [];
        foreach ($doc['sections'] as $s) {
            foreach ($s['blocks'] as $b) {
                if ($b['type'] !== 'chart') {
                    continue;
                }
                $svg = ReportSvg::render($b, 'c' . count($kinds));
                $dom = new DOMDocument();
                check(@$dom->loadXML($svg) === true, "{$b['kind']}: not well-formed XML");
                check($dom->getElementsByTagName('title')->length === 1 && trim($dom->getElementsByTagName('desc')->item(0)->textContent) !== '', "{$b['kind']}: no title or desc");
                check($dom->documentElement->getAttribute('role') === 'img', "{$b['kind']}: no role=img");
                $kinds[] = $b['kind'];
            }
        }
        sort($kinds);
        check($kinds === ['area', 'bar', 'bullet', 'donut', 'hbar', 'line', 'stacked'], 'kinds: ' . implode(',', $kinds));
        $spark = new DOMDocument();
        check(@$spark->loadXML(ReportSvg::sparkline([1, 3, 2, 5], 'sp')) === true, 'sparkline: not well-formed');
        $empty = ReportModel::normalize(['title' => 't', 'sections' => [['title' => 's', 'blocks' => [['type' => 'chart', 'kind' => 'line', 'series' => []]]]]]);
        check(@$spark->loadXML(ReportSvg::render($empty['sections'][0]['blocks'][0], 'e')) === true, 'an empty chart is not well-formed');
    },
    'report kit svg: identity is never colour alone (dash patterns, textures, legends, end labels)' => function () {
        $doc = ReportModel::normalize(ReportKit::example(1760950000), 1760950000);
        $byKind = [];
        foreach ($doc['sections'] as $s) {
            foreach ($s['blocks'] as $b) {
                if ($b['type'] === 'chart') {
                    $byKind[$b['kind']] = ReportSvg::render($b, 'k' . $b['kind']);
                }
            }
        }
        check(strpos($byKind['line'], 'stroke-dasharray') !== false && strpos($byKind['line'], '>Down 2.') !== false, 'the second line has no dash or no end label');
        check(strpos($byKind['stacked'], '<pattern') !== false && strpos($byKind['stacked'], '>Downloading<') !== false, 'stacked: no texture or no legend');
        check(strpos($byKind['donut'], '>rTorrent<') !== false && strpos($byKind['donut'], '80%') !== false, 'donut: no labelled legend');
        check(strpos($byKind['bullet'], 'above 75%') !== false, 'bullet: the state is not in words');
    },
    'report kit html: page structure (cover, contents over three sections, numbered sections, review, provenance, page boxes)' => function () {
        $html = ReportKit::html(ReportModel::normalize(ReportKit::example(1760950000), 1760950000));
        foreach (['<section class="rk-cover">', '<nav class="rk-toc">', 'id="rk-s1"', 'id="rk-s4"', 'id="rk-review"', 'id="rk-sources"', 'id="rk-src-1"',
            '@bottom-right{content:"Page " counter(page) " of " counter(pages)', '@page:first{@top-left{content:none}', 'class="rk-kpis n3"', 'class="rk-verdict warn"'] as $needle) {
            check(strpos($html, $needle) !== false, "missing: $needle");
        }
        check(substr_count($html, 'class="rk-kpi"') === 6, 'KPI cards: ' . substr_count($html, 'class="rk-kpi"'));
        check(substr_count($html, '<svg xmlns') >= 9, 'charts and sparklines: ' . substr_count($html, '<svg xmlns'));
        check(strpos($html, 'rk-ref none') === false, 'the clean example shows an unsourced mark');
        $three = rkDoc();
        $three['sections'] = array_fill(0, 3, $three['sections'][0]);
        check(strpos(ReportKit::html(ReportModel::normalize($three, 1760950000)), '<nav class="rk-toc"') === false, 'contents shown for three sections');
        $bare = rkDoc(['kpis' => [['label' => 'Bare', 'value' => 7]]]);
        check(strpos(ReportKit::html(ReportModel::normalize($bare, 1760950000)), '<sup class="rk-ref none" title="no source">†</sup>') !== false, 'no dagger on an unsourced figure');
    },
    'report kit model: a wrong shape is refused with a reason, numbers format by unit' => function () {
        foreach ([[[], 'no title'], [['title' => 't', 'sections' => [['title' => 's', 'blocks' => [['type' => 'video']]]]], 'unknown block type'],
            [['title' => 't', 'sections' => [['blocks' => []]]], 'has no title'], [['title' => 't', 'figures' => ['bad id' => 1]], 'figure id']] as $case) {
            try {
                ReportModel::normalize($case[0]);
                check(false, 'accepted: ' . json_encode($case[0]));
            } catch (InvalidArgumentException $e) {
                check(strpos($e->getMessage(), $case[1]) !== false, $e->getMessage());
            }
        }
        $f = function ($v, $unit) {
            return ReportFormat::figure(['value' => $v, 'unit' => $unit, 'decimals' => null]);
        };
        check($f(878299918336, 'bytes') === "818\u{202F}GiB" && $f(79.9, '%') === '79.9%' && $f(1.9, 'GiB') === "1.9\u{202F}GiB" && $f(12345, '') === '12,345', 'formatting');
        check(ReportFormat::cssString('a"b</style>') === '"a\22 b\3C /style\3E "', ReportFormat::cssString('a"b</style>'));
    },
];
