<?php
/**
 * HookLedger: the per-account daily count of model calls made by background checks (ADR-0071), one file,
 * local/state/async/ledger.json ({"day": "YYYYMMDD", "calls": {"<unit name>": N}, "last": {"<unit name>": time}}, UTC
 * days for the calls; last is kept across days), read and written under LOCK_EX. A caller may also ask for a gap: no
 * spend within that many seconds of the unit's last one, for the whole account (the goal review: 600 s, ADR-0096), so
 * runs killed after spending, or many sessions at once, cannot spend faster than that. spend() reserves all the calls a review will make before it makes the first, so a review never stops half
 * way; every caller of a unit, in the background or at the end of a turn, asks the same ledger. A unit's cap is
 * hooks.dailyCalls.<unit name> when set (by hand in local/config.php), else the default the unit gives.
 * The agent's own account can edit this file: it bounds a bug or a runaway loop, never the agent.
 */
require_once __DIR__ . '/HookSpool.php';

class HookLedger
{
    public static function file($localDir)
    {
        return HookSpool::dir($localDir) . '/ledger.json';
    }

    /** The unit's cap for today: hooks.dailyCalls.<name>, else $default. */
    public static function cap(array $config, $name, $default)
    {
        return isset($config['hooks']['dailyCalls'][$name]) && is_numeric($config['hooks']['dailyCalls'][$name]) ? max(0, (int) $config['hooks']['dailyCalls'][$name]) : $default;
    }

    /**
     * Reserve $n calls of $name's $cap for today: true when they fit (and are now counted), false otherwise; with $gap,
     * also false within $gap seconds of $name's last spend. $why: '' when spent, else 'daily-cap', 'gap' or 'no-ledger'.
     */
    public static function spend($localDir, $name, $cap, $n = 1, $gap = 0, &$why = null)
    {
        $file = self::file($localDir);
        $old = umask(0077);
        $made = is_dir(dirname($file)) || @mkdir(dirname($file), 0700, true) || is_dir(dirname($file));
        $fh = $made && !is_link($file) ? @fopen($file, 'c+') : false;
        umask($old);
        if ($fh === false || !flock($fh, LOCK_EX)) {
            $why = 'no-ledger';
            return false; // no ledger, no call: the cap must hold even when the disk is full
        }
        $d = json_decode((string) stream_get_contents($fh), true);
        $today = gmdate('Ymd');
        $calls = is_array($d) && isset($d['day'], $d['calls']) && $d['day'] === $today && is_array($d['calls']) ? $d['calls'] : [];
        $used = isset($calls[$name]) ? (int) $calls[$name] : 0;
        $last = is_array($d) && isset($d['last']) && is_array($d['last']) ? $d['last'] : [];
        $recent = $gap > 0 && isset($last[$name]) && is_int($last[$name]) && time() - $last[$name] < $gap && $last[$name] <= time();
        $ok = $n > 0 && $used + $n <= $cap && !$recent;
        $why = $recent ? 'gap' : ($ok ? '' : 'daily-cap');
        if ($ok) {
            $calls[$name] = $used + $n;
            $last[$name] = time();
            $json = json_encode(['day' => $today, 'calls' => $calls, 'last' => $last]);
            $ok = ftruncate($fh, 0) && rewind($fh) && fwrite($fh, $json) === strlen($json);
        }
        flock($fh, LOCK_UN);
        fclose($fh);
        return $ok;
    }

    /** Calls $name has made today. */
    public static function used($localDir, $name)
    {
        $d = json_decode((string) @file_get_contents(self::file($localDir)), true);
        return is_array($d) && isset($d['day'], $d['calls'][$name]) && $d['day'] === gmdate('Ymd') ? (int) $d['calls'][$name] : 0;
    }
}
