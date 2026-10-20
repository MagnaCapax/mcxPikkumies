<?php
/**
 * ReportModel: the report document, normalized from decoded JSON (the shape is in the unit's USAGE.md and in
 * ReportKit::example()). A wrong SHAPE (no title, a block of an unknown type) throws InvalidArgumentException; a doubtful
 * NUMBER (no source, stale, a sum that does not add up) is never an exception here: ReportLint reports it and the
 * renderer marks it, so the reader sees it.
 *
 * Every number is a figure: {value, unit, source, as_of, label, decimals, change, sum_of}. A bare JSON number is a
 * figure without a source. "fig:<id>" refers to doc.figures.<id>, and so does {fig:<id>} inside prose. Table cells and
 * chart points take their source, as_of and unit from the table or chart.
 */
class ReportModel
{
    const TONES = ['good', 'warn', 'crit', 'info'];
    const BLOCKS = ['prose', 'list', 'callout', 'table', 'chart', 'kpis'];
    const CHARTS = ['line', 'area', 'bar', 'hbar', 'stacked', 'donut', 'sparkline', 'bullet'];
    /** Bytes of one text field (a prose block, a list item, a cell): longer is raw data, not a report. */
    const MAX_TEXT = 65536;

    public static function normalize(array $in, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $title = self::str($in, 'title');
        if ($title === '') {
            throw new InvalidArgumentException('the report has no title');
        }
        $asOf = ReportFormat::ts(isset($in['as_of']) ? $in['as_of'] : null);
        $doc = ['title' => $title, 'subtitle' => self::str($in, 'subtitle'), 'eyebrow' => self::str($in, 'eyebrow'),
            'author' => self::str($in, 'author'), 'footer' => self::str($in, 'footer'), 'disclaimer' => self::str($in, 'disclaimer'),
            'as_of' => $asOf !== null ? $asOf : $now, 'as_of_given' => $asOf !== null, 'generated' => $now,
            'stale_days' => isset($in['stale_days']) && is_numeric($in['stale_days']) ? max(0, (float) $in['stale_days']) : 7.0,
            'verdict' => null, 'figures' => [], 'kpis' => [], 'sections' => [], 'meta' => [], 'unknown' => []];
        foreach ((isset($in['meta']) && is_array($in['meta']) ? $in['meta'] : []) as $k => $v) {
            $doc['meta'][] = [is_int($k) ? '' : (string) $k, is_scalar($v) ? (string) $v : ''];
        }
        if (isset($in['verdict']) && is_array($in['verdict'])) {
            $doc['verdict'] = ['tone' => self::tone($in['verdict']), 'headline' => self::str($in['verdict'], 'headline'),
                'text' => self::str($in['verdict'], 'text')];
        }
        foreach ((isset($in['figures']) && is_array($in['figures']) ? $in['figures'] : []) as $id => $f) {
            if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', (string) $id)) {
                throw new InvalidArgumentException("figure id '$id': use letters, digits, _ . - only");
            }
            $doc['figures'][(string) $id] = self::fig($f, [], []);
            $doc['figures'][(string) $id]['id'] = (string) $id;
        }
        $doc['kpis'] = self::kpis(isset($in['kpis']) ? $in['kpis'] : [], $doc['figures']);
        foreach ((isset($in['sections']) && is_array($in['sections']) ? $in['sections'] : []) as $i => $s) {
            if (!is_array($s) || self::str($s, 'title') === '') {
                throw new InvalidArgumentException('section ' . ($i + 1) . ' has no title');
            }
            $blocks = [];
            foreach ((isset($s['blocks']) && is_array($s['blocks']) ? $s['blocks'] : []) as $j => $b) {
                $blocks[] = self::block($b, $doc['figures'], 'section ' . ($i + 1) . ' block ' . ($j + 1));
            }
            $doc['sections'][] = ['title' => self::str($s, 'title'), 'lead' => self::str($s, 'lead'), 'blocks' => $blocks,
                'break' => !empty($s['page_break'])];
        }
        return $doc;
    }

    /** A figure from a bare number, "fig:<id>" or an object; $defaults supply source/as_of/unit/decimals. */
    public static function fig($x, array $figures, array $defaults)
    {
        if (is_string($x) && strpos($x, 'fig:') === 0) {
            $id = substr($x, 4);
            return isset($figures[$id]) ? $figures[$id] : self::blank($defaults) + ['missing' => $id];
        }
        $f = self::blank($defaults);
        if (is_int($x) || is_float($x)) {
            $f['value'] = $x;
            return $f;
        }
        if (!is_array($x)) {
            return $f + ['invalid' => is_scalar($x) ? (string) $x : gettype($x)];
        }
        $v = isset($x['value']) ? $x['value'] : null;
        if (!is_int($v) && !is_float($v)) {
            $f['invalid'] = is_scalar($v) ? (string) $v : 'no value';
        }
        $f['value'] = is_int($v) || is_float($v) ? $v : null;
        foreach (['unit', 'source', 'label'] as $k) {
            $f[$k] = isset($x[$k]) && is_scalar($x[$k]) ? trim((string) $x[$k]) : $f[$k];
        }
        if (isset($x['as_of'])) {
            $f['as_of'] = ReportFormat::ts($x['as_of']);
        }
        $f['decimals'] = isset($x['decimals']) && is_int($x['decimals']) ? max(0, min(6, $x['decimals'])) : $f['decimals'];
        $f['change'] = !empty($x['change']);
        $f['sum_of'] = isset($x['sum_of']) && is_array($x['sum_of']) ? array_values(array_map('strval', $x['sum_of'])) : [];
        return $f;
    }

    private static function blank(array $d)
    {
        return ['value' => null, 'unit' => isset($d['unit']) ? (string) $d['unit'] : '', 'source' => isset($d['source']) ? (string) $d['source'] : '',
            'as_of' => isset($d['as_of']) ? $d['as_of'] : null, 'label' => '', 'decimals' => isset($d['decimals']) ? $d['decimals'] : null,
            'change' => false, 'sum_of' => [], 'id' => null];
    }

    private static function kpis($list, array $figures)
    {
        $out = [];
        foreach ((is_array($list) ? $list : []) as $k) {
            if (!is_array($k)) {
                continue;
            }
            $label = self::str($k, 'label');
            $value = self::fig(isset($k['value']) ? $k['value'] : null, $figures, []);
            $value['label'] = $value['label'] !== '' ? $value['label'] : $label;
            $of = isset($k['of']) ? self::fig($k['of'], $figures, ['unit' => $value['unit']]) : null;
            if ($of !== null && $of['label'] === '') {
                $of['label'] = $label . ' (limit)';
            }
            $spark = isset($k['spark']) && is_array($k['spark']) ? array_values(array_filter($k['spark'], 'is_numeric')) : [];
            $out[] = ['label' => $label !== '' ? $label : $value['label'], 'value' => $value, 'of' => $of, 'note' => self::str($k, 'note'),
                'tone' => isset($k['tone']) ? self::tone($k) : null, 'spark' => array_map('floatval', $spark)];
        }
        return $out;
    }

    private static function block($b, array $figures, $where)
    {
        $type = is_array($b) && isset($b['type']) ? (string) $b['type'] : '';
        if (!in_array($type, self::BLOCKS, true)) {
            throw new InvalidArgumentException("$where: unknown block type '$type' (one of " . implode(', ', self::BLOCKS) . ')');
        }
        if ($type === 'prose') {
            return ['type' => 'prose', 'text' => self::str($b, 'text')];
        }
        if ($type === 'list') {
            $items = isset($b['items']) && is_array($b['items']) ? array_values(array_map([self::class, 'text'], array_filter($b['items'], 'is_scalar'))) : [];
            return ['type' => 'list', 'items' => $items, 'ordered' => !empty($b['ordered']), 'title' => self::str($b, 'title')];
        }
        if ($type === 'callout') {
            return ['type' => 'callout', 'tone' => self::tone($b), 'title' => self::str($b, 'title'), 'text' => self::str($b, 'text')];
        }
        if ($type === 'kpis') {
            return ['type' => 'kpis', 'items' => self::kpis(isset($b['items']) ? $b['items'] : [], $figures)];
        }
        if ($type === 'table') {
            return ReportModelTable::table($b, $figures, $where);
        }
        return ReportModelTable::chart($b, $figures, $where);
    }

    public static function tone(array $a)
    {
        $t = isset($a['tone']) ? (string) $a['tone'] : 'info';
        return in_array($t, self::TONES, true) ? $t : 'info';
    }

    public static function str($a, $k)
    {
        return is_array($a) && isset($a[$k]) && is_scalar($a[$k]) ? self::text($a[$k]) : '';
    }

    public static function text($v)
    {
        $s = trim((string) $v);
        if (strlen($s) > self::MAX_TEXT) {
            throw new InvalidArgumentException('a text of ' . strlen($s) . ' bytes, at most ' . self::MAX_TEXT . ': summarise first');
        }
        return $s;
    }
}
