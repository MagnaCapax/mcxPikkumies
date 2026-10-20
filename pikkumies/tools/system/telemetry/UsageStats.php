<?php
/**
 * UsageStats: the `telemetry usage` verb's own record and `--human` view (ADR-0087), over what
 * `hooks/trace/usage-count` has written into TelemetryLog's `usage-tool`, `usage-skill` and
 * `usage-u-<tool>` sources. Kept apart from TelemetryLog itself: this is this one verb's shape
 * (kind/name/verb/calls/ok_pct/last), not a general telemetry reader.
 */
class UsageStats
{
    const KINDS = ['tool', 'skill', 'unit'];

    /**
     * One record per name (tool/skill) or name+verb (unit) with at least one call in the window:
     * kind, name, verb (null for tool/skill), calls, ok_pct (0-100, one decimal), last (UTC, ISO
     * 8601). Sorted by calls, most first. $kind: null for every kind, or one of self::KINDS.
     */
    public static function compute($days, $kind = null, $localDir = null)
    {
        $out = [];
        if ($kind === null || $kind === 'tool') {
            self::addSource($out, 'tool', 'usage-tool', null, $days, $localDir);
        }
        if ($kind === null || $kind === 'skill') {
            self::addSource($out, 'skill', 'usage-skill', null, $days, $localDir);
        }
        if ($kind === null || $kind === 'unit') {
            foreach (TelemetryLog::listSources($localDir) as $s) {
                if (strpos($s['source'], 'usage-u-') === 0) {
                    self::addSource($out, 'unit', $s['source'], substr($s['source'], strlen('usage-u-')), $days, $localDir);
                }
            }
        }
        usort($out, function ($a, $b) {
            return $b['calls'] - $a['calls'];
        });
        return $out;
    }

    /** Appends one record per field TelemetryLog::counts($source) returns; $unitTool: the tool name for a `unit` row, else null (the field itself is the name). */
    private static function addSource(array &$out, $kind, $source, $unitTool, $days, $localDir)
    {
        foreach (TelemetryLog::counts($source, $days, $localDir) as $field => $c) {
            $out[] = ['kind' => $kind, 'name' => $unitTool !== null ? $unitTool : $field, 'verb' => $unitTool !== null ? $field : null,
                'calls' => $c['calls'], 'ok_pct' => $c['calls'] > 0 ? round(100 * $c['ok'] / $c['calls'], 1) : null, 'last' => gmdate('c', $c['last_ts'])];
        }
    }

    /** A 10-cell bar (doctrine/voice.md, Reports; the same shape memory `stats --human` uses). */
    public static function bar($pct)
    {
        $n = max(0, min(10, (int) round(((float) $pct) / 10)));
        return str_repeat('▰', $n) . str_repeat('▱', 10 - $n);
    }

    /** The --human view: off says so and where to turn it on; otherwise the result as a short bar table. */
    public static function human(array $records, $days, $kind, $off)
    {
        if ($off) {
            return "Usage counts are off (privacy.perfLogging); turn it on with the pikkumies-configure skill or `pikkumies install --perf-logging=on`.\n";
        }
        $out = 'Usage, last ' . $days . ' day' . ($days === 1 ? '' : 's') . ($kind !== null ? " ($kind)" : '') . ': ' . count($records) . " entries\n\n";
        if (!$records) {
            return $out . "Nothing counted yet: run a tool, a skill or a unit, then look again.\n";
        }
        foreach ($records as $r) {
            $name = $r['name'] . ($r['verb'] !== null ? ' ' . $r['verb'] : '');
            $pct = $r['ok_pct'];
            $out .= rtrim(sprintf("%-28s %6d  %s", $name, $r['calls'], $pct === null ? '' : self::bar($pct) . " $pct% ok")) . "\n";
        }
        return $out;
    }
}
