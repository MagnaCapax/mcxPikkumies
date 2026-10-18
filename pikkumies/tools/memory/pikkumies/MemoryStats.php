<?php
/**
 * MemoryStats: the memory unit's `stats` verb and the `pikkumies doctor` memory line (ADR-0041).
 * Search counts come from the metrics (written only with privacy.perfLogging on); lessons on disk
 * and documentation pages are counted from the files themselves.
 */
require_once __DIR__ . '/MemoryMetrics.php';

class MemoryStats
{
    const MISSES_SHOWN = 10;

    /** One record: searches, hits and misses in the last $days days, lessons on disk, saves, docs pages. */
    public static function compute($days, $now = null)
    {
        $now = $now === null ? time() : $now;
        $days = max(1, (int) $days);
        $since = $now - $days * 86400;
        $searches = MemoryMetrics::read('searches', $since);
        $anyHit = 0;
        $lessonHit = 0;
        $misses = [];
        foreach ($searches as $s) {
            $hits = isset($s['hits']) && is_array($s['hits']) ? $s['hits'] : [];
            $total = array_sum(array_map('intval', $hits));
            $anyHit += $total > 0 ? 1 : 0;
            $lessonHit += MemoryMetrics::tierOneHits($s) > 0 ? 1 : 0;
            if ($total === 0) {
                $misses[] = isset($s['words']) ? (string) $s['words'] : '';
            }
        }
        $n = count($searches);
        $byKind = ['lesson' => 0, 'reference' => 0, 'research' => 0];
        $byTopic = [];
        foreach (MemoryMetrics::memoryFiles() as $f) {
            $dir = basename(dirname($f));
            $top = basename(dirname($f, 2)) === 'memory';
            $kind = $top && in_array($dir, ['reference', 'research'], true) ? $dir : 'lesson';
            $byKind[$kind]++;
            if ($kind === 'lesson') {
                $topic = basename(dirname($f, 2)) === 'lessons' ? $dir : '(no topic)';
                $byTopic[$topic] = (isset($byTopic[$topic]) ? $byTopic[$topic] : 0) + 1;
            }
        }
        ksort($byTopic);
        $saves = MemoryMetrics::read('saves', $since);
        $docs = [];
        foreach (array_keys(DocSources::load()) as $name) {
            $m = DocSources::meta($name);
            if (!empty($m['count'])) {
                $docs[$name] = (int) $m['count'];
            }
        }
        return [
            'days' => $days,
            'metrics' => !empty(Config::load()['privacy']['perfLogging']) ? 'on' : 'off',
            'searches' => $n,
            'with_hit' => $anyHit,
            'with_hit_pct' => self::pct($anyHit, $n),
            'with_lesson_hit' => $lessonHit,
            'with_lesson_hit_pct' => self::pct($lessonHit, $n),
            'misses' => count($misses),
            'last_misses' => array_slice(array_reverse($misses), 0, self::MISSES_SHOWN),
            'lessons_on_disk' => array_sum($byKind),
            'by_kind' => $byKind,
            'by_topic' => $byTopic,
            'saved' => count($saves),
            'saved_after_miss' => count(array_filter($saves, function ($s) {
                return !empty($s['after_miss']);
            })),
            'docs_pages' => $docs,
        ];
    }

    /** Whole percent, or null when there is nothing to divide by. */
    public static function pct($part, $whole)
    {
        return $whole > 0 ? (int) round(100 * $part / $whole) : null;
    }

    /** A 10-cell bar (doctrine/voice.md, Reports). */
    public static function bar($pct)
    {
        $n = max(0, min(10, (int) round(((int) $pct) / 10)));
        return str_repeat('▰', $n) . str_repeat('▱', 10 - $n);
    }

    /** The --human view: the result first, then a short table with bars. */
    public static function human(array $s)
    {
        $out = "Memory, last {$s['days']} days: {$s['lessons_on_disk']} lessons on disk, {$s['searches']} searches\n";
        if ($s['metrics'] === 'off') {
            $out .= "Search metrics are off (privacy.perfLogging); only lessons and docs are counted.\n";
        }
        $out .= "\n";
        $row = function ($label, $value, $pct = null) {
            return rtrim(sprintf("%-22s %8s  %s", $label, $value, $pct === null ? '' : self::bar($pct) . " $pct%")) . "\n";
        };
        if ($s['searches'] > 0) {
            $out .= $row('found something', $s['with_hit'], $s['with_hit_pct']);
            $out .= $row('found a lesson', $s['with_lesson_hit'], $s['with_lesson_hit_pct']);
            $out .= $row('found nothing', $s['misses'], self::pct($s['misses'], $s['searches']));
        }
        $out .= $row('lessons saved', $s['saved']);
        $out .= $row('  after a miss', $s['saved_after_miss']);
        foreach ($s['by_kind'] as $kind => $count) {
            $out .= $row("on disk: $kind", $count);
        }
        foreach ($s['by_topic'] as $topic => $count) {
            $out .= $row("  topic $topic", $count);
        }
        foreach ($s['docs_pages'] as $name => $count) {
            $out .= $row("docs: $name", $count);
        }
        if ($s['last_misses']) {
            $out .= "\nLast searches that found nothing (newest first):\n";
            foreach ($s['last_misses'] as $words) {
                $out .= '🟡 ' . self::short($words) . "\n";
            }
        }
        return $out;
    }

    /** Searched words on one line, at most 76 characters. */
    public static function short($words)
    {
        $t = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $words));
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($t, 0, 76, '…', 'UTF-8');
        }
        return strlen($t) > 76 ? substr($t, 0, 75) . '…' : $t;
    }

    /** `memory: N lessons · M searches in 30 days · P% found something · K misses`; no search part without metrics. */
    public static function doctorLine()
    {
        $s = self::compute(30);
        $line = 'memory:   ' . $s['lessons_on_disk'] . ' lessons';
        if ($s['metrics'] === 'on' && MemoryMetrics::hasSearchMetrics()) {
            $line .= ' · ' . $s['searches'] . ' searches in 30 days';
            if ($s['searches'] > 0) {
                $line .= ' · ' . $s['with_hit_pct'] . '% found something · ' . $s['misses'] . ' misses';
            }
        }
        return $line;
    }
}
