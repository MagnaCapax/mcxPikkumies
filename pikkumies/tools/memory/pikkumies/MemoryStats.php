<?php
/**
 * MemoryStats: the memory unit's `stats` verb and the `pikkumies doctor` memory line (ADR-0041).
 * Search counts come from the metrics (written only with privacy.perfLogging on); lessons on disk
 * and documentation pages are counted from the files themselves. Two kinds of search are counted:
 * "found nothing" (no hit in any tier) and "found no lesson" (no tier-1 hit), the second with how
 * many were followed by at least one save within MemoryMetrics::SAVE_WINDOW (each search once).
 */
require_once __DIR__ . '/MemoryMetrics.php';

class MemoryStats
{
    const MISSES_SHOWN = 10;
    /** ADR-0041, Consequences: what found_no_lesson_then_saved can and cannot say. */
    const UPPER_BOUND = 'found_no_lesson_then_saved is an upper bound: a save within 2 hours is not proof that it answers that search, and several searches before one save all count';

    /** One record: the last $days days' searches and saves, lessons on disk, docs pages. Prunes old metrics first. */
    public static function compute($days, $now = null)
    {
        $now = $now === null ? time() : $now;
        $days = max(1, (int) $days);
        $since = $now - $days * 86400;
        $config = Config::load();
        MetricsLog::prune(DocSources::metricsDir(), isset($config['sessions']['retentionDays']) ? $config['sessions']['retentionDays'] : 0, $now);
        $saves = array_map(function ($r) {
            return (int) $r['ts'];
        }, MemoryMetrics::read('saves', $since));
        sort($saves);
        $c = ['searches' => 0, 'hit' => 0, 'lesson' => 0, 'nothing' => 0, 'noLesson' => 0, 'thenSaved' => 0];
        $last = [];
        MemoryMetrics::each('searches', $since, function ($s) use (&$c, &$last, $saves) {
            $total = array_sum(array_map('intval', isset($s['hits']) && is_array($s['hits']) ? $s['hits'] : []));
            $tierOne = MemoryMetrics::tierOneHits($s);
            $c['searches']++;
            $c['hit'] += $total > 0 ? 1 : 0;
            $c['lesson'] += $tierOne > 0 ? 1 : 0;
            if ($total === 0) {
                $c['nothing']++;
                $last[] = isset($s['words']) ? (string) $s['words'] : '';
                $last = array_slice($last, -self::MISSES_SHOWN);
            }
            if ($tierOne === 0) {
                $c['noLesson']++;
                $c['thenSaved'] += MemoryMetrics::savedWithin($saves, (int) $s['ts']) ? 1 : 0;
            }
        });
        list($byKind, $byTopic) = MemoryMetrics::onDisk();
        $docs = [];
        foreach (array_keys(DocSources::load()) as $name) {
            $m = DocSources::meta($name);
            if (!empty($m['count'])) {
                $docs[$name] = (int) $m['count'];
            }
        }
        $n = $c['searches'];
        return [
            'days' => $days,
            'metrics' => !empty($config['privacy']['perfLogging']) ? 'on' : 'off',
            'searches' => $n,
            'with_hit' => $c['hit'],
            'with_hit_pct' => self::pct($c['hit'], $n),
            'with_lesson_hit' => $c['lesson'],
            'with_lesson_hit_pct' => self::pct($c['lesson'], $n),
            'found_nothing' => $c['nothing'],
            'last_found_nothing' => array_reverse($last),
            'found_no_lesson' => $c['noLesson'],
            'found_no_lesson_then_saved' => $c['thenSaved'],
            'note' => self::UPPER_BOUND,
            'lessons_on_disk' => array_sum($byKind),
            'by_kind' => $byKind,
            'by_topic' => $byTopic,
            'saved' => count($saves),
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
            $out .= $row('found nothing', $s['found_nothing'], self::pct($s['found_nothing'], $s['searches']));
            $out .= $row('found no lesson', $s['found_no_lesson'], self::pct($s['found_no_lesson'], $s['searches']));
            $out .= $row('  then saved in 2 h', $s['found_no_lesson_then_saved'], self::pct($s['found_no_lesson_then_saved'], $s['found_no_lesson']));
            $out .= "(\"then saved\" is an upper bound: a save in the 2 hours is not proof that it answers that search)\n";
        }
        $out .= $row('memory files saved', $s['saved']);
        foreach ($s['by_kind'] as $kind => $count) {
            $out .= $row("on disk: $kind", $count);
        }
        foreach ($s['by_topic'] as $topic => $count) {
            $out .= $row("  topic $topic", $count);
        }
        foreach ($s['docs_pages'] as $name => $count) {
            $out .= $row("docs: $name", $count);
        }
        if ($s['last_found_nothing']) {
            $out .= "\nLast searches that found nothing (newest first):\n";
            foreach ($s['last_found_nothing'] as $words) {
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

    /** `memory: N lessons · M searches in 30 days · P% found something · K found nothing · L found no lesson, S then saved`; no search part without metrics. */
    public static function doctorLine()
    {
        $s = self::compute(30);
        $line = 'memory:   ' . $s['lessons_on_disk'] . ' lessons';
        if ($s['metrics'] === 'on' && MemoryMetrics::hasSearchMetrics()) {
            $line .= ' · ' . $s['searches'] . ' searches in 30 days';
            if ($s['searches'] > 0) {
                $line .= ' · ' . $s['with_hit_pct'] . '% found something · ' . $s['found_nothing'] . ' found nothing · '
                    . $s['found_no_lesson'] . ' found no lesson, ' . $s['found_no_lesson_then_saved'] . ' then saved';
            }
        }
        return $line;
    }
}
