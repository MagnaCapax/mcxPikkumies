<?php
/**
 * Coverage: what the question suite can see of the documentation a question searched and read (ADR-0039, amended
 * 2026-10-19). The stream truncates a search's output (about 5.5 KB, tier 3 printed last), so which sources a search
 * hit comes from the search's own metrics line (ADR-0041: hits per tier and per source name, pages per tier, never
 * page text). It relies on the shipped default privacy.perfLogging on; the suite does not force it, because a key the
 * overlay forces is one pikkumies-configure refuses to change, and a question may ask to change it. After each question
 * the runner copies that question's lines into search-log/<target>/<id>.jsonl, which --regrade reads again. The `found`
 * check and the report columns read them.
 */
require_once __DIR__ . '/CommandLogCheck.php';

class Coverage
{
    /** A `found` value: a source name, or tier:1..3. */
    const FOUND = '/^([a-z0-9][a-z0-9-]*|tier:[123])$/';

    /** The memory metrics directory of a sandbox root. */
    public static function metricsDir($root)
    {
        return "$root/checkout/pikkumies/local/state/memory-metrics";
    }

    /** The searches lines added since $counts (CommandLogCheck::counts of the metrics directory), decoded. */
    public static function newSearches($dir, array $counts)
    {
        $out = [];
        foreach (CommandLogCheck::newLinesByDay($dir, $counts) as $file => $lines) {
            foreach (strpos($file, 'searches-') === 0 ? $lines : [] as $line) {
                $j = json_decode($line, true);
                if (is_array($j)) {
                    $out[] = $j;
                }
            }
        }
        return $out;
    }

    /** The search records an earlier run saved for one question ([] when none). */
    public static function readLog($file)
    {
        $out = [];
        foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            $j = json_decode($line, true);
            if (is_array($j)) {
                $out[] = $j;
            }
        }
        return $out;
    }

    /** Did some memory search of the record return a hit from $want (a source name or tier:N)? */
    public static function found(array $searches, $want)
    {
        foreach ($searches as $s) {
            if (preg_match('/^tier:([123])$/', $want, $m)) {
                if (isset($s['hits'][$m[1]]) && (int) $s['hits'][$m[1]] > 0) {
                    return true;
                }
            } elseif (isset($s['sources'][$want]['hits']) && (int) $s['sources'][$want]['hits'] > 0) {
                return true;
            }
        }
        return false;
    }

    /** Every source name any search of the record hit, sorted. */
    public static function sourcesHit(array $searches)
    {
        $names = [];
        foreach ($searches as $s) {
            foreach (isset($s['sources']) && is_array($s['sources']) ? $s['sources'] : [] as $name => $v) {
                if (isset($v['hits']) && (int) $v['hits'] > 0) {
                    $names[$name] = true;
                }
            }
        }
        ksort($names);
        return array_keys($names);
    }

    /** Tool calls that opened documentation: any input naming docs-cache/ (terminal included), or the wiki and kb units. */
    public static function pagesOpened(array $tools)
    {
        return count(array_filter($tools, function ($t) {
            $in = json_encode(isset($t['input']) ? $t['input'] : null, JSON_UNESCAPED_SLASHES);
            return strpos($in, 'docs-cache/') !== false || preg_match('#tools/documentation/(wiki|kb)/#', $in);
        }));
    }

    /** The report columns of one question: searches run, sources hit, documentation tool calls. Never a check. */
    public static function columns(array $rec)
    {
        $searches = isset($rec['searches']) ? $rec['searches'] : [];
        return ['searched' => count($searches), 'sources_hit' => self::sourcesHit($searches), 'pages_opened' => self::pagesOpened(isset($rec['tools']) ? $rec['tools'] : [])];
    }

    /** The scorecard line: asked questions tagged docs that opened no page (each passed or failed on its own checks). */
    public static function scorecardLine(array $rows)
    {
        $docs = array_filter($rows, function ($r) {
            return $r['status'] !== 'stale' && in_array('docs', isset($r['tags']) ? $r['tags'] : [], true) && isset($r['pages_opened']);
        });
        $none = array_filter($docs, function ($r) {
            return (int) $r['pages_opened'] === 0;
        });
        return $docs ? sprintf("docs questions that opened no documentation page: %d of %d%s\n", count($none), count($docs),
            $none ? ' (' . implode(', ', array_unique(array_column($none, 'question'))) . ')' : '') : '';
    }
}
