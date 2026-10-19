<?php
/**
 * HookAsyncStatus: `pikkumies doctor`'s line about the interval hook units (ADR-0067): each unit's interval as set, and
 * from the hook log (event "async") over the last day its runs, skips, errors, timeouts, holds, texts shown, and texts
 * pruned before they were shown. Nothing when no interval unit ships.
 */
require_once __DIR__ . '/HookInterval.php';
require_once __DIR__ . '/Config.php';

class HookAsyncStatus
{
    const COUNTED = ['async-ran' => 'runs', 'async-skip' => 'skips', 'async-error' => 'errors', 'async-bad-output' => 'errors',
        'async-start-failed' => 'errors', 'async-timeout' => 'timeouts', 'held' => 'holds', 'context-shown' => 'shown',
        'notice-shown' => 'shown', 'pruned-undelivered' => 'pruned unshown'];

    public static function doctorLine(array $config, $hooksDir = null, $logDir = null)
    {
        $units = HookInterval::units($hooksDir);
        if (!$units) {
            return '';
        }
        $counts = self::counts($logDir !== null ? $logDir : Config::localDir() . '/logs/hooks', time() - 86400);
        $parts = [];
        foreach ($units as $u) {
            $id = HookInterval::id($u);
            $seen = [];
            foreach (isset($counts[$id]) ? $counts[$id] : [] as $what => $n) {
                $seen[] = "$n $what";
            }
            $parts[] = "$id " . HookInterval::describe($u, $config) . ($seen ? ' (last day: ' . implode(', ', $seen) . ')' : '');
        }
        return 'background: ' . (!empty($config['hooks']['async']['disabled']) ? 'off (hooks.async.disabled); ' : '') . implode('; ', $parts);
    }

    /** [unit => [what => count]] from the async lines of the hook log at or after $since. */
    public static function counts($logDir, $since)
    {
        $out = [];
        foreach ([gmdate('Ymd', $since), gmdate('Ymd')] as $day) {
            $file = "$logDir/$day.jsonl";
            foreach (is_file($file) ? (array) file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
                $d = json_decode($line, true);
                if (!is_array($d) || !isset($d['event'], $d['unit'], $d['outcome'], $d['ts']) || $d['event'] !== 'async'
                    || !isset(self::COUNTED[$d['outcome']]) || strtotime($d['ts']) < $since) {
                    continue;
                }
                $what = self::COUNTED[$d['outcome']];
                $out[$d['unit']][$what] = (isset($out[$d['unit']][$what]) ? $out[$d['unit']][$what] : 0) + 1;
            }
            if ($day === gmdate('Ymd')) {
                break; // since was today: one file
            }
        }
        return $out;
    }
}
