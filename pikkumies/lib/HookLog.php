<?php
/**
 * HookLog: one JSONL line per hook unit run in local/logs/hooks/YYYYMMDD.jsonl (UTC; 7 daily files kept): time, event,
 * unit, milliseconds and outcome, never the payload (ADR-0030). Shared by hooks/orchestrator.php and the interval units'
 * worker (event "async", ADR-0067). Moved unchanged from the orchestrator.
 */
require_once __DIR__ . '/Config.php';

class HookLog
{
    const DAYS = 7;

    /** Append one JSONL line; a new day's file prunes all but the newest self::DAYS files. Never fails the hook. */
    public static function add($event, $unit, $ms, $outcome)
    {
        $dir = Config::localDir() . '/logs/hooks';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return;
        }
        $file = $dir . '/' . gmdate('Ymd') . '.jsonl';
        $new = !is_file($file);
        @file_put_contents($file, json_encode(['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'event' => $event, 'unit' => $unit, 'ms' => $ms, 'outcome' => $outcome], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        if ($new) {
            $files = (array) glob($dir . '/[0-9]*.jsonl');
            sort($files);
            foreach (array_slice($files, 0, max(0, count($files) - self::DAYS)) as $old) {
                @unlink($old);
            }
        }
    }
}
