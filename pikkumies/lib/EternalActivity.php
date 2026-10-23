<?php
/**
 * EternalActivity: `pikkumies eternal activity [--json]`, what Primary (pm1) is doing now, for a page that shows
 * "Pikkumies is checking: <line>" while it works (ADR-0075 amendment 2026-10-22). Read-only: no lock, no paste, no
 * state file is written. state: off (eternal off or a transfer hold), absent (no Primary window), starting (the window
 * has no session pointer since its start yet), else what the pane's input line shows (EternalAsk::screenStatus): idle,
 * held (unsent text at the input) or busy. line: the newest activity entry of Primary's spool (lib/HookSpool.php), a tool
 * call line or the start of an agent text as lib/GoalReviewWindow.php builds them, never a request the user typed; at
 * most LINE characters, control characters removed; at: that record's time. said / said_at: the newest agent text alone
 * (never a tool call line), cut and cleaned the same way, for a page that must not show commands. The spool is found from the pane's process
 * tree: the Hermes process carries the launcher's run key and depth (lib/HookRunCount.php), else the session pointer.
 * With every interval hook unit off there is no spool, so the line stays empty.
 */
require_once __DIR__ . '/Eternal.php';
require_once __DIR__ . '/EternalAsk.php';
require_once __DIR__ . '/EternalRecord.php';
require_once __DIR__ . '/GoalReviewWindow.php';
require_once __DIR__ . '/HookRunCount.php';
require_once __DIR__ . '/HookSpool.php';
require_once __DIR__ . '/SessionCounters.php';

class EternalActivity
{
    const LINE = 200;
    const DEPTH = 6;      // process levels searched under the pane
    public static $proc = '/proc';

    /** [exit code, output without the final newline] for the account $uid; root is refused like `ask`. */
    public static function run($json, $uid)
    {
        if (EternalAsk::rootRefused($uid)) {
            return [1, 'Activity runs as the account owner, not root.'];
        }
        $a = self::read(Config::localDir());
        if ($json) {
            return [0, json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];
        }
        return [0, 'Primary: ' . $a['state'] . ($a['line'] !== '' ? '; latest: ' . $a['line'] : '')];
    }

    /** ['state', 'line', 'at', 'said', 'said_at'] for Primary. */
    public static function read($localDir)
    {
        $none = ['line' => '', 'at' => 0, 'said' => '', 'said_at' => 0];
        if (is_file($localDir . '/eternals.off') || Eternal::sourceHeld($localDir . '/eternals.hold', time())) {
            return ['state' => 'off'] + $none;
        }
        // No private tmux directory means no Primary; checked first so Eternal::tmux() never has to create it.
        if (!is_dir((Eternal::$tmuxDirectory !== null ? Eternal::$tmuxDirectory : $localDir) . '/tmux')) {
            return ['state' => 'absent'] + $none;
        }
        list($code, $pane) = Eternal::tmux(['display-message', '-p', '-t', '=pikkumies:pm1', '#{pane_start_command}|#{pane_tty}|#{pane_pid}|#{pane_dead}']);
        $p = explode('|', $pane, 4);
        if ($code !== 0 || count($p) !== 4 || !Eternal::paneOwns($p[0], 'pm1') || !ctype_digit($p[2]) || $p[3] === '1') {
            return ['state' => 'absent'] + $none;
        }
        $record = EternalRecord::read('pm1');
        $pointer = $record === null ? '' : Eternal::pointer($p[1], $record['lastStart']);
        $state = 'starting';
        if ($pointer !== '') {
            list($code, $screen) = EternalAsk::screen();
            $status = $code === 0 ? EternalAsk::screenStatus($screen) : -1;
            $state = $status === 0 ? 'idle' : ($status === 3 ? 'held' : ($status === 6 ? 'busy' : 'starting'));
        }
        $hash = self::spool($localDir, (int) $p[2], self::$proc);
        if ($hash === '' && $pointer !== '' && is_file(HookSpool::file($localDir, sha1($pointer)))) {
            $hash = sha1($pointer); // a Primary started without the launcher's run key
        }
        return ['state' => $state] + ($hash === '' ? $none : self::latest(HookSpool::read($localDir, $hash, 0)[0]));
    }

    /**
     * The spool hash of the shallowest agent under process $pid whose spool exists: a process with the launcher's run
     * key in its environment, hashed with its own pid and start time as the hooks it runs hash it. '' when none.
     */
    public static function spool($localDir, $pid, $proc = '/proc')
    {
        $children = [];
        foreach ((array) glob($proc . '/[0-9]*/stat') as $f) {
            $s = HookRunCount::stat($f);
            if ($s !== null && preg_match('#/([0-9]+)/stat$#', $f, $m)) {
                $children[(int) $s[1]][] = [(int) $m[1], $s[19]];
            }
        }
        $best = null;
        $level = [$pid];
        for ($d = 0; $d < self::DEPTH && $level; $d++) {
            $next = [];
            foreach ($level as $parent) {
                foreach (isset($children[$parent]) ? $children[$parent] : [] as $c) {
                    $next[] = $c[0];
                    $env = self::environ("$proc/{$c[0]}/environ");
                    $run = isset($env['PIKKUMIES_RUN']) ? $env['PIKKUMIES_RUN'] : '';
                    if (!preg_match(SessionCounters::RUN_RE, $run)) {
                        continue;
                    }
                    $depth = isset($env['PIKKUMIES_DEPTH']) ? (int) $env['PIKKUMIES_DEPTH'] : 0;
                    $hash = HookRunCount::runHash($run, $depth, $c[0] . ':' . $c[1]);
                    $file = HookSpool::file($localDir, $hash);
                    $at = is_file($file) && !is_link($file) ? (int) filemtime($file) : -1;
                    if ($at >= 0 && ($best === null || $depth < $best[0] || ($depth === $best[0] && $at > $best[1]))) {
                        $best = [$depth, $at, $hash];
                    }
                }
            }
            $level = $next;
        }
        return $best === null ? '' : $best[2];
    }

    /**
     * ['line', 'at'] of the newest activity entry among spool $records, and ['said', 'said_at'] of the newest agent text
     * among them (a tool call line never); never a user record.
     */
    public static function latest(array $records)
    {
        $activity = GoalReviewWindow::read($records)['activity'];
        if (!$activity) {
            return ['line' => '', 'at' => 0, 'said' => '', 'said_at' => 0];
        }
        $at = [];
        foreach ($records as $r) {
            if (is_array($r) && isset($r['seq'], $r['ts']) && is_string($r['ts'])) {
                $at[$r['seq']] = (int) strtotime($r['ts']);
            }
        }
        $last = end($activity);
        $said = null;
        foreach (array_reverse($activity) as $a) {
            if ($a['kind'] === 'A') {
                $said = $a;
                break;
            }
        }
        return ['line' => GoalReviewWindow::line(EternalAsk::clean($last['text']), self::LINE), 'at' => $at[$last['seq']] ?? 0,
            'said' => $said === null ? '' : GoalReviewWindow::line(EternalAsk::clean($said['text']), self::LINE),
            'said_at' => $said === null ? 0 : ($at[$said['seq']] ?? 0)];
    }

    /** NAME => value of a /proc environ file; [] when it cannot be read. */
    private static function environ($file)
    {
        $env = [];
        foreach (explode("\0", (string) @file_get_contents($file)) as $pair) {
            $at = strpos($pair, '=');
            if ($at > 0) {
                $env[substr($pair, 0, $at)] = substr($pair, $at + 1);
            }
        }
        return $env;
    }
}
