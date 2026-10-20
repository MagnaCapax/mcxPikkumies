<?php
/**
 * ReportLint: the deterministic data review run before every render. It never changes the document; it lists what a
 * careful reviewer would stop at, as errors (the report states something it cannot back) and warnings (it is harder to
 * trust or to read than it should be). The renderer prints the result as the Review page and in its status line, and
 * --strict refuses to render while there is any error. The same input always gives the same findings.
 */
class ReportLint
{
    const MAX_WORDS = 25;
    /** Findings listed per check; the rest are counted (errors and warnings stay exact) and named in one summary line. */
    const MAX_PER_CHECK = 100;
    /** code => [level, what the check looks for]; the Review page lists every check, also the ones that passed. */
    const CHECKS = [
        'unsourced' => ['error', 'Every number names its source'],
        'unknown-figure' => ['error', 'Every figure reference points to a declared figure'],
        'invalid-value' => ['error', 'Every number is a number'],
        'sum' => ['error', 'Declared totals equal the sum of their parts'],
        'percent' => ['error', 'Shares stay between 0% and 100%'],
        'future' => ['error', 'No figure is dated after the report'],
        'label-conflict' => ['error', 'One label, one value'],
        'empty-section' => ['error', 'No section is empty'],
        'stale' => ['warn', 'Figures are recent enough for the report date'],
        'no-date' => ['warn', 'Every sourced number says when it was measured'],
        'typed-number' => ['warn', 'Numbers in the text come from figures, not typing'],
        'long-sentence' => ['warn', 'Sentences stay under 26 words'],
        'flat-chart' => ['warn', 'Each chart can show the differences it plots'],
    ];

    public static function run(array $doc)
    {
        $items = [];
        $seen = [];
        $total = ['error' => 0, 'warn' => 0];
        $errs = [];
        $add = function ($code, $where, $message, $level = null) use (&$items, &$seen, &$total, &$errs) {
            $level = $level !== null ? $level : self::CHECKS[$code][0];
            $seen[$code] = (isset($seen[$code]) ? $seen[$code] : 0) + 1;
            $total[$level]++;
            $errs[$code] = (isset($errs[$code]) ? $errs[$code] : 0) + ($level === 'error' ? 1 : 0);
            if ($seen[$code] <= self::MAX_PER_CHECK) {
                $items[] = ['level' => $level, 'code' => $code, 'where' => $where, 'message' => $message];
            }
        };
        $byLabel = [];
        foreach (ReportProvenance::walk($doc) as $row) {
            self::figure($doc, $row, $add);
            $f = $row['fig'];
            if ($row['kind'] !== 'chart' && $f['value'] !== null) {
                $byLabel[strtolower(trim($row['label'])) . '|' . $f['unit']][] = $row;
            }
        }
        foreach ($byLabel as $rows) {
            self::conflict($rows, $add);
        }
        self::sums($doc, $add);
        foreach (self::texts($doc) as $t) {
            self::text($doc, $t[0], $t[1], $t[2], $add);
        }
        foreach ($doc['sections'] as $i => $s) {
            foreach ($s['blocks'] as $b) {
                self::flat($b, ($i + 1) . '. ' . $s['title'], $add);
            }
            if (!array_filter($s['blocks'], [self::class, 'hasContent'])) {
                $add('empty-section', ($i + 1) . '. ' . $s['title'], 'the section has no content; fill it or leave it out');
            }
        }
        $checks = [];
        foreach (self::CHECKS as $code => $c) {
            $n = isset($seen[$code]) ? $seen[$code] : 0;
            if ($n > self::MAX_PER_CHECK) {
                $items[] = ['level' => $c[0], 'code' => $code, 'where' => 'whole report', 'message' => ($n - self::MAX_PER_CHECK) . " more $code findings are counted but not listed"];
            }
            $checks[] = ['code' => $code, 'level' => $c[0], 'label' => $c[1], 'count' => $n, 'result' => $n === 0 ? 'pass' : (!empty($errs[$code]) ? 'fail' : 'warn')];
        }
        usort($items, function ($a, $b) {
            return $a['level'] === $b['level'] ? 0 : ($a['level'] === 'error' ? -1 : 1);
        });
        return ['errors' => $total['error'], 'warnings' => $total['warn'],
            'items' => $items, 'checks' => $checks];
    }

    /**
     * A bar, column or line chart whose values differ by less than 3% of its axis span draws them all the same: the
     * comparison it exists for is invisible. Bar axes start at zero on purpose (a cut bar axis misleads), so the fix is
     * another form, not a zoomed axis. Equal values are not flagged: there is no difference to show.
     */
    private static function flat(array $b, $where, callable $add)
    {
        if ($b['type'] !== 'chart' || !in_array($b['kind'], ['bar', 'hbar', 'line', 'area'], true)) {
            return;
        }
        $vals = [];
        foreach ($b['series'] as $s) {
            $vals = array_merge($vals, isset($s['points']) ? array_column($s['points'], 1) : $s['values']);
        }
        if (count($vals) < 2) {
            return;
        }
        $span = max(0, max($vals)) - min(0, min($vals)); // every chart kind here draws its axis from zero
        $diff = max($vals) - min($vals);
        if ($span > 0 && $diff > 0 && $diff < 0.03 * $span) {
            $add('flat-chart', $where, '"' . ($b['title'] !== '' ? $b['title'] : $b['kind'] . ' chart') . '" plots values ' . ReportFormat::number(min($vals)) . ' to '
                . ReportFormat::number(max($vals)) . ' on an axis from zero, so they look the same. The chart cannot show the difference: use a table, a bullet chart, or state the gap in words');
        }
    }

    /** "2 errors, 3 warnings" or "clean". */
    public static function summary(array $lint)
    {
        if ($lint['errors'] === 0 && $lint['warnings'] === 0) {
            return 'clean';
        }
        if ($lint['errors'] === 0) {
            return $lint['warnings'] . ' warning' . ($lint['warnings'] === 1 ? '' : 's');
        }
        return $lint['errors'] . ' error' . ($lint['errors'] === 1 ? '' : 's') . ', ' . $lint['warnings'] . ' warning' . ($lint['warnings'] === 1 ? '' : 's');
    }

    private static function figure(array $doc, array $row, callable $add)
    {
        $f = $row['fig'];
        $name = '"' . $row['label'] . '"';
        if (isset($f['missing'])) {
            $add('unknown-figure', $row['where'], "$name refers to fig:{$f['missing']}, which doc.figures does not declare");
            return;
        }
        if (isset($f['invalid'])) {
            $add('invalid-value', $row['where'], "$name is not a number (" . substr($f['invalid'], 0, 40) . ')');
            return;
        }
        if ($row['kind'] === 'chart' && $f['chart'] === 0) {
            $add('invalid-value', $row['where'], "$name has no data points");
        }
        if ($f['source'] === '') {
            $add('unsourced', $row['where'], "$name has no source; add source (and as_of) to the figure" . ($row['kind'] === 'cell' || $row['kind'] === 'chart' ? ' or to its table/chart' : ''));
        } elseif ($f['as_of'] === null) {
            $add('no-date', $row['where'], "$name ({$f['source']}) has no as_of date");
        }
        if ($f['as_of'] !== null && $f['as_of'] > $doc['as_of'] + 86400) {
            $add('future', $row['where'], "$name is dated " . ReportFormat::date($f['as_of']) . ', after the report (' . ReportFormat::date($doc['as_of']) . ')');
        } elseif ($f['as_of'] !== null && $doc['stale_days'] > 0 && $f['as_of'] < $doc['as_of'] - $doc['stale_days'] * 86400) {
            $add('stale', $row['where'], "$name is from " . ReportFormat::date($f['as_of']) . ', ' . ReportFormat::age($f['as_of'], $doc['as_of'])
                . ' before the report date (limit ' . $doc['stale_days'] . ' d)');
        }
        if ($f['unit'] === '%' && $f['value'] !== null && !$f['change'] && ($f['value'] > 100 || $f['value'] < 0)) {
            $add('percent', $row['where'], "$name is " . ReportFormat::figure($f) . '; a share is 0-100% (mark a change "change": true)');
        }
    }

    private static function conflict(array $rows, callable $add)
    {
        $values = array_unique(array_map(function ($r) {
            return (string) round((float) $r['fig']['value'], 9);
        }, $rows));
        if (count($values) < 2) {
            return;
        }
        $dates = array_unique(array_map(function ($r) {
            return (string) $r['fig']['as_of'];
        }, $rows));
        $shown = implode(' vs ', array_map(function ($r) {
            return ReportFormat::figure($r['fig']) . ' (' . $r['where'] . ')';
        }, $rows));
        $msg = '"' . $rows[0]['label'] . "\" has different values: $shown";
        if (count($dates) > 1) {
            $add('label-conflict', $rows[0]['where'], $msg . '; measured at different times, so name the time in each label', 'warn');
            return;
        }
        $add('label-conflict', $rows[0]['where'], $msg);
    }

    private static function sums(array $doc, callable $add)
    {
        foreach ($doc['sections'] as $i => $s) {
            $where = ($i + 1) . '. ' . $s['title'];
            foreach ($s['blocks'] as $b) {
                if ($b['type'] === 'table' && $b['totals'] && count($b['rows']) >= 2) {
                    $body = array_slice($b['rows'], 0, -1);
                    $total = $b['rows'][count($b['rows']) - 1];
                    foreach ($total as $c => $cell) {
                        if (!isset($cell['fig']) || $cell['fig']['value'] === null) {
                            continue;
                        }
                        $parts = [];
                        foreach ($body as $row) {
                            if (isset($row[$c]['fig']) && $row[$c]['fig']['value'] !== null) {
                                $parts[] = $row[$c]['fig']['value'];
                            }
                        }
                        $col = isset($b['columns'][$c]['label']) ? $b['columns'][$c]['label'] : 'column ' . ($c + 1);
                        self::sumCheck($cell['fig'], $parts, $where . ($b['caption'] !== '' ? ' › ' . $b['caption'] : ''), "total of \"$col\"", $add);
                    }
                }
                if ($b['type'] === 'chart' && $b['kind'] === 'donut' && $b['total'] !== null && $b['total']['value'] !== null) {
                    self::sumCheck($b['total'], array_column($b['slices'], 'value'), $where, 'donut total "' . $b['title'] . '"', $add);
                }
                if ($b['type'] === 'chart' && in_array($b['kind'], ['bar', 'hbar', 'stacked'], true) && $b['unit'] === '%') {
                    foreach ($b['categories'] as $k => $cat) {
                        $vals = array_map(function ($sr) use ($k) {
                            return $sr['values'][$k];
                        }, $b['series']);
                        $v = $b['kind'] === 'stacked' ? array_sum($vals) : max($vals ?: [0]);
                        if ($v > 100.5 || min($vals ?: [0]) < 0) {
                            $add('percent', $where, '"' . $b['title'] . "\" › $cat reaches " . ReportFormat::number($v) . '%; a share is 0-100%');
                        }
                    }
                }
            }
        }
        foreach ($doc['figures'] as $id => $f) {
            if ($f['sum_of'] && $f['value'] !== null) {
                $parts = [];
                foreach ($f['sum_of'] as $pid) {
                    if (!isset($doc['figures'][$pid])) {
                        $add('unknown-figure', 'figures', "fig:$id sums fig:$pid, which doc.figures does not declare");
                        continue 2;
                    }
                    $parts[] = $doc['figures'][$pid]['value'];
                }
                self::sumCheck($f, array_filter($parts, 'is_numeric'), 'figures', "fig:$id (sum of " . implode(' + ', $f['sum_of']) . ')', $add);
            }
        }
    }

    /** The declared total against the sum, allowing the rounding of decimal parts as written plus 0.1% of the total. */
    private static function sumCheck(array $total, array $parts, $where, $what, callable $add)
    {
        $sum = array_sum($parts);
        $dec = 0;
        foreach (array_merge($parts, [$total['value']]) as $p) {
            $s = (string) $p;
            $dec = strpos($s, '.') !== false && stripos($s, 'e') === false ? max($dec, strlen($s) - strpos($s, '.') - 1) : $dec;
        }
        $tol = ($dec > 0 ? (count($parts) + 1) * 0.5 * pow(10, -$dec) : 0) + 0.001 * abs((float) $total['value']); // whole numbers add up exactly
        if (abs($sum - (float) $total['value']) > $tol) {
            $f = ['value' => $sum, 'decimals' => $total['decimals']] + $total;
            $add('sum', $where, "$what says " . ReportFormat::figure($total) . ' but the parts add up to ' . ReportFormat::figure($f));
        }
    }

    /**
     * [where, text, scan for typed numbers?] for every free-text field a reader takes as a statement: title, subtitle,
     * meta values, verdict, KPI notes, section titles and leads, prose, callouts, lists, table captions, notes and text
     * cells, chart captions. Chart titles and KPI labels are names ("last 30 days"), not claims, and are not scanned.
     */
    private static function texts(array $doc)
    {
        $t = [['Title page', $doc['title'], true], ['Title page', $doc['subtitle'], true]];
        foreach ($doc['meta'] as $m) {
            $t[] = ['Title page', $m[1], true];
        }
        foreach ($doc['kpis'] as $k) {
            $t[] = ['Summary', $k['note'], true];
        }
        if ($doc['verdict'] !== null) {
            $t[] = ['Verdict', $doc['verdict']['headline'], true];
            $t[] = ['Verdict', $doc['verdict']['text'], true];
        }
        foreach ($doc['sections'] as $i => $s) {
            $where = ($i + 1) . '. ' . $s['title'];
            $t[] = [$where, $s['title'], true];
            $t[] = [$where, $s['lead'], true];
            foreach ($s['blocks'] as $b) {
                if ($b['type'] === 'prose' || $b['type'] === 'callout') {
                    $t[] = [$where, $b['text'], true];
                    $t[] = [$where, $b['type'] === 'callout' ? $b['title'] : '', true];
                } elseif ($b['type'] === 'list') {
                    $t[] = [$where, $b['title'], true];
                    foreach ($b['items'] as $item) {
                        $t[] = [$where, $item, true];
                    }
                } elseif ($b['type'] === 'kpis') {
                    foreach ($b['items'] as $k) {
                        $t[] = [$where, $k['note'], true];
                    }
                } elseif ($b['type'] === 'table') {
                    $t[] = [$where, $b['caption'] . ' ' . $b['note'], true];
                    foreach ($b['rows'] as $row) {
                        foreach ($row as $cell) {
                            $t[] = [$where, isset($cell['text']) ? $cell['text'] : '', true];
                        }
                    }
                } elseif ($b['type'] === 'chart') {
                    $t[] = [$where, $b['caption'], true];
                }
            }
        }
        return $t;
    }

    private static function text(array $doc, $where, $text, $numbers, callable $add)
    {
        preg_match_all('/\{fig:([A-Za-z0-9_.-]+)\}/', $text, $m);
        foreach ($m[1] as $id) {
            if (!isset($doc['figures'][$id])) {
                $add('unknown-figure', $where, "{fig:$id} in the text, but doc.figures does not declare $id");
            }
        }
        foreach (ReportFormat::sentences($text) as $s) {
            $n = ReportFormat::wordCount($s);
            if ($n > self::MAX_WORDS) {
                $add('long-sentence', $where, "$n words: \"" . self::cut($s, 70) . '…"; split it');
            }
        }
        if ($numbers) {
            foreach (self::typedNumbers($text) as $num) {
                $add('typed-number', $where, "\"$num\" is typed into the text; declare it in doc.figures and write {fig:<id>}");
            }
        }
    }

    /** Numbers written straight into text: not dates, times, years, versions, single digits or {fig:} references. */
    public static function typedNumbers($text)
    {
        $t = preg_replace(['/\{fig:[^}]*\}/', '/\b\d{4}-\d{2}-\d{2}(T[\d:.]+Z?)?\b/', '/\b\d{1,2}:\d{2}(:\d{2})?\b/', '/`[^`]*`/'], ' ', (string) $text);
        preg_match_all('/(?<![\p{L}\p{N}_.\/:#+-])(\d{1,3}(?:,\d{3})+|\d+)(\.\d+)?(\s?(?:%|[KMGTP]i?B\b|[kMG]bit\/s|MB\/s))?(?![\p{L}\p{N}_\/-])/u', (string) $t, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $hit) {
            $int = (int) str_replace(',', '', $hit[1]);
            $plain = !isset($hit[2]) || $hit[2] === '';
            $unit = isset($hit[3]) && $hit[3] !== '';
            if (!$unit && $plain && ($int < 10 || ($int >= 1900 && $int <= 2099 && strlen($hit[1]) === 4))) {
                continue;
            }
            $out[] = trim($hit[0]);
        }
        return $out;
    }

    private static function cut($s, $n)
    {
        return preg_match('/^.{0,' . (int) $n . '}/us', (string) $s, $m) ? $m[0] : substr((string) $s, 0, $n);
    }

    public static function hasContent(array $b)
    {
        if ($b['type'] === 'prose' || $b['type'] === 'callout') {
            return $b['text'] !== '' || ($b['type'] === 'callout' && $b['title'] !== '');
        }
        if ($b['type'] === 'list' || $b['type'] === 'kpis') {
            return (bool) $b['items'];
        }
        if ($b['type'] === 'table') {
            return (bool) $b['rows'];
        }
        return ReportProvenance::chartPoints($b) > 0 || isset($b['value']['value']);
    }
}
