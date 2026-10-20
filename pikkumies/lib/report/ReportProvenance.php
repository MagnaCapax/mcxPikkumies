<?php
/**
 * ReportProvenance: where each number comes from. walk() lists every figure of the document with where it appears and
 * the label a reader knows it by (ReportLint checks the same list); build() numbers the sources in order of first
 * appearance, so the renderer can print a small source number next to each figure, and the appendix can list every
 * figure with its value, source and date. A chart counts as one entry: its points share the chart's source.
 */
class ReportProvenance
{
    /**
     * Every figure: ['fig', 'label', 'where', 'kind' (kpi|limit|cell|figure|chart|bullet|total)], in reading order. A
     * registered figure (doc.figures) is listed once, where it is first used, or at the end when it is never used.
     */
    public static function walk(array $doc)
    {
        $out = [];
        $seen = [];
        $add = function (array $f, $label, $where, $kind) use (&$out, &$seen) {
            if ($f['id'] !== null) {
                if (isset($seen[$f['id']])) {
                    return;
                }
                $seen[$f['id']] = true;
            }
            $out[] = ['fig' => $f, 'label' => $f['label'] !== '' ? $f['label'] : $label, 'where' => $where, 'kind' => $kind];
        };
        $kpis = function (array $list, $where) use ($add, &$refs) {
            foreach ($list as $k) {
                $add($k['value'], $k['label'], $where, 'kpi');
                $refs($k['note'], $where);
                if ($k['of'] !== null) {
                    $add($k['of'], $k['label'] . ' (limit)', $where, 'limit');
                }
            }
        };
        $refs = function ($text, $where) use ($add, $doc) {
            preg_match_all('/\{fig:([A-Za-z0-9_.-]+)\}/', (string) $text, $m);
            foreach ($m[1] as $id) {
                if (isset($doc['figures'][$id])) {
                    $add($doc['figures'][$id], $id, $where, 'figure');
                }
            }
        };
        if ($doc['verdict'] !== null) {
            $refs($doc['verdict']['headline'] . ' ' . $doc['verdict']['text'], 'Verdict');
        }
        $kpis($doc['kpis'], 'Summary');
        foreach ($doc['sections'] as $i => $s) {
            $where = ($i + 1) . '. ' . $s['title'];
            $refs($s['lead'], $where);
            foreach ($s['blocks'] as $b) {
                if ($b['type'] === 'list') {
                    $refs(implode(' ', $b['items']), $where);
                } elseif ($b['type'] === 'kpis') {
                    $kpis($b['items'], $where);
                } elseif ($b['type'] === 'table') {
                    foreach ($b['rows'] as $r => $row) {
                        $rowName = isset($row[0]['text']) && $row[0]['text'] !== '' ? $row[0]['text'] : 'row ' . ($r + 1);
                        foreach ($row as $c => $cell) {
                            if (isset($cell['fig'])) {
                                $col = isset($b['columns'][$c]['label']) && $b['columns'][$c]['label'] !== '' ? $b['columns'][$c]['label'] : 'column ' . ($c + 1);
                                $add($cell['fig'], ($b['caption'] !== '' ? $b['caption'] . ': ' : '') . "$rowName / $col", $where . ($b['caption'] !== '' ? ' › ' . $b['caption'] : ''), 'cell');
                            }
                        }
                    }
                } elseif ($b['type'] === 'chart') {
                    $name = $b['title'] !== '' ? $b['title'] : ucfirst($b['kind']) . ' chart';
                    $pseudo = ['value' => null, 'unit' => $b['unit'], 'source' => $b['source'], 'as_of' => $b['as_of'], 'label' => $name,
                        'decimals' => null, 'change' => false, 'sum_of' => [], 'id' => null, 'chart' => self::chartPoints($b)];
                    $add($pseudo, $name, $where, 'chart');
                    foreach (['value', 'max', 'target', 'total'] as $k) {
                        if (isset($b[$k]) && is_array($b[$k])) {
                            $add($b[$k], $name . ($k === 'value' ? '' : " ($k)"), $where, $k === 'total' ? 'total' : 'bullet');
                        }
                    }
                } elseif ($b['type'] === 'prose' || $b['type'] === 'callout') {
                    $refs($b['text'], $where);
                }
            }
        }
        foreach ($doc['figures'] as $f) {
            $add($f, $f['id'], 'Not shown in the report (declared only)', 'figure');
        }
        return $out;
    }

    /** Number of data points a chart plots. */
    public static function chartPoints(array $b)
    {
        $n = 0;
        foreach (isset($b['series']) ? $b['series'] : [] as $s) {
            $n += isset($s['points']) ? count($s['points']) : count($s['values']);
        }
        return $n + (isset($b['slices']) ? count($b['slices']) : 0) + (isset($b['values']) ? count($b['values']) : 0)
            + (isset($b['value']['value']) ? 1 : 0);
    }

    /** ['sources' => [['n', 'label', 'count', 'latest']], 'index' => [source => n], 'rows' => walk() rows plus 'n']. */
    public static function build(array $doc)
    {
        $index = [];
        $sources = [];
        $rows = [];
        foreach (self::walk($doc) as $row) {
            $src = $row['fig']['source'];
            $n = null;
            if ($src !== '') {
                if (!isset($index[$src])) {
                    $index[$src] = count($index) + 1;
                    $sources[$src] = ['n' => $index[$src], 'label' => $src, 'count' => 0, 'latest' => null];
                }
                $n = $index[$src];
                $sources[$src]['count']++;
                $t = $row['fig']['as_of'];
                $sources[$src]['latest'] = $t !== null && ($sources[$src]['latest'] === null || $t > $sources[$src]['latest']) ? $t : $sources[$src]['latest'];
            }
            $rows[] = $row + ['n' => $n];
        }
        return ['sources' => array_values($sources), 'index' => $index, 'rows' => $rows];
    }
}
