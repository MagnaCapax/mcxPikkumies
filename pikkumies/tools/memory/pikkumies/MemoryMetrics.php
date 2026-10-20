<?php
/**
 * MemoryMetrics: what the memory unit knows about its own use (ADR-0041). The writers are
 * DocSources::recordSearch and Lessons::save (through DocSources::appendMetric, only with
 * privacy.perfLogging on); this class reads them back, and answers the save reminder's questions
 * from last-search.json and the memory files, which exist whatever perfLogging says.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class MemoryMetrics
{
    /** Seconds after a search during which a save counts as following it, and the reminder may fire. */
    const SAVE_WINDOW = 7200;

    /** The memory search's last record (time, tiers, hits per tier), or null. */
    public static function lastSearch()
    {
        $rec = json_decode((string) @file_get_contents(DocSources::searchStateDir() . '/last-search.json'), true);
        return is_array($rec) && isset($rec['time'], $rec['hits']) && is_array($rec['hits']) ? $rec : null;
    }

    /** Hits in tier 1 (memory) of a record, or null when the record does not say. */
    public static function tierOneHits(array $rec)
    {
        $hits = isset($rec['hits']) && is_array($rec['hits']) ? $rec['hits'] : [];
        return isset($hits[1]) && is_int($hits[1]) ? $hits[1] : null;
    }

    /** The newest search, when it is under SAVE_WINDOW old and found nothing in tier 1; else null. */
    public static function recentMiss($now = null)
    {
        $now = $now === null ? time() : $now;
        $rec = self::lastSearch();
        if ($rec === null || $now - (int) $rec['time'] >= self::SAVE_WINDOW || $now < (int) $rec['time'] - 60) {
            return null;
        }
        return self::tierOneHits($rec) === 0 ? $rec : null;
    }

    /** Memory markdown files (README.md excluded): lessons, reference and research notes. */
    public static function memoryFiles()
    {
        $out = [];
        foreach (TextSearch::lessonDirs(DocSources::root()) as $dir) {
            foreach ((array) glob($dir . '/*.md') as $f) {
                if (PathName::base($f) !== 'README.md') {
                    $out[] = $f;
                }
            }
        }
        return $out;
    }

    /** The newest modification time of any memory file (a save or an append), 0 when there are none. */
    public static function newestMemoryChange()
    {
        clearstatcache();
        $newest = 0;
        foreach (self::memoryFiles() as $f) {
            $newest = max($newest, (int) @filemtime($f));
        }
        return $newest;
    }

    /** Whether the sorted save times hold one at or after $ts and under SAVE_WINDOW later. */
    public static function savedWithin(array $saves, $ts)
    {
        $lo = 0;
        $hi = count($saves);
        while ($lo < $hi) { // the first save at or after $ts
            $mid = ($lo + $hi) >> 1;
            if ($saves[$mid] < $ts) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo < count($saves) && $saves[$lo] - $ts < self::SAVE_WINDOW;
    }

    /** Memory files by kind, and lessons by topic. */
    public static function onDisk()
    {
        $byKind = ['lesson' => 0, 'reference' => 0, 'research' => 0];
        $byTopic = [];
        foreach (self::memoryFiles() as $f) {
            $dir = PathName::base(dirname($f));
            $top = PathName::base(dirname($f, 2)) === 'memory';
            $kind = $top && in_array($dir, ['reference', 'research'], true) ? $dir : 'lesson';
            $byKind[$kind]++;
            if ($kind === 'lesson') {
                $topic = PathName::base(dirname($f, 2)) === 'lessons' ? $dir : '(no topic)';
                $byTopic[$topic] = (isset($byTopic[$topic]) ? $byTopic[$topic] : 0) + 1;
            }
        }
        ksort($byTopic);
        return [$byKind, $byTopic];
    }

    /** The exact save command, with placeholders for what the agent must fill in. */
    public static function saveCommand()
    {
        return 'php ' . DocSources::root() . '/tools/memory/pikkumies/pikkumies.php save --topic <topic> --title "<what someone would search for>"'
            . ' --sources "<where it came from; another>" --body-file <file>';
    }

    /** Calls $visit with each record of <kind>-YYYYMM.jsonl with ts >= $since, oldest month first, a line at a time. */
    public static function each($kind, $since, callable $visit)
    {
        foreach (MetricsLog::files(DocSources::metricsDir(), $kind) as $f) {
            if (MetricsLog::month($f) >= gmdate('Ym', $since)) { // skip whole months before the period
                MetricsLog::each($f, $since, $visit);
            }
        }
    }

    /** The records each() visits, as a list; for the small files (saves). */
    public static function read($kind, $since)
    {
        $out = [];
        self::each($kind, $since, function ($r) use (&$out) {
            $out[] = $r;
        });
        return $out;
    }

    /** Whether any search metrics were ever written. */
    public static function hasSearchMetrics()
    {
        return (bool) MetricsLog::files(DocSources::metricsDir(), 'searches');
    }
}
