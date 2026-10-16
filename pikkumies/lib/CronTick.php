<?php
/**
 * CronTick: `pikkumies cron-tick` runs Hermes' own per-minute job scheduler (`hermes cron tick`)
 * once. An unattended, no-human-watching call must never gain more privilege than the account
 * already has, so it always runs under `setpriv --no-new-privs` when that binary exists: a
 * container test this session showed plain `sudo` works for a sudo-group account, and the same
 * `sudo` (direct, and via `bash -c`) is refused once wrapped in `setpriv --no-new-privs` (ADR-0025).
 * When setpriv itself is missing: a root-available host refuses outright rather than run
 * unprotected; a host with no root/sudo to begin with has nothing to drop, so it runs the bare
 * command.
 */
class CronTick
{
    /** Test seam: a setpriv presence other than this host's real PATH. */
    public static $setprivPresentOverride = null;

    /** True when a `setpriv` binary exists on PATH. Only checked for existence; never executed to check (same pattern as Detect::suPresent()). */
    public static function setprivPresent()
    {
        if (self::$setprivPresentOverride !== null) {
            return self::$setprivPresentOverride;
        }
        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file("$dir/setpriv") && is_executable("$dir/setpriv")) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when a tick would refuse outright: root is available but there is no setpriv to run it
     * under. Shared by the tick itself and Update's install-time decision, so a line that would
     * only ever refuse is never even scheduled (ADR-0025).
     */
    public static function refuses($rootAvailable)
    {
        // $rootAvailable comes from the cached detection (ADR-0023), which can be stale (sudo granted
        // after detection) or edited by the user; a safety decision must not rest on it alone
        // (ADR-0004 addendum). So also ask the OS, cheaply and without calling sudo (no log noise
        // every minute): uid 0, or membership of a sudo group, means this account might escalate.
        return !self::setprivPresent() && ((bool) $rootAvailable || self::mightEscalate());
    }

    /** Test seam: a uid-0/sudo-group answer other than this process's real one. */
    public static $mightEscalateOverride = null;

    /** True when the OS says this account is root or in sudo/wheel/admin (fails closed: membership is enough). */
    public static function mightEscalate()
    {
        if (self::$mightEscalateOverride !== null) {
            return self::$mightEscalateOverride;
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) trim((string) shell_exec('id -u'));
        return $uid === 0 || Detect::inSudoGroup();
    }

    /**
     * argv for one tick, ready for exec()/escapeshellarg-per-element: `setpriv --no-new-privs
     * <hermesBin> cron tick` when setpriv exists, else the bare argv (nothing to drop without
     * setpriv AND without root/sudo already). [] when refuses($rootAvailable) is true; the caller
     * must never exec an empty command.
     */
    public static function argv($hermesBin, $rootAvailable)
    {
        return self::wrap([$hermesBin, 'cron', 'tick'], $rootAvailable);
    }

    /**
     * Any argv run under the same rule: prefixed with `setpriv --no-new-privs` when setpriv exists,
     * bare when there is nothing to drop, [] when refuses($rootAvailable). Also used for
     * `pikkumies run update`, whose upstream updater may otherwise call sudo apt-get (ADR-0033).
     */
    public static function wrap(array $cmd, $rootAvailable)
    {
        if (self::refuses($rootAvailable)) {
            return [];
        }
        return self::setprivPresent() ? array_merge(['setpriv', '--no-new-privs'], $cmd) : $cmd;
    }

    /**
     * Append one line to $logFile the first time a refusal is seen. $logFile is the same
     * local/update.log the daily auto-update line truncates on every run (Update::cronLine), so
     * this is "once per day" in practice, not "once forever" — and that is fine: the condition
     * (root available, no setpriv) does not change minute to minute.
     */
    public static function logRefusalOnce($logFile)
    {
        $marker = '[cron-tick] refused: root available, no setpriv';
        $existing = (string) @file_get_contents($logFile);
        if (strpos($existing, $marker) !== false) {
            return;
        }
        @mkdir(dirname($logFile), 0700, true);
        @file_put_contents($logFile, gmdate('c') . ' ' . $marker . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Append one line to $logFile only when this tick's outcome differs from the last recorded one
     * (exit code, or whether there was any output at all — Hermes only prints when jobs ran or
     * something went wrong; an idle tick with zero due jobs is silent, per
     * cron/scheduler_tick.py's own "No jobs due" idle path, which logs through Python logging, not
     * stdout). $stateFile persists {code, hadOutput} between ticks — a different process every
     * minute — so a repeated idle tick never writes a line, and a tick that keeps failing the same
     * way logs once, not every minute.
     */
    public static function logIfChanged($logFile, $stateFile, $code, $output, $now = null)
    {
        $hadOutput = trim((string) $output) !== '';
        $state = ['code' => (int) $code, 'hadOutput' => $hadOutput];
        $prev = json_decode((string) @file_get_contents($stateFile), true);
        $changed = !is_array($prev) || !isset($prev['code'], $prev['hadOutput'])
            || $prev['code'] !== $state['code'] || $prev['hadOutput'] !== $state['hadOutput'];
        if ($changed || $hadOutput) {
            @mkdir(dirname($logFile), 0700, true);
            $line = ($now !== null ? $now : gmdate('c')) . ' [cron-tick] exit ' . $state['code']
                . ($hadOutput ? ': ' . self::firstLine($output) : ' (no jobs due)') . "\n";
            @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        }
        @mkdir(dirname($stateFile), 0700, true);
        @file_put_contents($stateFile, json_encode($state), LOCK_EX);
    }

    private static function firstLine($text)
    {
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $text));
        return $lines ? $lines[0] : '';
    }
}
