<?php
/**
 * HermesUsage: how many model requests a one-shot question made, for `pikkumies --stats` (ADR-0109), read from Hermes'
 * own session store (local/hermes/state.db, table sessions, column api_call_count; Hermes v0.22.2
 * hermes_state_common.py). The stream-json result event carries tokens but no request count. A snapshot is taken
 * before the first Hermes run and the store is read again after the last one has exited (Hermes writes its usage
 * when it exits); the requests are the positive differences summed over the final session's lineage: its parents
 * (a resumed or compressed session keeps a parent link) and its own descendants (helper agents are child sessions);
 * another child of a parent (a sibling, a scheduled job) is not counted.
 * Read only, through SQLite3; null when PHP has no SQLite3 or the store cannot be read, never a guess.
 */
require_once __DIR__ . '/HermesConfig.php';

class HermesUsage
{
    /** [session id => api_call_count] for every session, or null when the store cannot be read. */
    public static function snapshot($database = null)
    {
        $rows = self::rows($database);
        if ($rows === null) {
            return null;
        }
        $out = [];
        foreach ($rows as $id => $r) {
            $out[$id] = $r['calls'];
        }
        return $out;
    }

    /** Requests since $before over $finalId's lineage; null when either read failed or the session is not in the store. */
    public static function requests($before, $finalId, $database = null)
    {
        if (!is_array($before) || !is_string($finalId) || $finalId === '') {
            return null;
        }
        $rows = self::rows($database);
        if ($rows === null || !isset($rows[$finalId])) {
            return null;
        }
        $lineage = [$finalId => true];
        for ($id = $rows[$finalId]['parent']; $id !== '' && isset($rows[$id]) && !isset($lineage[$id]); $id = $rows[$id]['parent']) {
            $lineage[$id] = true;
        }
        $below = [$finalId => true]; // descendants of the final session only: a sibling or a parent's other children are not this run
        do {
            $grew = false;
            foreach ($rows as $id => $r) {
                if (!isset($lineage[$id]) && $r['parent'] !== '' && isset($below[$r['parent']])) {
                    $below[$id] = true;
                    $lineage[$id] = true;
                    $grew = true;
                }
            }
        } while ($grew);
        $sum = 0;
        foreach (array_keys($lineage) as $id) {
            $sum += max(0, $rows[$id]['calls'] - (isset($before[$id]) ? (int) $before[$id] : 0));
        }
        return $sum;
    }

    /** id => ['parent' => id or '', 'calls' => int], or null. */
    private static function rows($database)
    {
        $database = $database !== null ? $database : HermesConfig::home() . '/state.db';
        if (!class_exists('SQLite3') || !is_file($database)) {
            return null;
        }
        try {
            $db = new SQLite3($database, SQLITE3_OPEN_READONLY);
            $db->busyTimeout(2000);
            $result = @$db->query('SELECT id, parent_session_id, api_call_count FROM sessions');
            if (!$result) {
                $db->close();
                return null;
            }
            $rows = [];
            while (($r = $result->fetchArray(SQLITE3_NUM)) !== false) {
                $rows[(string) $r[0]] = ['parent' => (string) $r[1], 'calls' => (int) $r[2]];
            }
            $db->close();
            return $rows;
        } catch (Throwable $x) {
            return null;
        }
    }
}
