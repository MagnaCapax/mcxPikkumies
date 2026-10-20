<?php
/**
 * DrivesLesson: when a verdict or a grade lands, a `lesson` record with a title, the sources (this drive's events
 * and SMART document) and a body file written into the drive's own state directory. The agent saves it with the
 * memory tool (`pikkumies.php save --topic hardware --tool <drives.php> ...`); this tool never writes memory itself
 * (ADR-0018), and a screen alone never makes a lesson.
 */
class DrivesLesson
{
    public static function record(DrivesStore $store, $key, array $rec)
    {
        $dir = $store->dir($key);
        $what = $rec['verdict'] !== null ? 'verify ' . $rec['verdict'] : 'grade ' . $rec['grade'];
        $title = 'Drive ' . $key . (!empty($rec['model']) ? ' (' . $rec['model'] . ')' : '') . ", $what";
        $end = $store->last($key, ['verify-end']);
        $lines = ["# $title", '', '- date ' . gmdate('Y-m-d H:i') . ' UTC'];
        foreach (['device', 'bytes', 'protocol', 'role', 'poh', 'health', 'realloc', 'pending', 'uncorrectable', 'reported_unc', 'crc', 'selftest_last',
            'verdict', 'verdict_reason', 'grade', 'grade_reason'] as $k) {
            if (array_key_exists($k, $rec) && $rec[$k] !== null) {
                $lines[] = "- $k = " . (is_bool($rec[$k]) ? ($rec[$k] ? 'yes' : 'no') : (string) $rec[$k]);
            }
        }
        if ($end) {
            $lines[] = '- the latest write pass (' . $end['mode'] . ') ended with ' . (int) $end['read_errors'] . '/' . (int) $end['write_errors'] . '/'
                . (int) $end['compare_errors'] . ' read/write/compare errors; SMART before ' . json_encode($end['pre']) . ', after ' . json_encode($end['post']);
        }
        $lines[] = '';
        $lines[] = 'Counters are a screen; only a completed write pass gives a verdict (tools/hardware/DOCTRINE.md).';
        $body = "$dir/lesson-" . gmdate('Ymd\THis') . '-' . bin2hex(random_bytes(4)) . '.md'; // unique: two lessons can land in one second
        if (@file_put_contents($body, implode("\n", $lines) . "\n") !== false) {
            @chmod($body, 0600);
        }
        $sources = Config::shown("$dir/events.jsonl") . '; ' . Config::shown("$dir/smart.json");
        $memory = dirname(__DIR__, 2) . '/memory/pikkumies/pikkumies.php';
        return ['lesson' => $title, 'serial' => $key, 'body_file' => Config::shown($body), 'sources' => $sources,
            'save' => ToolProc::line(['php', $memory, 'save', '--topic', 'hardware', '--title', $title, '--sources', $sources,
                '--tool', __DIR__ . '/drives.php', '--body-file', $body])];
    }
}
