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
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/HaltState.php';
require_once __DIR__ . '/HaltJudge.php';

class HaltRun
{
    const MAX_CONTINUES = 3;         // mirrors Hermes' own agent.max_verify_nudges default (ADR-0047 verified facts)
    const CAPTURE_MAX = 40000;       // bytes of printed output kept as the judged "final answer"

    /** Runs $hermesBin with $passArgs (['chat', '--query=...']), judging and resuming as needed; returns the exit code. */
    public static function run($hermesBin, array $passArgs)
    {
        list($code, $output, $sessionId) = self::exec($hermesBin, $passArgs);
        if ($sessionId === '') {
            return $code; // no usable usage file: nothing to judge against
        }
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
            HaltState::logDecision($sessionId, 'oneshot', 0, $verdict);
            if ($verdict['verdict'] === 'continue' && $continuesLeft > 0) {
                $continuesLeft--;
                list($code, $output) = self::exec($hermesBin, ['chat', '--resume=' . $sessionId, '--query=' . $verdict['message']]);
                continue;
            }
            if ($verdict['verdict'] === 'done-wrapup' && !$wrappedUp) {
                $wrappedUp = true;
                list($code, $output) = self::exec($hermesBin, ['chat', '--resume=' . $sessionId, '--query=' . $verdict['message']]);
                continue; // the wrap-up turn may itself edit a file and get judged live; check again before acting further
            }
            return $code;
        }
    }

    /**
     * One Hermes run: streams stdout to the user as it arrives (unchanged from today) while also
     * keeping the last CAPTURE_MAX bytes of it, and reads --usage-file for the session id afterwards.
     * Returns [exit code, captured output, session id ('' when it could not be read or validated)].
     */
    private static function exec($hermesBin, array $argv)
    {
        $usageFile = self::usageFilePath();
        @mkdir(dirname($usageFile), 0700, true);
        @touch($usageFile);
        @chmod($usageFile, 0600);
        $full = array_merge($argv, ['--usage-file=' . $usageFile]);
        $cmd = 'exec ' . escapeshellarg($hermesBin);
        foreach ($full as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $p = @proc_open($cmd, [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        $output = '';
        $code = 1;
        if (is_resource($p)) {
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
        $output = strlen($output) > self::CAPTURE_MAX ? substr($output, -self::CAPTURE_MAX) : $output;
        $usage = json_decode((string) @file_get_contents($usageFile), true);
        @unlink($usageFile);
        $sessionId = is_array($usage) && isset($usage['session_id']) && is_string($usage['session_id'])
            && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $usage['session_id']) ? $usage['session_id'] : '';
        return [$code, $output, $sessionId];
    }

    private static function usageFilePath()
    {
        return Config::localDir() . '/tmp/halt-usage-' . getmypid() . '-' . mt_rand() . '.json';
    }
}
