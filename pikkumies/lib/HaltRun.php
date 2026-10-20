<?php
/**
 * HaltRun: the halt guard's one-shot path (ADR-0047). `pikkumies <free text>` becomes `hermes chat
 * --query=...`, which answers and exits when stdin or stdout is not a terminal (Hermes' own
 * behaviour, ADR-0022); `pre_verify` only fires inside a turn that edited a file (agent/turn_stop_gates.py,
 * Hermes v2026.9.24), so a one-shot run with no file edit never reaches it. This wrapper runs the
 * same HaltJudge itself once the process has exited, from the snapshot hooks/halt/halt-snapshot/
 * wrote at pre_llm_call and the command log (ADR-0044) as evidence, and skips judging whenever the
 * live hook already judged that turn (HaltState::alreadyJudgedThisTurn). Interactive sessions never
 * call this: bin/pikkumies only reaches it for a seeded prompt without a TTY on stdin or stdout.
 * Every Hermes run here carries PIKKUMIES_ONESHOT=1 (HookAsync::ONESHOT_ENV): the interval hook units' records are still
 * kept, but post_llm_call starts no background run, which could never be shown and would take the run a check made at
 * the end of the judged turn needs (ADR-0067).
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/HaltState.php';
require_once __DIR__ . '/HaltJudge.php';
require_once __DIR__ . '/HookAsync.php';
require_once __DIR__ . '/Antipatterns.php';

class HaltRun
{
    const MAX_CONTINUES = 3;         // mirrors Hermes' own agent.max_verify_nudges default (ADR-0047 verified facts)
    const CAPTURE_MAX = 40000;       // bytes of printed output kept as the judged "final answer"
    /** Printed when Hermes could not be started at all. Not a claimed cause: a process or memory limit fails the same way. */
    const START_FAILED = 'Could not start Hermes. If the prompt is long (over about 128 KB), it does not fit on a command line:'
        . " put it in a file, `pikkumies --prompt-file FILE`, or send it on stdin with `pikkumies --prompt-file -`.\n";

    /** Runs $hermesBin with $passArgs (['chat', '--query=...']), judging and resuming as needed; returns the exit code. */
    public static function run($hermesBin, array $passArgs)
    {
        list($code, $output, $sessionId) = self::exec($hermesBin, $passArgs);
        if ($sessionId === '') {
            return $code; // no session id in the output (plain text answer): nothing to judge against
        }
        $resumeTail = self::formatArgs($passArgs); // a stream-json caller keeps getting stream-json on the resumes
        $continuesLeft = self::MAX_CONTINUES;
        $wrappedUp = false;
        while (true) {
            if (HaltState::alreadyJudgedThisTurn($sessionId)) {
                return $code; // the live pre_verify hook already judged this turn
            }
            $state = HaltState::load($sessionId);
            if ($state === null) {
                return $code; // no snapshot for this session: pre_llm_call never ran
            }
            $evidence = ['commands' => HaltState::commandsSince(Config::localDir() . '/logs/commands', $sessionId, (int) $state['turn_started']), 'changedPaths' => []];
            $verdict = HaltJudge::evaluate((string) $state['user_message'], (string) $state['tail'], $evidence, $output);
            // Antipatterns (ADR-0088): a continue/wrap-up message gets the matching lines appended (no extra turn);
            // a verdict with no message of its own gets the run's one wrap-up slot instead ($wrappedUp below).
            $applied = Antipatterns::apply($verdict, Antipatterns::forTurn($sessionId, $output, $evidence['commands'], 'oneshot'));
            $verdict['message'] = $applied['message'];
            HaltState::logDecision($sessionId, 'oneshot', 0, $verdict);
            if ($verdict['verdict'] === 'continue' && $continuesLeft > 0) {
                $continuesLeft--;
                list($code, $output) = self::exec($hermesBin, array_merge(['chat', '--resume=' . $sessionId, '--query=' . $verdict['message']], $resumeTail));
                continue;
            }
            if ($verdict['verdict'] === 'done-wrapup' && !$wrappedUp) {
                $wrappedUp = true;
                if (self::detachWrapUp($hermesBin, array_merge(['chat', '--resume=' . $sessionId, '--query=' . $verdict['message']], $resumeTail), $sessionId)) {
                    return $code; // unattended: the answer is out; the wrap-up runs on its own (ADR-0103)
                }
                list($code, $output) = self::exec($hermesBin, array_merge(['chat', '--resume=' . $sessionId, '--query=' . $verdict['message']], $resumeTail));
                continue; // the wrap-up turn may itself edit a file and get judged live; check again before acting further
            }
            if ($applied['resume'] !== null && !$wrappedUp) {
                $wrappedUp = true;
                if (self::detachWrapUp($hermesBin, array_merge(['chat', '--resume=' . $sessionId, '--query=' . $applied['resume']], $resumeTail), $sessionId)) {
                    return $code;
                }
                list($code, $output) = self::exec($hermesBin, array_merge(['chat', '--resume=' . $sessionId, '--query=' . $applied['resume']], $resumeTail));
                continue; // same single wrap-up slot as done-wrapup above: ADR-0047's bound stays 3 continues + 1 final resume
            }
            return $code;
        }
    }

    const WRAPUP_DETACH_ENV = 'PIKKUMIES_WRAPUP_DETACH'; // set to 1 by an unattended caller (a support job) that reads the answer and goes
    const WRAPUP_DETACH_SECONDS = 300;                  // the detached wrap-up's own bound: no caller watches it

    /**
     * With PIKKUMIES_WRAPUP_DETACH=1, the run's one wrap-up turn (save a lesson, one idea: HaltJudge::WRAPUP) starts in its
     * own session under `timeout`, its output to a 0600 log, and the run returns at once: the caller has the answer
     * without waiting for the memory save (ADR-0103). Nothing is skipped: the same resume runs, with the same bound of 3
     * continues + 1 wrap-up. Returns false (the caller runs it inline, as without the variable) when the variable is not
     * set, setsid or timeout is missing, or the start fails.
     */
    public static function detachWrapUp($hermesBin, array $argv, $sessionId)
    {
        if (getenv(self::WRAPUP_DETACH_ENV) !== '1') {
            return false;
        }
        $setsid = trim((string) @shell_exec('command -v setsid 2>/dev/null'));
        $timeout = trim((string) @shell_exec('command -v timeout 2>/dev/null'));
        $dir = Config::localDir() . '/logs/halt-guard';
        if ($setsid === '' || $timeout === '' || (!is_dir($dir) && !@mkdir($dir, 0700, true))) {
            return false;
        }
        $log = $dir . '/wrapup-' . sha1((string) $sessionId) . '.log';
        $cmd = escapeshellarg($setsid) . ' ' . escapeshellarg($timeout) . ' -k 10 ' . self::WRAPUP_DETACH_SECONDS . ' ' . escapeshellarg($hermesBin);
        foreach ($argv as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $env = getenv();
        $env[HookAsync::ONESHOT_ENV] = '1';
        unset($env[self::WRAPUP_DETACH_ENV]);
        $old = umask(0077);
        $p = @proc_open($cmd . ' &', [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $env);
        umask($old);
        if (!is_resource($p) || proc_close($p) !== 0) {
            return false;
        }
        HaltState::logDecision($sessionId, 'oneshot', 0, ['verdict' => 'wrapup-detached']);
        return true;
    }

    /**
     * One Hermes run: streams stdout to the user as it arrives (unchanged from today) while also
     * keeping the last CAPTURE_MAX bytes of it. The arguments go to Hermes unchanged: Hermes'
     * --usage-file is a top-level, one-shot-only flag, and after `chat` it is an unrecognised argument
     * that stops the run (Hermes v0.22.2 hermes_cli/_parser.py). The session id comes from the
     * stream-json `init`/`result` event when the caller asked for `--format=stream-json`.
     * Returns [exit code, captured output, session id ('' when none was printed or it is not valid)].
     */
    private static function exec($hermesBin, array $argv)
    {
        $cmd = 'exec ' . escapeshellarg($hermesBin);
        foreach ($argv as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $env = getenv();
        $env[HookAsync::ONESHOT_ENV] = '1';
        $p = @proc_open($cmd, [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $pipes, null, $env);
        $output = '';
        $code = 1;
        $started = is_resource($p);
        if ($started) {
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk === false || $chunk === '') {
                    if (feof($pipes[1])) {
                        break;
                    }
                    continue;
                }
                fwrite(STDOUT, $chunk);
                $output .= $chunk;
                if (strlen($output) > self::CAPTURE_MAX * 2) {
                    $output = substr($output, -self::CAPTURE_MAX); // bound memory while a long answer is still streaming
                }
            }
            fclose($pipes[1]);
            $code = proc_close($p);
        }
        // Not started: proc_open fails (PHP 8.3+, posix_spawn), or the forked child cannot exec sh and exits 127 silently (older PHP).
        if (!$started || ($code === 127 && $output === '')) {
            fwrite(STDERR, self::START_FAILED);
        }
        $output = strlen($output) > self::CAPTURE_MAX ? substr($output, -self::CAPTURE_MAX) : $output;
        return [$code, $output, self::streamSessionId($output)];
    }

    /** `hermes chat` options that take the next word as their value (Hermes v0.22.2 hermes_cli/_parser.py): that word is never read as an option. */
    const VALUE_OPTIONS = ['-q', '--query', '--query-file', '-m', '--model', '-t', '--toolsets', '--reasoning', '-s', '--skills',
        '--provider', '-r', '--resume', '--in', '--image', '--max-turns', '--run-budget', '--source'];

    /**
     * The caller's format for the resumes, as ['--format=<value>'], or [] when none was given. Read the way Hermes'
     * argparse reads it: `--format x`, `--format=x`, or an abbreviation down to `--f` (no other chat option starts
     * with f); the word after an option that takes a value is skipped, never read as the format. A call argparse would
     * reject (`-q --format`, which it reads as a missing query) never prints a session id, so it is never resumed.
     */
    public static function formatArgs(array $passArgs)
    {
        $format = [];
        for ($i = 0, $n = count($passArgs); $i < $n; $i++) {
            $a = (string) $passArgs[$i];
            if (preg_match('/^--f(o(r(m(at?)?)?)?)?(=(.*))?$/s', $a, $m)) {
                if (isset($m[5]) && $m[5] !== '') { // the =value form
                    $format = ['--format=' . $m[6]];
                } elseif ($i + 1 < $n) {
                    $format = ['--format=' . $passArgs[++$i]];
                }
            } elseif (in_array($a, self::VALUE_OPTIONS, true)) {
                $i++; // its value, whatever it looks like
            }
        }
        return $format; // the last one wins, as in argparse
    }

    /** The session id from the last stream-json event that carries one; '' for plain text output. */
    public static function streamSessionId($output)
    {
        $lines = preg_split('/\r?\n/', (string) $output);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $event = json_decode($line, true);
            if (is_array($event) && isset($event['session_id']) && is_string($event['session_id'])
                && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $event['session_id'])) {
                return $event['session_id'];
            }
        }
        return '';
    }
}
