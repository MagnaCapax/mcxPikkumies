<?php
/**
 * ChangeUnits: whether a call of a Pikkumies tool unit changes data (ADR-0067), from the unit's own USAGE.md verb table
 * (Units::verbs), for lib/ChangeClassify.php.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Units.php';

class ChangeUnits
{
    /**
     * A Pikkumies tool unit's script (tools/<category>/<tool>/<tool>.php): 'read' when its USAGE.md says the verb changes
     * nothing (no, or loads), 'write' for yes, deletes, sends or anything else it says, 'unknown' when the verb is not in
     * the table; null when $script is not a tool unit. USAGE.md is read only for such a path.
     */
    public static function verdict($script, array $rest, $toolsDir)
    {
        if (!is_string($script) || !preg_match('#(?:^|/)tools/((?:[a-z0-9][a-z0-9._-]*/)+)([a-z0-9][a-z0-9_-]*)\.php$#', $script, $m)
            || basename($m[1]) !== $m[2]) {
            return null;
        }
        $usage = rtrim($toolsDir !== null ? $toolsDir : Units::toolsDir(), '/') . '/' . $m[1] . 'USAGE.md';
        $at = self::plainAt($rest);
        $verb = $at < 0 ? '' : $rest[$at];
        if (strpos($m[1], '..') !== false || !is_file($usage)) {
            return 'unknown';
        }
        if ($verb === '') {
            return 'read'; // no verb: the tool prints its usage
        }
        foreach (Units::verbs((string) file_get_contents($usage, false, null, 0, 262144)) as $row) {
            if ($row['verb'] === $verb) {
                return preg_match('/^(no|loads)\b/i', $row['changes']) ? 'read' : 'write';
            }
        }
        return 'unknown';
    }

    /** The index of the first word that is not an option, -1 when there is none. */
    public static function plainAt(array $words)
    {
        foreach ($words as $i => $w) {
            if ($w !== '' && $w[0] !== '-') {
                return $i;
            }
        }
        return -1;
    }
}
