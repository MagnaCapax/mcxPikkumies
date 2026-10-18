<?php
/**
 * MemoryMetrics: what the memory unit knows about its own use (ADR-0041). The writers are
 * DocSources::recordSearch and Lessons::save (through DocSources::appendMetric, only with
 * privacy.perfLogging on); this class reads them back, and answers the save reminder's questions
 * from last-search.json and the memory files, which exist whatever perfLogging says.
 */
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
                if (basename($f) !== 'README.md') {
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

    /** The exact save command, with placeholders for what the agent must fill in. */
    public static function saveCommand()
    {
        return 'php ' . DocSources::root() . '/tools/memory/pikkumies/pikkumies.php save --topic <topic> --title "<what someone would search for>"'
            . ' --sources "<where it came from; another>" --body-file <file>';
    }

    /** Records of <kind>-YYYYMM.jsonl with ts >= $since, oldest first; unreadable lines are skipped. */
    public static function read($kind, $since)
    {
        $out = [];
        $files = (array) glob(DocSources::metricsDir() . '/' . $kind . '-[0-9][0-9][0-9][0-9][0-9][0-9].jsonl');
        sort($files);
        foreach ($files as $f) {
            if (substr(basename($f, '.jsonl'), -6) < gmdate('Ym', $since)) {
                continue; // a whole month before the period
            }
            foreach (explode("\n", (string) @file_get_contents($f)) as $line) {
                $r = $line === '' ? null : json_decode($line, true);
                if (is_array($r) && isset($r['ts']) && (int) $r['ts'] >= $since) {
                    $out[] = $r;
                }
            }
        }
        return $out;
    }

    /** Whether any search metrics were ever written. */
    public static function hasSearchMetrics()
    {
        return (bool) glob(DocSources::metricsDir() . '/searches-*.jsonl');
    }
}
